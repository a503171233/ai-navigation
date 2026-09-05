<?php
/**
 * 对外 API 统一入口
 * 路由: /api/v1/{resource}/{action}
 * 统一处理: 签名鉴权、时间戳/nonce 防重放、限流、日志
 */
require_once dirname(__DIR__) . '/core/Bootstrap.php';

// ---------- 路由解析 ----------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = trim((string)$path, '/');

// 兼容直接访问 api/index.php（如 /api/v1/auth/verify）与别名路径
if (preg_match('#^api/v1/([a-z]+)/([a-z]+)$#i', $path, $m)) {
    $resource = strtolower($m[1]);
    $action = strtolower($m[2]);
} elseif (preg_match('#^v1/([a-z]+)/([a-z]+)$#i', $path, $m)) {
    $resource = strtolower($m[1]);
    $action = strtolower($m[2]);
} else {
    // 兜底: 从查询参数解析
    $resource = strtolower($_GET['r'] ?? '');
    $action = strtolower($_GET['act'] ?? '');
}

$allowedResources = ['auth', 'instance', 'command', 'popup', 'update', 'module', 'skill', 'offline'];
if (!in_array($resource, $allowedResources, true)) {
    DCAI_Response::fail(1001, '接口不存在');
}

$GLOBALS['DCAI_API_RESOURCE'] = $resource;
$GLOBALS['DCAI_API_ACTION'] = $action;

// ---------- 请求体 ----------
$bodyRaw = file_get_contents('php://input');
$bodyRaw = $bodyRaw === false ? '' : $bodyRaw;
$req = json_decode($bodyRaw, true);
if (!is_array($req)) {
    $req = [];
}
$GLOBALS['DCAI_API_BODY'] = $req;

function dcai_api_body(): array
{
    return $GLOBALS['DCAI_API_BODY'] ?? [];
}

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}

// ---------- 防重放 nonce ----------
$nonceDir = (string)dcai_config('storage.path', DCAI_ROOT . '/storage') . '/cache/nonces';
if (!is_dir($nonceDir)) {
    @mkdir($nonceDir, 0755, true);
}

function dcai_nonce_used(string $nonce): bool
{
    global $nonceDir;
    $file = $nonceDir . '/' . hash('sha256', $nonce) . '.tmp';
    if (is_file($file)) {
        return true;
    }
    @file_put_contents($file, (string)time(), LOCK_EX);
    return false;
}

function dcai_cleanup_nonces(int $ttl): void
{
    global $nonceDir;
    // 限频：全局目录扫描最多每 60s 一次，避免高流量下每个请求 O(N) 遍历 nonce 目录
    $stamp = $nonceDir . '/.last_cleanup';
    if (is_file($stamp) && (time() - (int)@file_get_contents($stamp)) < 60) {
        return;
    }
    @file_put_contents($stamp, (string)time(), LOCK_EX);
    foreach (glob($nonceDir . '/*.tmp') ?: [] as $f) {
        if (is_file($f) && (time() - filemtime($f)) > $ttl) {
            @unlink($f);
        }
    }
}

// ---------- 鉴权分派 ----------
// 公开操作（app_secret 签名，无需实例令牌）：离线激活的签发/校验与 verify/register 一样无实例上下文
$isPublicAction = ($resource === 'auth' && $action === 'verify')
    || ($resource === 'instance' && $action === 'register')
    || ($resource === 'update' && $action === 'download')
    || ($resource === 'offline' && ($action === 'request' || $action === 'verify'));

$GLOBALS['DCAI_INSTANCE'] = null;

if (!$isPublicAction) {
    // 已注册阶段：实例令牌签名
    [$authErr, $instance] = DCAI_InstanceService::authenticate($headers, $bodyRaw);
    if ($authErr !== null) {
        if ($authErr === 1003) {
            DCAI_Response::fail(1003, '时间戳过期或 nonce 重复');
        }
        if ($authErr === 2008) {
            DCAI_Response::fail(2008, '实例已被远程禁用');
        }
        DCAI_Response::fail(2100, '实例令牌无效');
    }
    $GLOBALS['DCAI_INSTANCE'] = $instance;

    // nonce 防重放（实例维度）
    $nonce = $headers['x-nonce'] ?? '';
    if ($nonce === '' || dcai_nonce_used($instance['instance_id'] . ':' . $nonce)) {
        DCAI_Response::fail(1003, '时间戳过期或 nonce 重复');
    }
    dcai_cleanup_nonces((int)dcai_config('security.nonce_ttl', 300));

    // 限流（实例维度）
    $apiLimit = (int)dcai_config('security.rate_limit.api', 120);
    $rl = new DCAI_RateLimit();
    if (!$rl->allow('api:' . $instance['id'], $apiLimit)) {
        DCAI_Response::fail(1004, '请求过于频繁');
    }
} elseif ($resource === 'update' && $action === 'download') {
    // 下载接口：签名放在查询参数，由 handler 校验
} else {
    // 未注册阶段：app_secret 签名（auth/verify、instance/register）
    $appSecret = (string)dcai_config('sdk.app_secret', '');
    if ($appSecret === '') {
        DCAI_Response::fail(5000, '服务端未配置 app_secret');
    }
    [$ok, $reason] = DCAI_Signature::verify($headers, $appSecret, $bodyRaw, (int)dcai_config('security.timestamp_max_diff', 300));
    if (!$ok) {
        if (strpos($reason, '时间戳') !== false || strpos($reason, 'nonce') !== false) {
            DCAI_Response::fail(1003, '时间戳过期或 nonce 重复');
        }
        DCAI_Response::fail(1002, '签名校验失败');
    }
    $nonce = $headers['x-nonce'] ?? '';
    $identity = $resource . ':' . ($req['license_key'] ?? '') . ':' . ($req['domain'] ?? '');
    if ($nonce === '' || dcai_nonce_used($identity . ':' . $nonce)) {
        DCAI_Response::fail(1003, '时间戳过期或 nonce 重复');
    }
    dcai_cleanup_nonces((int)dcai_config('security.nonce_ttl', 300));

    // 未注册阶段的公开动作统一按 IP 限流（register/offline 等；verify 走下方专属限流）
    // 说明：app_secret 随客户端 SDK 分发，单凭签名无法区分正常客户机与恶意脚本，
    //       需以 IP 兜底防针对任意授权码/产品的批量探测。
    if ($resource === 'instance' || $resource === 'offline') {
        $apiLimit = (int)dcai_config('security.rate_limit.api', 120);
        $rl = new DCAI_RateLimit();
        if (!$rl->allow('pubip:' . dcai_client_ip(), $apiLimit)) {
            DCAI_Response::fail(1004, '请求过于频繁');
        }
    }

    // verify 限流：IP + 授权码
    if ($resource === 'auth' && $action === 'verify') {
        $verifyLimit = (int)dcai_config('security.rate_limit.verify', 30);
        $rl = new DCAI_RateLimit();
        $key = 'verify:' . dcai_client_ip() . ':' . ($req['license_key'] ?? '');
        if (!$rl->allow($key, $verifyLimit)) {
            DCAI_Response::fail(1004, '请求过于频繁');
        }
    }
}

// ---------- 分发到资源处理器 ----------
$handlerFile = dirname(__DIR__) . '/api/v1/' . $resource . '.php';
if (!is_file($handlerFile)) {
    DCAI_Response::fail(1001, '接口不存在');
}
require $handlerFile;

<?php
/**
 * DCAI 授权系统 全局引导
 * 加载配置、注册自动加载、设置时区与错误处理。
 */

define('DCAI_ROOT', dirname(__DIR__));
define('DCAI_CONFIG_FILE', DCAI_ROOT . '/config/config.php');

if (!is_file(DCAI_CONFIG_FILE)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('系统尚未安装，请运行安装向导 install/index.php');
}

$GLOBALS['DCAI_CONFIG'] = require DCAI_CONFIG_FILE;

// 系统自身版本：优先取 config.php 中 app.version（系统级 OTA 升级后更新），否则默认 1.0.0
define('DCAI_SYSTEM_VERSION', (string)($GLOBALS['DCAI_CONFIG']['app']['version'] ?? '1.1.1'));

date_default_timezone_set($GLOBALS['DCAI_CONFIG']['app']['timezone'] ?? 'Asia/Shanghai');

// 错误处理
$debug = !empty($GLOBALS['DCAI_CONFIG']['app']['debug']);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', ($GLOBALS['DCAI_CONFIG']['log']['file'] ?? DCAI_ROOT . '/storage/logs/app.log'));

if (!$debug) {
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

// ---------- 全局异常/致命错误捕获（兜底容错） ----------
/**
 * 是否 API 上下文（请求路径以 /api/ 开头或来自 CLI 的 api 调用）
 */
function dcai_is_api_request(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/api/') === 0 || strpos($uri, 'api/v1') !== false) {
        return true;
    }
    // CLI 下请求测试/健康检查等 JSON 场景
    return PHP_SAPI === 'cli';
}

/**
 * 记录异常到日志（带请求上下文，便于排障）
 */
function dcai_report_exception(Throwable $e, string $phase = 'global'): void
{
    $ctx = [
        'err'     => $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
        'phase'   => $phase,
        'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri'     => $_SERVER['REQUEST_URI'] ?? '',
        'ip'      => function_exists('dcai_client_ip') ? dcai_client_ip() : '',
    ];
    try {
        dcai_log('error', '未捕获异常', $ctx);
    } catch (Throwable $logErr) {
        // 日志也失败时写入 PHP error_log，避免丢失
        error_log('DCAI uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
}

/**
 * 全局异常处理器：统一 JSON 降级响应（API）或友好错误页（后台），并记录日志
 */
set_exception_handler(function (Throwable $e) {
    dcai_report_exception($e);
    // 回滚未完成事务，避免连接持有脏状态
    try {
        if (function_exists('dcai_db')) {
            $db = dcai_db();
            if ($db->inTransaction()) {
                $db->rollback();
            }
        }
    } catch (Throwable $ignore) {
    }

    if (headers_sent()) {
        return; // 输出已开始，无法再发响应头，仅记录日志
    }

    $debug = !empty($GLOBALS['DCAI_CONFIG']['app']['debug']);
    $message = '服务器内部错误';
    if ($debug) {
        $message .= '：' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    }
    if (dcai_is_api_request()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 5000, 'msg' => $message, 'data' => null], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>系统错误</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#f3f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
            . '.box{background:#fff;border:1px solid #e3e8f0;border-radius:12px;padding:40px;max-width:480px;text-align:center}'
            . 'h1{font-size:18px;color:#c5353a;margin:0 0 10px}p{color:#5b6b7f;font-size:14px;line-height:1.7;margin:0 0 18px}'
            . 'a{display:inline-block;background:#4f6ef7;color:#fff;padding:8px 18px;border-radius:8px;text-decoration:none;font-size:14px}</style>'
            . '</head><body><div class="box"><h1>⚠️ 系统开小差了</h1><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><a href="javascript:history.back()">返回上一步</a></div></body></html>';
    }
    exit;
});

/**
 * 致命错误兜底处理（输出友好响应）
 */
function dcai_render_fatal(): void
{
    if (headers_sent()) {
        return;
    }
    if (dcai_is_api_request()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 5000, 'msg' => '服务器内部错误', 'data' => null], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>系统错误</title></head>'
            . '<body style="font-family:system-ui,sans-serif;background:#f3f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
            . '<div style="background:#fff;border-radius:12px;padding:40px;text-align:center"><h1 style="color:#c5353a;font-size:18px">⚠️ 系统开小差了</h1>'
            . '<p style="color:#5b6b7f;font-size:14px">服务器内部错误，请稍后重试或联系管理员。</p></div></body></html>';
    }
}

/**
 * 致命错误（E_ERROR/E_PARSE 等）兜底：转换为统一响应并记录日志
 */
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (in_array($err['type'], $fatal, true)) {
        dcai_report_exception(new ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']), 'fatal');
        dcai_render_fatal();
        exit;
    }
});

// 简易自动加载: core/ 与 service/ 下与类名一致的 PHP 文件（兼容 *Service.php 命名）
spl_autoload_register(function ($class) {
    if (strpos($class, 'DCAI_') !== 0) {
        return;
    }
    $relative = substr($class, 5) . '.php';
    foreach ([DCAI_ROOT . '/core/', DCAI_ROOT . '/service/'] as $dir) {
        // 尝试 ClassName.php 与 ClassNameService.php（如 DCAI_Store -> Store.php / StoreService.php）
        foreach ([$relative, substr($relative, 0, -4) . 'Service.php'] as $cand) {
            $file = $dir . $cand;
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
});

function dcai_config($key = null, $default = null)
{
    static $merged = null;
    if ($merged === null) {
        $merged = $GLOBALS['DCAI_CONFIG'];
        // settings 表覆盖项：后台"系统设置"写入的键实时生效
        try {
            $rows = dcai_db()->query('SELECT skey, svalue FROM settings WHERE skey LIKE \'%_enabled\' OR skey IN (\'site_name\',\'verify_ttl\',\'heartbeat_threshold\',\'rate_verify\',\'rate_api\',\'notify_webhook\',\'notify_email\',\'notify_enabled\',\'store_enabled\',\'store_epay_gateway\',\'store_epay_pid\',\'store_epay_key\',\'store_manual_account\',\'update_source_enabled\',\'update_source_manifest\',\'update_source_auth_token\',\'update_source_timeout\')');
            $map = [];
            foreach ($rows as $row) {
                $map[$row['skey']] = $row['svalue'];
            }
            if (isset($map['site_name']) && $map['site_name'] !== '') {
                $merged['app']['name'] = $map['site_name'];
            }
            if (isset($map['verify_ttl']) && $map['verify_ttl'] !== '') {
                $merged['security']['verify_ttl'] = (int)$map['verify_ttl'];
            }
            if (isset($map['heartbeat_threshold']) && $map['heartbeat_threshold'] !== '') {
                $merged['security']['heartbeat_threshold'] = (int)$map['heartbeat_threshold'];
            }
            if (isset($map['rate_verify']) && $map['rate_verify'] !== '') {
                $merged['security']['rate_limit']['verify'] = (int)$map['rate_verify'];
            }
            if (isset($map['rate_api']) && $map['rate_api'] !== '') {
                $merged['security']['rate_limit']['api'] = (int)$map['rate_api'];
            }
            if (isset($map['notify_webhook']) && $map['notify_webhook'] !== '') {
                $merged['notify']['webhook'] = $map['notify_webhook'];
            }
            if (isset($map['notify_email']) && $map['notify_email'] !== '') {
                $merged['notify']['email'] = $map['notify_email'];
            }
            if (isset($map['notify_enabled']) && $map['notify_enabled'] !== '') {
                $merged['notify']['enabled'] = (int)$map['notify_enabled'];
            }
            if (isset($map['store_enabled']) && $map['store_enabled'] !== '') {
                $merged['store']['enabled'] = (int)$map['store_enabled'];
            }
            if (isset($map['store_epay_gateway']) && $map['store_epay_gateway'] !== '') {
                $merged['store']['epay_gateway'] = $map['store_epay_gateway'];
            }
            if (isset($map['store_epay_pid']) && $map['store_epay_pid'] !== '') {
                $merged['store']['epay_pid'] = (int)$map['store_epay_pid'];
            }
            if (isset($map['store_epay_key']) && $map['store_epay_key'] !== '') {
                $merged['store']['epay_key'] = $map['store_epay_key'];
            }
            if (isset($map['store_manual_account']) && $map['store_manual_account'] !== '') {
                $merged['store']['manual_account'] = $map['store_manual_account'];
            }
            // 远程升级源配置
            if (isset($map['update_source_enabled']) && $map['update_source_enabled'] !== '') {
                $merged['update_source']['enabled'] = (int)$map['update_source_enabled'];
            }
            if (isset($map['update_source_manifest']) && $map['update_source_manifest'] !== '') {
                $merged['update_source']['manifest'] = $map['update_source_manifest'];
            }
            if (isset($map['update_source_auth_token']) && $map['update_source_auth_token'] !== '') {
                $merged['update_source']['auth_token'] = $map['update_source_auth_token'];
            }
            if (isset($map['update_source_timeout']) && $map['update_source_timeout'] !== '') {
                $merged['update_source']['timeout'] = (int)$map['update_source_timeout'];
            }
        } catch (Throwable $e) {
            // 表不存在或连接异常时静默忽略，回退 config.php
        }
    }
    if ($key === null) {
        return $merged;
    }
    $cursor = $merged;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$segment];
    }
    return $cursor;
}

function dcai_db(): DCAI_Database
{
    return DCAI_Database::instance();
}

function dcai_now(): string
{
    return date('Y-m-d H:i:s');
}

function dcai_log(string $level, string $message, array $context = []): void
{
    DCAI_Logger::instance()->log($level, $message, $context);
}

function dcai_client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        return '0.0.0.0';
    }
    // 仅当来源 IP 命中可信代理时，才允许读取转发头，避免客户端伪造 IP 绕过限流/登录锁定
    if (dcai_is_trusted_proxy($remote)) {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    return $remote;
}

function dcai_is_trusted_proxy(string $ip): bool
{
    $proxies = (array)dcai_config('security.trusted_proxies', []);
    if (!$proxies) {
        return false;
    }
    foreach ($proxies as $proxy) {
        $proxy = trim((string)$proxy);
        if ($proxy === '') {
            continue;
        }
        if (strpos($proxy, '/') !== false) {
            if (dcai_ip_in_cidr($ip, $proxy)) {
                return true;
            }
        } elseif ($ip === $proxy) {
            return true;
        }
    }
    return false;
}

function dcai_ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr), 2, null);
    if ($bits === null || !is_numeric($bits) || (int)$bits < 0 || (int)$bits > 128) {
        return false;
    }
    $bits = (int)$bits;
    $ver = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;
    if ($ver === 4 && strpos($net, ':') !== false) {
        return false;
    }
    if ($ver === 6 && strpos($net, ':') === false) {
        return false;
    }
    if ($bits > ($ver === 4 ? 32 : 128)) {
        return false;
    }
    $ipBin = inet_pton($ip);
    $netBin = inet_pton($net);
    if ($ipBin === false || $netBin === false) {
        return false;
    }
    if ($ver === 4) {
        $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
        return (unpack('N', $ipBin)[1] & $mask) === (unpack('N', $netBin)[1] & $mask);
    }
    $mask = str_repeat("\xFF", intdiv($bits, 8));
    if ($bits % 8 !== 0) {
        $mask .= chr(0xFF << (8 - $bits % 8) & 0xFF);
    }
    $mask = str_pad($mask, 16, "\0");
    return (substr($ipBin, 0, strlen($mask)) & $mask) === (substr($netBin, 0, strlen($mask)) & $mask);
}

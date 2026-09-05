<?php
/**
 * 端到端 API 验证脚本
 * 模拟 SDK 完整流程：验证 → 注册 → 心跳 → 弹窗 → 命令 → 更新检测 → 模块调用
 * 用法: php tests/e2e.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$BASE = 'http://127.0.0.1:8080/api/v1';
$APP_SECRET = '66b097d9828db3f130a4dc84bbd6f27907def20976bab67b59cd1561b4975533';
$PRODUCT_CODE = 'shop_v2';

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ $name\n";
    } else {
        $fail++;
        echo "  ✗ $name  $detail\n";
    }
}

function sign(string $secret, int $ts, string $nonce, string $body): string
{
    return hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
}

function api(string $path, array $body, string $secret, ?string $instanceId = null): array
{
    global $BASE;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $ts = time();
    $nonce = bin2hex(random_bytes(8));
    $sign = sign($secret, $ts, $nonce, $json);
    $headers = [
        'Content-Type: application/json',
        'X-Instance-Id: ' . ($instanceId ?: ''),
        'X-Timestamp: ' . $ts,
        'X-Nonce: ' . $nonce,
        'X-Sign: ' . $sign,
    ];
    $ch = curl_init("$BASE/$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['code' => -1, 'msg' => '响应异常: ' . $raw . ' / ' . $err, 'data' => null];
    }
    return $decoded;
}

echo "=== 1. 三合一授权验证 ===\n";

// 1.1 正常验证（127.0.0.1 域名 + 授权码 + IP）
require_once dirname(__DIR__) . '/core/Bootstrap.php';
// 按产品取授权码（避免误取其他产品的最新授权码导致 2004 产品不匹配）。
// 优先选择"带域名/IP 白名单"的授权码：服务端语义为空白名单=不限制，
// 若取到空白名单的码，1.3/1.4 的 2005/2006 断言将永不触发（测试数据漂移）。
// 找不到符合条件的码时回退到最新一条，并提示先运行 php tests/seed.php。
$licenseRow = dcai_db()->queryOne(
    'SELECT l.license_key FROM licenses l JOIN products p ON p.id = l.product_id
     WHERE p.product_code = ? AND l.allowed_domains != \'\' AND l.allowed_ips != \'\'
     ORDER BY l.id DESC LIMIT 1',
    [$PRODUCT_CODE]
);
if (!$licenseRow) {
    $licenseRow = dcai_db()->queryOne(
        'SELECT l.license_key FROM licenses l JOIN products p ON p.id = l.product_id WHERE p.product_code = ? ORDER BY l.id DESC LIMIT 1',
        [$PRODUCT_CODE]
    );
}
$licenseKey = $licenseRow['license_key'] ?? '';
if ($licenseKey === '') {
    echo "  需要先运行 php tests/seed.php 初始化数据\n";
    exit(1);
}

$resp = api('auth/verify', [
    'product_code'  => $PRODUCT_CODE,
    'license_key'   => $licenseKey,
    'domain'        => '127.0.0.1',
    'ip'            => '127.0.0.1',
    'client_version' => '1.0.0',
    'instance_id'   => '',
], $APP_SECRET);
check('验证通过并签发令牌', ($resp['code'] ?? -1) === 0 && !empty($resp['data']['token']), json_encode($resp));
$token = $resp['data']['token'] ?? '';

// 1.2 错误授权码
$resp = api('auth/verify', [
    'product_code' => $PRODUCT_CODE,
    'license_key'  => 'DCAI-INVAL-IDKEY-NONE',
    'domain'       => '127.0.0.1',
    'ip'           => '127.0.0.1',
], $APP_SECRET);
check('无效授权码返回 2001', ($resp['code'] ?? -1) === 2001);

// 1.3 未授权域名
$resp = api('auth/verify', [
    'product_code' => $PRODUCT_CODE,
    'license_key'  => $licenseKey,
    'domain'       => 'evil.example.com',
    'ip'           => '127.0.0.1',
], $APP_SECRET);
check('未授权域名返回 2005', ($resp['code'] ?? -1) === 2005);

// 1.4 未授权 IP
$resp = api('auth/verify', [
    'product_code' => $PRODUCT_CODE,
    'license_key'  => $licenseKey,
    'domain'       => '127.0.0.1',
    'ip'           => '203.0.113.99',
], $APP_SECRET);
check('未授权 IP 返回 2006', ($resp['code'] ?? -1) === 2006);

// 1.5 签名错误
$json = json_encode(['product_code' => $PRODUCT_CODE, 'license_key' => $licenseKey, 'domain' => '127.0.0.1', 'ip' => '127.0.0.1']);
$ts = time(); $nonce = bin2hex(random_bytes(8));
$ch = curl_init("$BASE/auth/verify");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Instance-Id: ', 'X-Timestamp: ' . $ts, 'X-Nonce: ' . $nonce, 'X-Sign: wrongsign'],
]);
$raw = curl_exec($ch); curl_close($ch);
$bad = json_decode((string)$raw, true);
check('错误签名返回 1002', ($bad['code'] ?? -1) === 1002);

echo "=== 2. 实例注册 ===\n";
$resp = api('instance/register', [
    'product_code' => $PRODUCT_CODE,
    'license_key'  => $licenseKey,
    'domain'       => '127.0.0.1',
    'ip'           => '127.0.0.1',
    'version'      => '1.0.0',
    'server_info'  => ['php_version' => '8.2', 'os' => 'Windows', 'memory_mb' => 4096, 'disk_free_mb' => 10240],
    'db_info'      => ['type' => 'mysql', 'version' => '8.0'],
], $APP_SECRET);
$instanceId = $resp['data']['instance_id'] ?? '';
$instanceToken = $resp['data']['instance_token'] ?? '';
check('实例注册成功', $resp['code'] === 0 && $instanceId !== '', json_encode($resp));
check('返回心跳间隔', !empty($resp['data']['heartbeat_interval']));

echo "=== 3. 心跳 ===\n";
$resp = api('instance/heartbeat', [
    'version'     => '1.0.0',
    'server_info' => ['php_version' => '8.2', 'os' => 'Windows', 'memory_mb' => 4096],
    'db_info'     => ['type' => 'mysql', 'version' => '8.0'],
], $instanceToken, $instanceId);
check('心跳成功且 revoked=false', $resp['code'] === 0 && empty($resp['data']['revoked']), json_encode($resp));

echo "=== 4. 弹窗 ===\n";
$resp = api('popup/list', [], $instanceToken, $instanceId);
$popupId = $resp['data']['popups'][0]['id'] ?? 0;
check('拉取到弹窗', $resp['code'] === 0 && $popupId > 0, json_encode($resp));
if ($popupId > 0) {
    $resp = api('popup/report', ['popup_id' => $popupId], $instanceToken, $instanceId);
    check('弹窗展示上报成功', $resp['code'] === 0);
}

echo "=== 5. 命令 ===\n";
// 通过服务层下发一条命令（模拟后台操作）
$instRow = dcai_db()->queryOne('SELECT id FROM instances WHERE instance_id = ?', [$instanceId]);
if ($instRow) {
    DCAI_CommandService::issue((int)$instRow['id'], 'config_push', ['config' => ['site_name' => '新站名']]);
}
$resp = api('command/poll', [], $instanceToken, $instanceId);
$cmd = $resp['data']['commands'][0] ?? null;
check('轮询到 config_push 命令', $resp['code'] === 0 && $cmd !== null && $cmd['command_type'] === 'config_push', json_encode($resp));
if ($cmd) {
    $resp = api('command/report', ['command_id' => $cmd['id'], 'status' => 2, 'result' => ['ok' => true, 'detail' => '配置已更新']], $instanceToken, $instanceId);
    check('命令结果上报成功', $resp['code'] === 0);
}

echo "=== 6. 更新检测 ===\n";
$resp = api('update/check', ['current_version' => '1.0.0'], $instanceToken, $instanceId);
check('更新检测接口可用', $resp['code'] === 0, json_encode($resp));

echo "=== 7. 远程模块 ===\n";
$resp = api('module/list', [], $instanceToken, $instanceId);
check('模块列表含 demo_hello', $resp['code'] === 0 && in_array('demo_hello', array_column($resp['data']['modules'] ?? [], 'module_code')), json_encode($resp));

$resp = api('module/invoke', ['module_code' => 'demo_hello', 'params' => ['name' => '张三']], $instanceToken, $instanceId);
check('模块调用成功', $resp['code'] === 0 && isset($resp['data']['result']['hello']), json_encode($resp));

$resp = api('module/invoke', ['module_code' => 'not_exist', 'params' => []], $instanceToken, $instanceId);
check('不存在的模块返回 3001', ($resp['code'] ?? -1) === 3001);

echo "=== 8. 实例令牌鉴权 ===\n";
$resp = api('command/poll', [], 'wrongtoken', 'bad-instance-id');
check('无效实例令牌返回 2100', ($resp['code'] ?? -1) === 2100, json_encode($resp));

echo "\n----------------------------------------\n";
echo "通过: $pass  |  失败: $fail\n";
exit($fail > 0 ? 1 : 0);

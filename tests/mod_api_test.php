<?php
// 真实 API 链路验证模块调用（宝塔环境 proc_open 被禁 → 走降级沙箱）
error_reporting(E_ALL & ~E_DEPRECATED);
$BASE = 'http://127.0.0.1:8080/api/v1/';

function api_call(string $path, array $body, string $secret, ?string $instanceId = null): array {
    global $BASE;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $ts = time();
    $nonce = bin2hex(random_bytes(8));
    $sign = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . hash('sha256', $json), $secret);
    $headers = [
        'Content-Type: application/json',
        'X-Instance-Id: ' . ($instanceId ?: ''),
        'X-Timestamp: ' . $ts,
        'X-Nonce: ' . $nonce,
        'X-Sign: ' . $sign,
    ];
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : ['code' => -1, 'msg' => $raw];
}

require_once 'D:/xiangmu/shouquan/core/Bootstrap.php';
$APP_SECRET = (string)dcai_config('sdk.app_secret');
$license = dcai_db()->queryOne(
    "SELECT l.license_key FROM licenses l JOIN products p ON p.id = l.product_id WHERE p.product_code = 'shop_v2' ORDER BY l.id DESC LIMIT 1"
);
if (!$license) { echo "[FAIL] 无 shop_v2 授权码\n"; exit(1); }

// 1. 验证拿 token
$r = api_call('auth/verify', [
    'product_code' => 'shop_v2', 'license_key' => $license['license_key'],
    'domain' => '127.0.0.1', 'ip' => '127.0.0.1', 'client_version' => '1.3.0', 'instance_id' => '',
], $APP_SECRET);
echo "1.验证: code={$r['code']} " . ($r['msg'] ?? '') . "\n";
if ($r['code'] !== 0) { echo "[FAIL] 验证失败\n"; exit(1); }
$token = $r['data']['token'];

// 2. 注册实例（拿 instance_token）
$r2 = api_call('instance/register', [
    'product_code' => 'shop_v2', 'license_key' => $license['license_key'],
    'domain' => '127.0.0.1', 'ip' => '127.0.0.1', 'version' => '1.3.0',
], $APP_SECRET);
$iid = $r2['data']['instance_id'] ?? '';
$itoken = $r2['data']['instance_token'] ?? '';
echo "2.注册: code={$r2['code']} iid=" . substr($iid, 0, 8) . "\n";
if ($itoken === '') { echo "[FAIL] 未拿到 instance_token\n"; exit(1); }

// 3. 模块列表（instance_token 签名）
$r3 = api_call('module/list', [], $itoken, $iid);
$mods = $r3['data']['modules'] ?? [];
echo "3.模块列表: code={$r3['code']} 含demo_hello=" . (in_array('demo_hello', array_column($mods, 'module_code')) ? 'yes' : 'no') . "\n";

// 4. 模块调用（关键：宝塔环境 proc_open 禁用 → 降级沙箱）
$r4 = api_call('module/invoke', ['module_code' => 'demo_hello', 'params' => ['name' => '张三']], $itoken, $iid);
echo "4.模块调用: code={$r4['code']} " . ($r4['msg'] ?? '') . " data=" . json_encode($r4['data'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
echo (($r4['code'] ?? -1) === 0 && !empty($r4['data']['result']['hello'])) ? "  [PASS] 降级沙箱模块调用成功\n" : "  [FAIL] 模块调用失败\n";

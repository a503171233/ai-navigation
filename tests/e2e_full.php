<?php
/**
 * 端到端全链路测试：
 * 1. 开启商城模式
 * 2. 标记产品为商城销售
 * 3. 注册买家
 * 4. 买家登录 → 下单
 * 5. 模拟支付回调 → 自动发码
 * 6. 买家查看授权码
 * 7. 自助绑定域名/IP
 */
$base = 'http://127.0.0.1:8080';

function api(string $url, array $post = [], string $method = 'POST'): array {
    global $base;
    $ch = curl_init($base . $url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10];
    if ($method === 'POST' && $post) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $http, 'raw' => $raw];
}

function e(string $s): string { return $s; }

echo "=== 1. 开启商城模式 (settings 表写入) ===\n";
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=shouquan;charset=utf8mb4', 'shouquan', 'shouquan');
$db->exec("REPLACE INTO settings (skey, svalue, updated_at) VALUES ('store_enabled','1', NOW())");
echo "store_enabled=1\n";

// 检查产品，设置 ai_novel 为销售
$pdoCheck = $db->query("SELECT id FROM products WHERE product_code='ai_novel'")->fetchColumn();
if (!$pdoCheck) {
    $db->exec("INSERT INTO products (product_code, name, description, current_version, enforce_auth, fail_open, status, for_sale, sale_price, price_unit, created_at, updated_at) VALUES ('ai_novel','AI小说助手','AI智能小说创作平台','1.2.1',1,1,1,1,99.00,'year',NOW(),NOW())");
    echo "ai_novel product created\n";
} else {
    $db->exec("UPDATE products SET for_sale=1, sale_price=99.00, price_unit='year', status=1 WHERE product_code='ai_novel'");
    echo "ai_novel set for_sale=1\n";
}

echo "\n=== 2. 注册买家 ===\n";
$resp = http_post($base . '/shop/register', ['email'=>'test@example.com','password'=>'12345678','nickname'=>'测试买家']);
echo "register: " . substr($resp, 0, 300) . "\n";

echo "\n=== 3. 买家登录 ===\n";
$resp = http_post($base . '/shop/login', ['email'=>'test@example.com','password'=>'12345678']);
echo "login: " . substr($resp, 0, 300) . "\n";

echo "\n=== 4. 检查产品列表 ===\n";
$resp = file_get_contents($base . '/shop/home');
echo "home page: " . (strpos($resp, 'AI小说助手') !== false ? 'PASS (product found)' : 'FAIL (product not found)') . "\n";

echo "\n=== 5. 模拟支付成功 (直接调用 StoreService::paySuccess) ===\n";
require_once dirname(__DIR__) . '/core/Bootstrap.php';
$db = dcai_db();
// 找最新的买家
$buyer = $db->queryOne("SELECT * FROM buyers WHERE username='test@example.com'");
if (!$buyer) {
    echo "FAIL: buyer not found\n"; exit;
}
echo "buyer id={$buyer['id']} email={$buyer['email']}\n";

// 找产品
$product = $db->queryOne("SELECT * FROM products WHERE product_code='ai_novel'");
if (!$product) {
    echo "FAIL: product not found\n"; exit;
}
echo "product id={$product['id']} name={$product['name']} price={$product['sale_price']}\n";

// 创建订单
$createResult = DCAI_Store::createOrder($product['id'], $buyer['id'], 'manual', '测试绑定域名', 1);
$ok = $createResult[0]; $result = $createResult[1];
if (!$ok) { echo "FAIL createOrder: $result\n"; exit; }
$orderId = $result['order_id'];
$orderNo = $result['order_no'];
echo "order created: id=$orderId no=$orderNo amount={$result['amount']}\n";

// 模拟支付成功
$payResultArr = DCAI_Store::paySuccess($orderId, 'TEST_TRADE_001', 'manual');
$ok = $payResultArr[0]; $payResult = $payResultArr[1];
if (!$ok) { echo "FAIL paySuccess: $payResult\n"; exit; }
echo "paySuccess: license_key={$payResult['license']['license_key']}\n";

echo "\n=== 6. 买家查看授权码 ===\n";
$licenses = DCAI_Store::buyerLicenses($buyer['id']);
foreach ($licenses as $l) {
    $expireShow = $l['expire_at'] ?: 'permanent';
    echo "  license: {$l['license_key']} product={$l['product_name']} expire={$expireShow} status={$l['status']}\n";
}

echo "\n=== 7. 自助绑定域名/IP ===\n";
$targetLic = $licenses[0];
$bindResult = DCAI_Store::bindLicense($targetLic['id'], $buyer['id'], ['example.com', 'www.example.com'], ['1.2.3.4', '192.168.0.0/16']);
echo "bind: " . ($bindResult[0] ? 'PASS' : 'FAIL: ' . $bindResult[1]) . "\n";

// 验证绑定
$check = $db->queryOne("SELECT allowed_domains, allowed_ips FROM licenses WHERE id = ?", [$targetLic['id']]);
echo "  domains: {$check['allowed_domains']}\n";
echo "  ips: {$check['allowed_ips']}\n";

echo "\n=== 8. 验证授权 API (verify/register/heartbeat) ===\n";
// 用 SDK 模拟一次授权验证
$licenseKey = $payResult['license']['license_key'];
$ts = time();
$nonce = bin2hex(random_bytes(8));
$headers = [
    'Content-Type: application/json; charset=utf-8',
    'X-Timestamp: ' . $ts,
    'X-Nonce: ' . $nonce,
];
$body = json_encode(['product_code'=>'ai_novel','license_key'=>$licenseKey,'domain'=>'example.com','ip'=>'1.2.3.4','client_version'=>'1.2.1']);
$appSecret = '66b097d9828db3f130a4dc84bbd6f27907def20976bab67b59cd1561b4975533';
// 签名内容为纯值拼接：ts . "\n" . nonce . "\n" . sha256(body)（不能把 "X-Timestamp: " 前缀带进去）
$sign = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . hash('sha256', $body), $appSecret);
$headers[] = 'X-Sign: ' . $sign;

$ch = curl_init($base . '/api/v1/auth/verify');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>10]);
$raw = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$resp = json_decode($raw ?: '{}', true);
echo "verify HTTP=$http code={$resp['code']}\n";
echo "  verified: " . ($resp['data']['verified'] ?? false ? 'PASS' : 'FAIL') . "\n";
if (!empty($resp['data']['token'])) {
    echo "  token: " . substr($resp['data']['token'], 0, 40) . "...\n";
}

echo "\n=== 全部测试完成 ===\n";

function http_post(string $url, array $data): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($data), CURLOPT_TIMEOUT=>10, CURLOPT_FOLLOWLOCATION=>false]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return "HTTP=$http " . substr($raw ?: '(empty)', 0, 200);
}
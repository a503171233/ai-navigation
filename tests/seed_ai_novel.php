<?php
/**
 * 为 AI小说网（AIdaohang / ThinkPHP 8）创建产品与本地联调授权码
 * 用法: php tests/seed_ai_novel.php
 */
require_once dirname(__DIR__) . '/core/Bootstrap.php';

$db = dcai_db();
$now = dcai_now();

$productCode = 'ai_novel';

// 产品
$product = $db->queryOne('SELECT * FROM products WHERE product_code = ?', [$productCode]);
if (!$product) {
    $pid = $db->insert('products', [
        'product_code'    => $productCode,
        'name'            => 'AI小说网',
        'description'     => '基于 ThinkPHP 8 的 AI 小说导航站（AIdaohang）',
        'current_version' => '1.0.0',
        'enforce_auth'    => 1,
        'fail_open'       => 1,
        'verify_ttl'      => 3600,
        'status'          => 1,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
    echo "产品已创建, ID: $pid\n";
} else {
    $pid = (int)$product['id'];
    $db->update('products', ['enforce_auth' => 1, 'updated_at' => $now], 'id = ?', [$pid]);
    echo "产品已存在, ID: $pid\n";
}

// 授权码（绑定本地开发域名与 IP，带端口号条目兼容 SDK 以 HTTP_HOST 上报的行为）
$remark = 'AI小说网本地联调授权码';
$lic = $db->queryOne('SELECT * FROM licenses WHERE product_id = ? AND remark = ?', [$pid, $remark]);
if (!$lic) {
    [$ok, $lid] = DCAI_LicenseService::create([
        'product_id'      => $pid,
        'customer_name'   => 'AI小说网演示客户',
        'customer_email'  => 'demo@example.com',
        'allowed_domains' => "127.0.0.1\nlocalhost\n127.0.0.1:8000\nlocalhost:8000",
        'allowed_ips'     => "127.0.0.1\n::1",
        'max_instances'   => 5,
        'expire_at'       => date('Y-m-d H:i:s', strtotime('+365 days')),
        'remark'          => $remark,
        'status'          => 1,
    ]);
    if (!$ok) {
        fwrite(STDERR, "创建授权码失败: $lid\n");
        exit(1);
    }
    echo "授权码已创建, ID: $lid\n";
    $license = $db->queryOne('SELECT * FROM licenses WHERE id = ?', [$lid]);
} else {
    $license = $lic;
    echo "授权码已存在, ID: {$license['id']}\n";
}

echo "产品编码: {$productCode}\n";
echo "授权码: {$license['license_key']}\n";
echo "到期时间: {$license['expire_at']}\n";
echo "数据初始化完成\n";

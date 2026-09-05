<?php
/**
 * 买家下载安装包
 * 仅限持有该产品有效授权的买家
 */
require __DIR__ . '/_init.php';

if (!$currentBuyer) {
    header('Location: ' . shop_url('login'));
    exit;
}

$packageId = (int)($_GET['package_id'] ?? 0);
$db = dcai_db();

$pkg = $db->queryOne('SELECT * FROM install_packages WHERE id = ? AND status = 1', [$packageId]);
if (!$pkg) {
    http_response_code(404);
    exit('安装包不存在');
}

// 校验买家持有该产品有效授权
$hasLicense = (int)$db->queryValue(
    'SELECT COUNT(*) FROM licenses l
     WHERE l.buyer_id = ? AND l.product_id = ? AND l.status = 1
       AND (l.expire_at IS NULL OR l.expire_at >= ?)',
    [(int)$currentBuyer['id'], (int)$pkg['product_id'], dcai_now()]
);
if ($hasLicense === 0) {
    http_response_code(403);
    exit('您没有该产品的有效授权，无法下载安装包');
}

$full = (string)dcai_config('storage.path', DCAI_ROOT . '/storage') . '/' . $pkg['package_path'];
if (!is_file($full)) {
    http_response_code(404);
    exit('安装包文件缺失');
}

// 计数
$db->increment('install_packages', 'download_count', 'id = ?', [$packageId]);

header('Content-Type: application/zip');
header('Content-Length: ' . filesize($full));
header('Content-Disposition: attachment; filename="' . $pkg['product_code'] . '_' . $pkg['version'] . '.zip"');
header('X-Content-Type-Options: nosniff');
readfile($full);
exit;
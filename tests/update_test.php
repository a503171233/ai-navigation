<?php
/**
 * 更新全流程测试
 * 用法: php tests/update_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/core/Bootstrap.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $res, string $detail = ''): void
{
    global $pass, $fail;
    $res ? $pass++ : $fail++;
    echo ($res ? "  ✓ " : "  ✗ ") . $name . ($res ? '' : "  $detail") . "\n";
}

$BASE = 'http://127.0.0.1:8080/api/v1';

// 1. 构造一个最小更新包 zip（版本自动高于当前产品版本）
$product = dcai_db()->queryOne('SELECT id, current_version FROM products WHERE product_code = ?', ['shop_v2']);
$verParts = DCAI_Version::parse($product['current_version']);
$verParts[2]++;
$targetVersion = $verParts[0] . '.' . $verParts[1] . '.' . $verParts[2];

$tmpZip = sys_get_temp_dir() . '/demo_update_' . $targetVersion . '.zip';
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "无法创建 zip\n";
    exit(1);
}
$zip->addFromString('update.json', json_encode([
    'version'    => $targetVersion,
    'min_version' => '1.0.0',
    'changelog'  => '修复若干 Bug，新增功能',
    'files'      => ['app/demo_file.php'],
    'delete_files' => [],
    'migrate'    => '',
], JSON_UNESCAPED_UNICODE));
$zip->addFromString('app/demo_file.php', "<?php // demo update v{$targetVersion}\n");
$zip->close();

// 2. 通过服务层上传（模拟后台操作）
$product = dcai_db()->queryOne('SELECT id FROM products WHERE product_code = ?', ['shop_v2']);
$file = ['name' => 'demo_update_1.1.0.zip', 'type' => 'application/zip', 'tmp_name' => $tmpZip, 'error' => 0, 'size' => filesize($tmpZip)];
[$okUpload, $res, $err] = DCAI_UpdateService::upload($file, (int)$product['id'], 1);
ok('上传更新包成功', $okUpload, $err);
$updateId = $res['update_id'] ?? 0;

// 3. 发布
dcai_db()->update('updates', ['status' => 1], 'id = ?', [$updateId]);
ok('发布更新包', true);

// 4. 通过 API 检测更新
$inst = dcai_db()->queryOne('SELECT * FROM instances WHERE product_id = ? ORDER BY id DESC LIMIT 1', [(int)$product['id']]);
$instanceId = $inst['instance_id'];
$token = DCAI_Crypto::decrypt($inst['instance_token_enc']);
$fromVersion = (string)$inst['version'];

$json = json_encode(['current_version' => $fromVersion]);
$ts = time(); $nonce = bin2hex(random_bytes(8));
$sign = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . hash('sha256', $json), $token);
$ch = curl_init("$BASE/update/check");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Instance-Id: ' . $instanceId, 'X-Timestamp: ' . $ts, 'X-Nonce: ' . $nonce, 'X-Sign: ' . $sign],
    CURLOPT_TIMEOUT => 10,
]);
$raw = curl_exec($ch); curl_close($ch);
$resp = json_decode((string)$raw, true);
ok("检测到新版本 $targetVersion", ($resp['code'] ?? -1) === 0 && ($resp['data']['has_update'] ?? false) === true && ($resp['data']['update']['version'] ?? '') === $targetVersion, $raw);
$downloadUrl = $resp['data']['update']['download_url'] ?? '';
$md5 = $resp['data']['update']['md5'] ?? '';
ok('下载地址带签名', strpos($downloadUrl, 's=') !== false);
ok('返回 MD5', $md5 !== '' && $md5 === md5_file($tmpZip));

// 5. 下载更新包
if ($downloadUrl !== '') {
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true]);
    $downloaded = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    ok('下载更新包返回 200', $httpCode === 200, "http=$httpCode");
    ok('下载内容 MD5 一致', md5($downloaded) === $md5);
}

// 6. 非法签名下载被拒绝
$badUrl = str_replace('s=' . substr($downloadUrl, strpos($downloadUrl, 's=') + 2), 's=deadbeef', $downloadUrl);
if (strpos($downloadUrl, 's=') !== false) {
    $badUrl = substr($downloadUrl, 0, strpos($downloadUrl, 's=')) . 's=deadbeef';
    $ch = curl_init($badUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $raw2 = curl_exec($ch); curl_close($ch);
    $bad = json_decode((string)$raw2, true);
    ok('非法签名下载被拒绝', ($bad['code'] ?? -1) === 1003, $raw2);
}

// 7. 更新结果上报
$instRowId = (int)$inst['id'];
DCAI_UpdateService::logApply($updateId, $instRowId, $fromVersion, 0);
$json = json_encode(['target_version' => $targetVersion, 'status' => 2, 'error' => '']);
$ts = time(); $nonce = bin2hex(random_bytes(8));
$sign = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . hash('sha256', $json), $token);
$ch = curl_init("$BASE/update/report");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Instance-Id: ' . $instanceId, 'X-Timestamp: ' . $ts, 'X-Nonce: ' . $nonce, 'X-Sign: ' . $sign],
    CURLOPT_TIMEOUT => 10,
]);
$raw = curl_exec($ch); curl_close($ch);
$resp = json_decode((string)$raw, true);
ok('更新结果上报成功', ($resp['code'] ?? -1) === 0, $raw);

$newVer = dcai_db()->queryValue('SELECT version FROM instances WHERE id = ?', [$instRowId]);
ok("实例版本同步为 $targetVersion", $newVer === $targetVersion, "version=$newVer");

@unlink($tmpZip);
echo "\n----------------------------------------\n";
echo "更新测试通过: $pass  |  失败: $fail\n";
exit($fail > 0 ? 1 : 0);

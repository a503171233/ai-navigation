<?php
/**
 * 健康检查端点 /api/healthz
 * 返回服务与数据库状态，供负载均衡/监控/被授权程序排障探测。
 * 无需鉴权，仅返回最小状态信息。
 */
require_once dirname(__DIR__) . '/core/Bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$status = 'ok';
$dbOk = false;
$checks = [];

// 数据库检查
try {
    dcai_db()->queryValue('SELECT 1');
    $dbOk = true;
    $checks[] = ['name' => 'database', 'status' => 'ok'];
} catch (Throwable $e) {
    $status = 'degraded';
    $checks[] = ['name' => 'database', 'status' => 'error', 'error' => 'db unreachable'];
}

// 存储目录检查
$storagePath = (string)dcai_config('storage.path', DCAI_ROOT . '/storage');
$storageWritable = is_dir($storagePath) && is_writable($storagePath);
$checks[] = ['name' => 'storage', 'status' => $storageWritable ? 'ok' : 'error'];
if (!$storageWritable) {
    $status = 'degraded';
}

$http = $status === 'ok' ? 200 : 503;
http_response_code($http);
echo json_encode([
    'status'   => $status,
    'version'  => DCAI_SYSTEM_VERSION,
    'service'  => (string)dcai_config('app.name', 'DCAI 授权系统'),
    'time'     => dcai_now(),
    'uptime'   => null,
    'checks'   => $checks,
], JSON_UNESCAPED_UNICODE);
exit;

<?php
/**
 * API 资源处理器: update
 * 动作: check（更新检测 POST）/ download（更新包下载 GET，签名在查询串）/ report（结果上报 POST）
 */
$action = $GLOBALS['DCAI_API_ACTION'] ?? ($_GET['act'] ?? '');
$instance = $GLOBALS['DCAI_INSTANCE'] ?? null;

switch ($action) {
    case 'check':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        if (!$instance) {
            DCAI_Response::fail(2100, '实例令牌无效');
        }
        $body = dcai_api_body();
        $currentVersion = (string)($body['current_version'] ?? $instance['version']);
        if ($currentVersion === '') {
            DCAI_Response::fail(1001, '缺少 current_version');
        }
        [, $result] = DCAI_UpdateService::check((int)$instance['product_id'], $currentVersion, (int)$instance['id']);
        DCAI_Response::success($result);
        break;

    case 'download':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            DCAI_Response::fail(1001, '仅支持 GET');
        }
        $updateId = (int)($_GET['u'] ?? 0);
        $instanceRowId = (int)($_GET['i'] ?? 0);
        $expire = (int)($_GET['e'] ?? 0);
        $sign = (string)($_GET['s'] ?? '');
        if ($updateId <= 0 || $sign === '') {
            DCAI_Response::fail(1001, '下载参数缺失');
        }
        $update = DCAI_UpdateService::resolveDownload($updateId, $instanceRowId ?: null, $expire, $sign);
        if (!$update) {
            DCAI_Response::fail(1003, '下载链接无效或已过期');
        }
        $full = (string)dcai_config('storage.path', DCAI_ROOT . '/storage') . '/' . $update['package_path'];
        if (!is_file($full)) {
            DCAI_Response::fail(5000, '更新包文件不存在');
        }
        // 记录下载日志（实例存在时）
        if ($instanceRowId > 0) {
            $inst = dcai_db()->queryOne('SELECT version FROM instances WHERE id = ?', [$instanceRowId]);
            DCAI_UpdateService::logApply($updateId, $instanceRowId, $inst['version'] ?? '', 0);
        }
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: attachment; filename="update_' . $update['version'] . '.zip"');
        header('X-Content-Type-Options: nosniff');
        readfile($full);
        exit;

    case 'report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        if (!$instance) {
            DCAI_Response::fail(2100, '实例令牌无效');
        }
        $body = dcai_api_body();
        $targetVersion = (string)($body['target_version'] ?? '');
        $status = (int)($body['status'] ?? 0);
        $error = (string)($body['error'] ?? '');
        if ($targetVersion === '' || !in_array($status, [1, 2, 3], true)) {
            DCAI_Response::fail(1001, '参数缺失或状态无效');
        }
        DCAI_UpdateService::report((int)$instance['id'], $targetVersion, $status, $error);
        DCAI_Response::success(null, '上报成功');
        break;

    default:
        DCAI_Response::fail(1001, '未知动作: ' . $action);
}

<?php
/**
 * API 资源处理器: command
 * 动作: poll（命令轮询）/ report（结果上报）
 */
$action = $GLOBALS['DCAI_API_ACTION'] ?? ($_GET['act'] ?? '');
$instance = $GLOBALS['DCAI_INSTANCE'] ?? null;

switch ($action) {
    case 'poll':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        if (!$instance) {
            DCAI_Response::fail(2100, '实例令牌无效');
        }
        $commands = DCAI_CommandService::poll((int)$instance['id']);
        DCAI_Response::success(['commands' => $commands]);
        break;

    case 'report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        if (!$instance) {
            DCAI_Response::fail(2100, '实例令牌无效');
        }
        $body = dcai_api_body();
        $commandId = (int)($body['command_id'] ?? 0);
        $status = (int)($body['status'] ?? 0);
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];
        if ($commandId <= 0) {
            DCAI_Response::fail(1001, '缺少 command_id');
        }
        [$ok, $err] = DCAI_CommandService::report($commandId, (int)$instance['id'], $status, $result);
        if (!$ok) {
            DCAI_Response::fail(1001, $err);
        }
        DCAI_Response::success(null, '上报成功');
        break;

    default:
        DCAI_Response::fail(1001, '未知动作: ' . $action);
}

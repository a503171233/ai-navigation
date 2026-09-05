<?php
/**
 * API 资源处理器: instance
 * 动作: register（实例注册）/ heartbeat（心跳上报）
 */
$action = $GLOBALS['DCAI_API_ACTION'] ?? ($_GET['act'] ?? '');
$instance = $GLOBALS['DCAI_INSTANCE'] ?? null;

switch ($action) {
    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        [$err, $result] = DCAI_InstanceService::register(dcai_api_body());
        if ($err !== null) {
            DCAI_Response::fail($err, DCAI_VerifyService::reasonText($err));
        }
        DCAI_Response::success($result);
        break;

    case 'heartbeat':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        if (!$instance) {
            DCAI_Response::fail(2100, '实例令牌无效');
        }
        [$err, $result] = DCAI_InstanceService::heartbeat((int)$instance['id'], dcai_api_body(), $instance);
        if ($err !== null) {
            DCAI_Response::fail($err, DCAI_VerifyService::reasonText($err));
        }
        DCAI_Response::success($result);
        break;

    default:
        DCAI_Response::fail(1001, '未知动作: ' . $action);
}

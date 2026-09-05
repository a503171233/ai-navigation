<?php
/**
 * API 资源处理器: auth
 * 动作: verify（三合一授权验证）
 */
$action = $GLOBALS['DCAI_API_ACTION'] ?? ($_GET['act'] ?? '');

switch ($action) {
    case 'verify':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        [$err, $result] = DCAI_VerifyService::verify(dcai_api_body());
        if ($err !== null) {
            DCAI_Response::fail($err, DCAI_VerifyService::reasonText($err));
        }
        DCAI_Response::success($result);
        break;

    default:
        DCAI_Response::fail(1001, '未知动作: ' . $action);
}

<?php
/**
 * API 资源处理器: popup
 * 动作: list（弹窗拉取）/ report（展示上报）
 */
$action = $GLOBALS['DCAI_API_ACTION'] ?? ($_GET['act'] ?? '');
$instance = $GLOBALS['DCAI_INSTANCE'] ?? null;

switch ($action) {
    case 'list':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        if (!$instance) {
            DCAI_Response::fail(2100, '实例令牌无效');
        }
        $popups = DCAI_PopupService::listForInstance((int)$instance['id']);
        DCAI_Response::success(['popups' => $popups]);
        break;

    case 'report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        if (!$instance) {
            DCAI_Response::fail(2100, '实例令牌无效');
        }
        $body = dcai_api_body();
        $popupId = (int)($body['popup_id'] ?? 0);
        if ($popupId <= 0) {
            DCAI_Response::fail(1001, '缺少 popup_id');
        }
        DCAI_PopupService::reportShown($popupId, (int)$instance['id']);
        DCAI_Response::success(null, '上报成功');
        break;

    default:
        DCAI_Response::fail(1001, '未知动作: ' . $action);
}

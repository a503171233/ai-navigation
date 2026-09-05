<?php
/**
 * API 资源处理器: module
 * 动作: list（模块列表）/ invoke（模块调用）
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
        $modules = DCAI_ModuleService::listForProduct((int)$instance['product_id']);
        DCAI_Response::success(['modules' => $modules]);
        break;

    case 'invoke':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        if (!$instance) {
            DCAI_Response::fail(2100, '实例令牌无效');
        }
        $body = dcai_api_body();
        $moduleCode = (string)($body['module_code'] ?? '');
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        if ($moduleCode === '') {
            DCAI_Response::fail(1001, '缺少 module_code');
        }
        [$err, $result] = DCAI_ModuleService::invoke((int)$instance['product_id'], (int)$instance['id'], $moduleCode, $params);
        if ($err !== null) {
            $msgMap = [3001 => '模块不存在或未启用', 3002 => '模块参数校验失败', 3003 => '模块执行异常'];
            DCAI_Response::fail($err, $msgMap[$err] ?? '模块调用失败');
        }
        DCAI_Response::success($result);
        break;

    default:
        DCAI_Response::fail(1001, '未知动作: ' . $action);
}

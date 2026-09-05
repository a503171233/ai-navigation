<?php
/**
 * API 资源处理器: skill
 * 动作: list（技能列表）/ invoke（技能函数调用）
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
        $licenseId = !empty($instance['license_id']) ? (int)$instance['license_id'] : null;
        $skills = DCAI_SkillService::listForInstance((int)$instance['product_id'], $licenseId);
        DCAI_Response::success(['skills' => $skills]);
        break;

    case 'invoke':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        if (!$instance) {
            DCAI_Response::fail(2100, '实例令牌无效');
        }
        $body = dcai_api_body();
        $skillCode = (string)($body['skill_code'] ?? '');
        $functionCode = (string)($body['function_code'] ?? '');
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        if ($skillCode === '' || $functionCode === '') {
            DCAI_Response::fail(1001, '缺少 skill_code 或 function_code');
        }
        [$err, $result] = DCAI_SkillService::invoke($instance, $skillCode, $functionCode, $params);
        if ($err !== null) {
            $msgMap = [
                3101 => '技能不存在或未启用',
                3102 => '当前授权码无权使用该技能',
                3103 => '技能函数不存在或未启用',
                3104 => '技能参数校验失败',
                3105 => '技能执行异常',
            ];
            DCAI_Response::fail($err, $msgMap[$err] ?? '技能调用失败');
        }
        DCAI_Response::success($result);
        break;

    default:
        DCAI_Response::fail(1001, '未知动作: ' . $action);
}
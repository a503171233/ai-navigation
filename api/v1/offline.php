<?php
/**
 * API 资源处理器: offline（离线激活）
 * 动作:
 *  - request（在线签发）：客户端提交激活请求，服务端校验授权码+机器码后直接签发激活文件
 *  - verify（校验激活文件）：返回激活文件有效性（联网场景客户端导入前可先校验）
 *
 * 离线激活主流程（完全断网场景无需本 API）：
 *   客户端生成请求文件 → 管理员后台导入签发 → 激活文件交回客户端导入。
 * 本 API 是"在线签发"的等价能力，供可联网的客户机使用；两种途径签发的激活文件格式完全一致。
 */
$action = $GLOBALS['DCAI_API_ACTION'] ?? ($_GET['act'] ?? '');

switch ($action) {
    case 'request':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        $req = dcai_api_body();
        $productCode = trim((string)($req['product_code'] ?? ''));
        $licenseKey  = trim((string)($req['license_key'] ?? ''));
        $machineCode = strtolower(trim((string)($req['machine_code'] ?? '')));
        $machineName = trim((string)($req['machine_name'] ?? ''));

        if ($productCode === '' || $licenseKey === '' || !preg_match('/^[a-f0-9]{32,64}$/', $machineCode)) {
            DCAI_Response::fail(1001, '参数缺失或机器码格式错误');
        }
        $product = dcai_db()->queryOne('SELECT * FROM products WHERE product_code = ? AND status = 1', [$productCode]);
        if (!$product) {
            DCAI_Response::fail(2009, '产品不存在');
        }
        $license = dcai_db()->queryOne('SELECT * FROM licenses WHERE license_key = ? AND product_id = ?', [$licenseKey, (int)$product['id']]);
        if (!$license || (int)$license['status'] !== 1) {
            DCAI_Response::fail(2001, '授权码不存在或已禁用');
        }
        if ($license['expire_at'] !== null && $license['expire_at'] !== '' && strtotime($license['expire_at']) < time()) {
            DCAI_Response::fail(2003, '授权码已过期');
        }
        // 机器绑定校验：统一走 authorizeMachine（格式强校验 + 事务防超配）
        $machineErr = DCAI_MachineService::authorizeMachine((int)$license['id'], $machineCode, $machineName);
        if ($machineErr !== null) {
            if ($machineErr === DCAI_MachineService::ERR_LIMIT) {
                DCAI_Response::fail(2012, '绑定机器数已达上限或机器未在授权内');
            }
            DCAI_Response::fail(2011, '机器码格式非法或未通过校验');
        }
        // 签发激活文件（离线授权到期 = 授权码到期）
        [$ok, $res] = DCAI_OfflineActivationService::issue(
            (int)$license['id'],
            $machineCode,
            $machineName,
            null
        );
        if (!$ok) {
            DCAI_Response::fail(5000, $res);
        }
        // 记录签发日志（写 verify_logs：status=1 表示离线签发成功，reason 注明来源）
        try {
            dcai_db()->insert('verify_logs', [
                'product_id'       => (int)$product['id'],
                'license_id'       => (int)$license['id'],
                'instance_id'      => null,
                'domain'           => '',
                'ip'               => dcai_client_ip(),
                'license_key_masked' => DCAI_Util::maskLicenseKey($licenseKey),
                'result'           => 1,
                'reason'           => '离线激活签发(API)',
                'created_at'       => dcai_now(),
            ]);
        } catch (Throwable $e) {
            dcai_log('error', '写入离线签发日志失败', ['err' => $e->getMessage()]);
        }
        DCAI_Response::success(['activation' => $res['activation'], 'file_json' => $res['file_json']]);
        break;

    case 'verify':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            DCAI_Response::fail(1001, '仅支持 POST');
        }
        $req = dcai_api_body();
        $raw = (string)($req['file_json'] ?? '');
        if ($raw === '') {
            DCAI_Response::fail(1001, '缺少激活文件内容');
        }
        [$ok, $msg] = DCAI_OfflineActivationService::verifyActivationFile($raw);
        if (!$ok) {
            DCAI_Response::fail(5001, $msg);
        }
        DCAI_Response::success(['valid' => true, 'msg' => $msg]);
        break;

    default:
        DCAI_Response::fail(1001, '未知动作: ' . $action);
}
<?php
/**
 * 离线激活端到端验证（真实客户机闭环场景）
 * 1. 客户机 SDK 生成激活请求（指纹 = SDK 本机指纹）
 * 2. 管理员（服务端）解析 + 签发
 * 3. 同一客户机 SDK 导入激活文件，验签生效
 * 4. 断网场景 guard 放行
 * 5. 篡改激活文件 → 导入被拒
 */
error_reporting(E_ALL & ~E_DEPRECATED);

require_once 'D:/xiangmu/shouquan/core/Bootstrap.php';
require_once 'D:/xiangmu/shouquan/sdk/dcai_client.php';

// ---------- 准备：取一个有效授权码与产品 ----------
$license = dcai_db()->queryOne(
    "SELECT l.* FROM licenses l WHERE l.status = 1 AND l.expire_at > NOW() ORDER BY l.id DESC LIMIT 1"
);
if (!$license) { echo "[FAIL] 无可用授权码\n"; exit(1); }
$product = dcai_db()->queryOne('SELECT * FROM products WHERE id = ?', [(int)$license['product_id']]);
$pubKey = (string)dcai_config('security.rsa_public_key', '');
echo "[SETUP] 授权码 {$license['license_key']} 产品 {$product['product_code']}\n";

// 客户机 SDK（cache 独立目录，模拟真实客户机本地状态）
$cacheDir = 'D:/xiangmu/shouquan/tests/tmp/offline_cache_' . bin2hex(random_bytes(3)) . '/';
@mkdir($cacheDir, 0777, true);
$sdkConfig = [
    'server_url'     => 'http://127.0.0.1:8080/api/v1/',
    'product_code'   => $product['product_code'],
    'license_key'    => $license['license_key'],
    'app_secret'     => (string)dcai_config('sdk.app_secret'),
    'rsa_public_key' => $pubKey,
    'enabled'        => true,
    'fail_open'      => true,
    'cache_dir'      => $cacheDir,
    'app_version'    => '1.3.0',
];
$client = new DCAI_Client($sdkConfig);

// ---------- 1. SDK 生成激活请求 ----------
$requestJson = $client->createOfflineRequest();
$requestData = json_decode($requestJson, true);
echo "[OK] 客户机 SDK 生成激活请求, machine_code=" . substr($requestData['machine_code'], 0, 16) . "...\n";

// ---------- 2. 管理员解析 + 签发 ----------
[$okParse, $parsed] = DCAI_OfflineActivationService::parseRequest($requestJson);
echo $okParse ? "[OK] 请求解析成功\n" : "[FAIL] 请求解析: $parsed\n";
if (!$okParse) exit(1);

[$okIssue, $res] = DCAI_OfflineActivationService::issue(
    (int)$license['id'],
    $requestData['machine_code'],
    $requestData['machine_name'],
    null
);
if (!$okIssue) { echo "[FAIL] 签发失败: $res\n"; exit(1); }
echo "[OK] 管理员签发激活文件\n";

// ---------- 3. 服务端校验 ----------
// （与后台 offline_activate.php 一致：签发时同步把机器加入绑定，machine_limit>0 时校验绑定关系）
if ((int)$license['machine_limit'] > 0) {
    DCAI_MachineService::bind((int)$license['id'], $requestData['machine_code'], $requestData['machine_name']);
}
[$vOk, $vMsg] = DCAI_OfflineActivationService::verifyActivationFile($res['file_json']);
echo $vOk ? "[PASS] 服务端校验: $vMsg\n" : "[FAIL] 服务端校验: $vMsg\n";

// ---------- 4. 客户机导入激活文件 ----------
$applyOk = $client->applyOfflineActivation($res['file_json']);
echo $applyOk ? "[PASS] 客户机导入激活文件成功\n" : "[FAIL] 客户机导入: " . $client->lastOfflineError() . "\n";

// ---------- 5. guard 断网放行（模拟服务器不可达） ----------
if ($applyOk) {
    $guardOk = true;
    $client->guard(function () use (&$guardOk) { $guardOk = false; });
    echo $guardOk ? "[PASS] guard 放行（离线激活生效）\n" : "[FAIL] guard 未放行\n";

    // verify() 也应通过
    $v = $client->verify();
    echo $v ? "[PASS] verify() 通过（离线态）\n" : "[FAIL] verify() 失败\n";
    echo $client->hasOfflineActivation() ? "[PASS] hasOfflineActivation()=true\n" : "[FAIL] hasOfflineActivation()=false\n";
}

// ---------- 6. 篡改激活文件 → 拒签 ----------
if ($applyOk) {
    $tampered = json_decode($res['file_json'], true);
    $tampered['expire_at'] = '2000-01-01 00:00:00'; // 改成已过期
    $okTampered = $client->applyOfflineActivation(json_encode($tampered, JSON_UNESCAPED_UNICODE));
    echo $okTampered ? "[FAIL] 篡改后的激活文件竟导入成功\n" : "[PASS] 篡改/过期激活文件被拒绝\n";
}

// ---------- 7. 新机器（不同指纹）导入 → 拒绝 ----------
if ($applyOk) {
    $otherMachine = hash_hmac('sha256', hash('sha256', 'other-machine-fingerprint'), 'other');
    [$iOk, $otherRes] = DCAI_OfflineActivationService::issue((int)$license['id'], $otherMachine, '别台机器', null);
    if ($iOk) {
        $okOther = $client->applyOfflineActivation($otherRes['file_json']);
        echo $okOther ? "[FAIL] 其它机器的激活文件导入成功（机器匹配失效）\n" : "[PASS] 其它机器激活文件被拒绝（机器码不匹配）\n";
    }
}

// 清理：删除本次自动签发的离线激活记录（保持测试环境干净）
dcai_db()->execute(
    "DELETE FROM offline_activations WHERE machine_code = ? AND machine_name = ?",
    [$requestData['machine_code'], $requestData['machine_name']]
);
echo "[OK] 清理测试数据\n";
echo "DONE\n";
<?php
// 离线激活链路加固验证：签发 / 校验 / 篡改 / 过期 / 作废 / 跨机器复制
require_once 'D:/xiangmu/shouquan/core/Bootstrap.php';
$db = dcai_db();

$pass = 0; $fail = 0;
function chk(string $name, bool $ok) { global $pass, $fail; echo ($ok ? "  [PASS] " : "  [FAIL] ") . $name . "\n"; $ok ? $pass++ : $fail++; }

// 准备测试授权码（machine_limit=5）
$lic = $db->queryOne("SELECT * FROM licenses WHERE status = 1 AND machine_limit > 0 ORDER BY id LIMIT 1");
if (!$lic) { echo "无可用授权码\n"; exit(1); }
$lid = (int)$lic['id'];
$mcA = str_repeat('a1', 32);   // 合法 hex 机器码 A
$mcB = str_repeat('b2', 32);   // 合法 hex 机器码 B

// 清理
$db->execute('DELETE FROM offline_activations WHERE license_id = ?', [$lid]);
$db->execute('DELETE FROM machine_bindings WHERE license_id = ?', [$lid]);

// 1. 格式校验：非法机器码签发被拒
[$ok, $msg] = DCAI_OfflineActivationService::issue($lid, str_repeat('z', 64), '坏机器');
chk("非法机器码签发拒绝（{$msg}）", !$ok);

// 2. 正常签发
[$ok, $res] = DCAI_OfflineActivationService::issue($lid, $mcA, '机器A');
chk('正常签发成功', $ok);
$file = $res['activation'];
$fileJson = $res['file_json'];

// 3. 校验通过（先绑定机器，因为 verifyActivationFile 现在校验绑定关系）
DCAI_MachineService::bind($lid, $mcA, '机器A');
[$ok, $msg] = DCAI_OfflineActivationService::verifyActivationFile($fileJson);
chk("校验通过（{$msg}）", $ok);

// 4. 篡改到期时间 → 验签失败
$tampered = $file;
$tampered['expire_at'] = '2099-01-01 00:00:00';
$tampered['signature'] = $file['signature']; // 保留原签名
[$ok, $msg] = DCAI_OfflineActivationService::verifyActivationFile(json_encode($tampered, JSON_UNESCAPED_UNICODE));
chk('篡改 expire_at 被拒（签名不匹配）', !$ok);

// 5. 篡改机器码 → 验签失败
$tampered2 = $file;
$tampered2['machine_code'] = $mcB;
$tampered2['signature'] = $file['signature'];
[$ok, $msg] = DCAI_OfflineActivationService::verifyActivationFile(json_encode($tampered2, JSON_UNESCAPED_UNICODE));
chk('篡改机器码被拒', !$ok);

// 6. 跨机器复制：机器 B 的绑定外激活文件（合法签名）在校验时被机器绑定关系拦截
[$okB, $resB] = DCAI_OfflineActivationService::issue($lid, $mcB, '机器B未绑定');
chk('机器B签发成功（但未绑定）', $okB);
[$ok, $msg] = DCAI_OfflineActivationService::verifyActivationFile($resB['file_json']);
chk("机器B激活文件因未绑定被拒（{$msg}）", !$ok);

// 7. 机器B绑定后校验通过
DCAI_MachineService::bind($lid, $mcB, '机器B');
[$ok, $msg] = DCAI_OfflineActivationService::verifyActivationFile($resB['file_json']);
chk("机器B绑定后校验通过（{$msg}）", $ok);

// 8. 作废后校验被拒
$rec = $db->queryOne('SELECT id FROM offline_activations WHERE license_id = ? AND machine_code = ? ORDER BY id DESC LIMIT 1', [$lid, $mcB]);
if ($rec) {
    DCAI_OfflineActivationService::revoke((int)$rec['id']);
    [$ok, $msg] = DCAI_OfflineActivationService::verifyActivationFile($resB['file_json']);
    chk("作废后校验被拒（{$msg}）", !$ok);
} else {
    chk('作废记录存在', false);
}

// 9. 过期激活文件：签发一个过期文件（expire_at 在过去）
[$okC, $resC] = DCAI_OfflineActivationService::issue($lid, $mcA, '机器A', date('Y-m-d H:i:s', time() - 3600));
chk('过期文件签发（测试构造）', $okC);
if ($okC) {
    [$ok, $msg] = DCAI_OfflineActivationService::verifyActivationFile($resC['file_json']);
    chk("过期文件校验被拒（{$msg}）", !$ok);
}

// 10. list() 过滤生效
$rows = DCAI_OfflineActivationService::list(['license_id' => $lid, 'machine_code' => $mcA], 1, 20);
chk('list() 按条件过滤生效', is_array($rows) && count($rows) > 0 && (string)$rows[0]['machine_code'] === $mcA);

// 11. parseRequest 格式校验
[$ok, $msg] = DCAI_OfflineActivationService::parseRequest(json_encode([
    'type' => 'dcai_offline_request', 'version' => 1,
    'product_code' => 'p', 'license_key' => 'L', 'machine_code' => 'bad!!!', 'request_id' => 'rid',
]));
chk('parseRequest 拒绝非法机器码', !$ok);
[$ok, $data] = DCAI_OfflineActivationService::parseRequest(json_encode([
    'type' => 'dcai_offline_request', 'version' => 1,
    'product_code' => 'p', 'license_key' => 'L', 'machine_code' => str_repeat('a', 64), 'request_id' => 'rid',
]));
chk('parseRequest 接受合法机器码', $ok && $data['machine_code'] === str_repeat('a', 64));

// 清理
$db->execute('DELETE FROM offline_activations WHERE license_id = ?', [$lid]);
$db->execute('DELETE FROM machine_bindings WHERE license_id = ?', [$lid]);

echo "\n结果: {$pass} 通过 / {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
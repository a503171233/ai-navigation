<?php
// 离线激活链路加固-篡改专项验证（修正断言变量）
require_once 'D:/xiangmu/shouquan/core/Bootstrap.php';
$db = dcai_db();
$pass = 0; $fail = 0;
function chk(string $name, bool $ok) { global $pass, $fail; echo ($ok ? "  [PASS] " : "  [FAIL] ") . $name . "\n"; $ok ? $pass++ : $fail++; }

$lic = $db->queryOne("SELECT * FROM licenses WHERE status = 1 AND machine_limit > 0 ORDER BY id LIMIT 1");
$lid = (int)$lic['id'];
$mcA = str_repeat('a1', 32);
$db->execute('DELETE FROM machine_bindings WHERE license_id = ?', [$lid]);
$db->execute('DELETE FROM offline_activations WHERE license_id = ?', [$lid]);

DCAI_MachineService::bind($lid, $mcA, '机器A');
[$ok, $res] = DCAI_OfflineActivationService::issue($lid, $mcA, '机器A');
$file = $res['activation'];

// 篡改 expire_at（保留原签名）
$tampered = $file;
$tampered['expire_at'] = '2099-01-01 00:00:00';
$tampered['signature'] = $file['signature'];
[$vok, $vmsg] = DCAI_OfflineActivationService::verifyActivationFile(json_encode($tampered, JSON_UNESCAPED_UNICODE));
chk('篡改 expire_at 被拒（验签失败）', !$vok);
echo "    实际返回: vok=" . var_export($vok, true) . " msg={$vmsg}\n";

// 篡改机器码
$t2 = $file;
$t2['machine_code'] = str_repeat('c3', 32);
$t2['signature'] = $file['signature'];
[$vok2, $vmsg2] = DCAI_OfflineActivationService::verifyActivationFile(json_encode($t2, JSON_UNESCAPED_UNICODE));
chk('篡改机器码被拒（验签失败）', !$vok2);
echo "    实际返回: vok=" . var_export($vok2, true) . " msg={$vmsg2}\n";

// 原始文件校验通过
[$vok3, $vmsg3] = DCAI_OfflineActivationService::verifyActivationFile($res['file_json']);
chk('原始文件校验通过', $vok3);
echo "    实际返回: {$vmsg3}\n";

$db->execute('DELETE FROM machine_bindings WHERE license_id = ?', [$lid]);
$db->execute('DELETE FROM offline_activations WHERE license_id = ?', [$lid]);
echo "\n结果: {$pass} 通过 / {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
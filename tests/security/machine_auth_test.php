<?php
// 机器码绑定修复验证：格式校验 / 限额 / 幂等 / 并发防超配（数据库级）
require_once 'D:/xiangmu/shouquan/core/Bootstrap.php';
$db = dcai_db();

// 准备：取一个测试授权码，清空其绑定，设为 machine_limit=3
$lic = $db->queryOne("SELECT * FROM licenses WHERE status = 1 AND machine_limit > 0 ORDER BY id LIMIT 1");
if (!$lic) { echo "无可用测试授权码\n"; exit(1); }
$lid = (int)$lic['id'];
$db->execute('DELETE FROM machine_bindings WHERE license_id = ?', [$lid]);
$db->execute('UPDATE licenses SET machine_limit = 3 WHERE id = ?', [$lid]);
echo "测试授权码 id=$lid machine_limit=3（已清空绑定）\n";

$pass = 0; $fail = 0;
function chk(string $name, bool $ok) { global $pass, $fail; echo ($ok ? "  [PASS] " : "  [FAIL] ") . $name . "\n"; $ok ? $pass++ : $fail++; }

// 1. 合法机器码自动绑定
$mc1 = str_repeat('a', 64);
$r = DCAI_MachineService::authorizeMachine($lid, $mc1, '机器A');
chk('合法机器码绑定通过', $r === null);
chk('绑定落库 status=1', (int)$db->queryValue('SELECT COUNT(*) FROM machine_bindings WHERE license_id=? AND machine_code=? AND status=1', [$lid, $mc1]) === 1);

// 2. 非法格式（大写 hex 会被 strtolower 规范化？先测非 hex 字符）
$r = DCAI_MachineService::authorizeMachine($lid, str_repeat('z', 64), '坏机器');
chk('非hex机器码拒绝(2011)', $r === DCAI_MachineService::ERR_NOT_BOUND);
chk('非法机器码未落库', (int)$db->queryValue('SELECT COUNT(*) FROM machine_bindings WHERE license_id=?', [$lid]) === 1);

// 3. 过短/超长机器码
$r = DCAI_MachineService::authorizeMachine($lid, 'abc', '太短');
chk('过短机器码拒绝', $r === DCAI_MachineService::ERR_NOT_BOUND);
$r = DCAI_MachineService::authorizeMachine($lid, str_repeat('b', 65), '太长');
chk('超长机器码拒绝', $r === DCAI_MachineService::ERR_NOT_BOUND);

// 4. 大小写规范化（hex 大写应视为同一机器）
$mcUp = strtoupper($mc1);
$r = DCAI_MachineService::authorizeMachine($lid, $mcUp, '机器A大写');
chk('大小写规范化后视为已绑定', $r === null);
chk('未产生重复绑定', (int)$db->queryValue('SELECT COUNT(*) FROM machine_bindings WHERE license_id=?', [$lid]) === 1);

// 5. 填满配额（2/3）→ 达上限后新机器拒绝
$mc2 = str_repeat('c', 64);
$r = DCAI_MachineService::authorizeMachine($lid, $mc2, '机器B');
chk('第二台绑定通过', $r === null);
$mc3 = str_repeat('d', 64);
$r = DCAI_MachineService::authorizeMachine($lid, $mc3, '机器C');
chk('第三台绑定通过', $r === null);
chk('已达上限3台', (int)$db->queryValue('SELECT COUNT(*) FROM machine_bindings WHERE license_id=? AND status=1', [$lid]) === 3);
$mc4 = str_repeat('e', 64);
$r = DCAI_MachineService::authorizeMachine($lid, $mc4, '机器D');
chk('超限机器拒绝(2012)', $r === DCAI_MachineService::ERR_LIMIT);
$r = DCAI_MachineService::authorizeMachine($lid, $mc1, '机器A再来');
chk('已绑定机器在满额时仍放行', $r === null);

// 6. 解绑释放配额后新机器可绑定
DCAI_MachineService::unbind($lid, $mc2);
chk('解绑后计数-1', (int)$db->queryValue('SELECT COUNT(*) FROM machine_bindings WHERE license_id=? AND status=1', [$lid]) === 2);
$r = DCAI_MachineService::authorizeMachine($lid, $mc4, '机器D重试');
chk('释放配额后新机器可绑定', $r === null);

// 7. 空机器码放行（Web 场景）
$r = DCAI_MachineService::authorizeMachine($lid, '', '');
chk('空机器码放行', $r === null);

// 8. 并发防超配模拟：预置 2 台后模拟多个并发（串行调用同样应只允许 +1）
$db->execute('DELETE FROM machine_bindings WHERE license_id = ?', [$lid]);
$db->execute('UPDATE licenses SET machine_limit = 3 WHERE id = ?', [$lid]);
// 注意：机器码必须为合法 hex（0-9a-f）——g/h/i 非 hex 会被格式校验正确拦截（2011）
$codes = [str_repeat('f', 64), str_repeat('a1', 32), str_repeat('b2', 32), str_repeat('c3', 32)];
$okCount = 0; $limitCount = 0;
foreach ($codes as $i => $code) {
    $r = DCAI_MachineService::authorizeMachine($lid, $code, '并发' . $i);
    if ($r === null) $okCount++; elseif ($r === DCAI_MachineService::ERR_LIMIT) $limitCount++;
}
chk("并发4台仅前3台成功(成功={$okCount},拒绝={$limitCount})", $okCount === 3 && $limitCount === 1);

// 清理：恢复 machine_limit 与清空测试绑定
$db->execute('UPDATE licenses SET machine_limit = 10 WHERE id = ?', [$lid]);
$db->execute('DELETE FROM machine_bindings WHERE license_id = ?', [$lid]);
echo "\n结果: {$pass} 通过 / {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
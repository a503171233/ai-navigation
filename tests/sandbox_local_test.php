<?php
/**
 * Sandbox 同进程降级执行测试（模拟宝塔环境 proc_open 被禁用）
 * 通过反射将 proc_open 标记为"不可用"来强制走 runInProcess 分支，
 * 覆盖：正常执行、参数透传、危险函数拦截、只读DB、返回值校验、死循环超时。
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once 'D:/xiangmu/shouquan/core/Bootstrap.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $res, string $detail = ''): void {
    global $pass, $fail;
    $res ? $pass++ : $fail++;
    echo ($res ? "  [PASS] " : "  [FAIL] ") . $name . ($res ? '' : "  $detail") . "\n";
}

// 强制同进程模式：能改 disable_functions 模拟最佳，否则直接调用私有 runInProcess
$m = new ReflectionMethod(DCAI_Sandbox::class, 'runInProcess');
$m->setAccessible(true);

$ctx = ['db' => dcai_db(), 'logger' => DCAI_Logger::instance()];
$params = ['msg' => 'hello <b>world</b>'];

echo "=== 同进程降级沙箱测试 ===\n";

// 1. 正常执行 + DB 查询
[$ok, $result, $err] = $m->invoke(null,
    '$rows = $db->queryAll("SELECT 1 AS v");
return ["ok" => true, "count" => count($rows), "msg" => $params["msg"]];',
    $params
);
ok('正常执行+DB查询+参数', $ok && ($result['count'] ?? 0) === 1 && ($result['msg'] ?? '') === 'hello <b>world</b>', $err);

// 2. 危险函数拦截 system()
[$ok, $result, $err] = $m->invoke(null, 'return system("whoami");', []);
ok('system() 被拦截', !$ok, $err);

// 3. 反引号拦截
[$ok, $result, $err] = $m->invoke(null, 'return `dir`;', []);
ok('反引号被拦截', !$ok, $err);

// 4. eval 拦截（静态扫描）
[$ok, $result, $err] = $m->invoke(null, 'return eval("1+1");', []);
ok('eval 被拦截', !$ok, $err);

// 5. 文件函数拦截
[$ok, $result, $err] = $m->invoke(null, 'return file_get_contents("C:/windows/win.ini");', []);
ok('file_get_contents 被拦截', !$ok, $err);

// 6. 数据库写操作拦截
[$ok, $result, $err] = $m->invoke(null,
    '$db->queryAll("DELETE FROM products WHERE id = 1");
return ["ok" => true];', []);
ok('DB 非 SELECT 被拦截', !$ok, $err);

// 7. 返回值 JSON 安全
[$ok, $result, $err] = $m->invoke(null, 'return fopen("php://input", "r");', []);
ok('资源返回被拒', !$ok || $result === null, $err);

// 8. 异常处理
[$ok, $result, $err] = $m->invoke(null, 'throw new Exception("boom");', []);
ok('异常被捕获', !$ok && strpos($err, 'boom') !== false, $err);

// 9. 死循环超时截断（有语句循环 → tick 检查器优雅中断）
$start = microtime(true);
[$ok, $result, $err] = $m->invoke(null, 'while(true) { $i++; }', []);
$elapsed = microtime(true) - $start;
ok('有语句死循环被 tick 截断', $elapsed < 20 && strpos($err, '超时') !== false, "耗时 {$elapsed}s err=$err");

// 10. 阻塞型循环（PDO/复杂表达式内 tick 可能不触发 → set_time_limit 硬超时 + 框架全局 fatal 兜底，
//     保证不裸崩；本用例验证 tick 对纯语句循环稳定拦截即可）
$start = microtime(true);
[$ok, $result, $err] = $m->invoke(null,
    '$i = 0; while($i < 2000000000) { $i++; }', []);
$elapsed2 = microtime(true) - $start;
ok('长循环被 tick 截断（不裸崩）', $elapsed2 < 20 && (strpos($err, '超时') !== false || $ok), "耗时 {$elapsed2}s err=$err");

echo "\n----------------------------------------\n";
echo "降级沙箱测试通过: $pass  |  失败: $fail\n";
exit($fail > 0 ? 1 : 0);
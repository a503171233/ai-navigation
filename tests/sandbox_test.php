<?php
/**
 * 沙箱安全测试
 * 用法: php tests/sandbox_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/core/Bootstrap.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $res, string $detail = ''): void
{
    global $pass, $fail;
    $res ? $pass++ : $fail++;
    echo ($res ? "  ✓ " : "  ✗ ") . $name . ($res ? '' : "  $detail") . "\n";
}

$ctx = ['db' => dcai_db(), 'logger' => DCAI_Logger::instance()];

// 1. 正常代码执行
[$ok, $result, $err] = DCAI_Sandbox::run(
    '$rows = $db->queryAll("SELECT 1 AS v");
return ["ok" => true, "count" => count($rows)];',
    [],
    $ctx
);
ok('正常模块代码执行', $ok && ($result['count'] ?? 0) === 1, $err);

// 2. 参数注入
[$ok, $result, $err] = DCAI_Sandbox::run(
    'return ["echo" => $params["msg"]];',
    ['msg' => 'hello <b>world</b>'],
    $ctx
);
ok('参数透传', $ok && $result['echo'] === 'hello <b>world</b>', $err);

// 3. 禁用系统函数
[$ok, $result, $err] = DCAI_Sandbox::run('return system("whoami");', [], $ctx);
ok('调用 system 被拦截', !$ok && strpos($err, 'system') !== false, $err);

[$ok, $result, $err] = DCAI_Sandbox::run('return shell_exec("ls");', [], $ctx);
ok('调用 shell_exec 被拦截', !$ok, $err);

[$ok, $result, $err] = DCAI_Sandbox::run('return `ls`;', [], $ctx);
ok('反引号命令被拦截', !$ok, $err);

[$ok, $result, $err] = DCAI_Sandbox::run('return eval("1+1");', [], $ctx);
ok('eval 被拦截', !$ok, $err);

[$ok, $result, $err] = DCAI_Sandbox::run('return file_get_contents("/etc/passwd");', [], $ctx);
ok('file_get_contents 被拦截', !$ok, $err);

// 4. 数据库写操作拦截
[$ok, $result, $err] = DCAI_Sandbox::run(
    '$db->queryAll("DELETE FROM products WHERE id = 1");
return ["ok" => true];',
    [],
    $ctx
);
ok('沙箱数据库禁止非 SELECT', !$ok, $err);

// 5. 返回值限制
[$ok, $result, $err] = DCAI_Sandbox::run('return fopen("php://input", "r");', [], $ctx);
ok('返回资源对象被拒绝', !$ok || $result === null, $err);

// 6. 时间限制（死循环被 5s 截断）
$start = microtime(true);
[$ok, $result, $err] = DCAI_Sandbox::run('while(true) { }', [], $ctx);
$elapsed = microtime(true) - $start;
ok('死循环被时间限制截断（≤6s）', $elapsed < 6, "耗时 {$elapsed}s");

echo "\n----------------------------------------\n";
echo "沙箱测试通过: $pass  |  失败: $fail\n";
exit($fail > 0 ? 1 : 0);

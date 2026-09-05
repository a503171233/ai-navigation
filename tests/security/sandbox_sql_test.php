<?php
// 沙箱只读 SQL 加固验证：多语句/写文件/锁/版本注释绕过/写关键字子查询
require_once 'D:/xiangmu/shouquan/core/Bootstrap.php';
require_once 'D:/xiangmu/shouquan/core/Sandbox.php';

$pass = 0; $fail = 0;
function chk(string $name, bool $ok) { global $pass, $fail; echo ($ok ? "  [PASS] " : "  [FAIL] ") . $name . "\n"; $ok ? $pass++ : $fail++; }

$m = new ReflectionClass('DCAI_SandboxDb');
/** @var callable|null $assert */
$assert = null;
// 通过反射调用私有 assertReadOnly
$meth = $m->getMethod('assertReadOnly');
$meth->setAccessible(true);
$db = dcai_db();
$proxy = new DCAI_SandboxDb($db);

function trySql(DCAI_SandboxDb $proxy, string $sql): bool {
    try {
        $rm = new ReflectionMethod('DCAI_SandboxDb', 'assertReadOnly');
        $rm->setAccessible(true);
        $rm->invoke($proxy, $sql);
        return true; // 未抛异常 = 放行
    } catch (Throwable $e) {
        return false; // 被拦截
    }
}

// 1. 正常 SELECT 放行
chk('正常 SELECT 放行', trySql($proxy, 'SELECT * FROM products'));

// 2. INSERT 拦截
chk('INSERT 拦截', !trySql($proxy, 'INSERT INTO products (name) VALUES ("x")'));

// 3. 前导空格 + DELETE 拦截
chk('前导空白 + DELETE 拦截', !trySql($proxy, '   DELETE FROM products'));

// 4. 版本注释伪装 SELECT 拦截
chk('注释伪装 DROP 拦截', !trySql($proxy, '/*!50000 DROP TABLE products */'));

// 5. 多语句拦截
chk('多语句 SELECT;DROP 拦截', !trySql($proxy, 'SELECT * FROM products; DROP TABLE products'));

// 6. INTO OUTFILE 拦截
chk('INTO OUTFILE 拦截', !trySql($proxy, "SELECT * INTO OUTFILE '/tmp/x' FROM products"));

// 7. FOR UPDATE 拦截
chk('FOR UPDATE 拦截', !trySql($proxy, 'SELECT * FROM products WHERE id=1 FOR UPDATE'));

// 8. LOCK IN SHARE MODE 拦截
chk('LOCK IN SHARE MODE 拦截', !trySql($proxy, 'SELECT * FROM products LOCK IN SHARE MODE'));

// 9. 子查询内写关键字拦截（SELECT (DELETE ...) 花式绕过）
chk('子查询写关键字拦截', !trySql($proxy, "SELECT (SELECT COUNT(*) FROM (SELECT 1) t), (DELETE FROM products)"));

// 10. WITH 前缀拦截（防未来 CTE 内嵌写）
chk('WITH 前缀拦截', !trySql($proxy, 'WITH x AS (SELECT 1) SELECT * FROM x'));

// 11. 实际查询方法也走校验
try {
    $proxy->queryAll('SELECT * FROM products LIMIT 1');
    chk('queryAll 正常 SELECT 可用', true);
} catch (Throwable $e) {
    chk('queryAll 正常 SELECT 可用', false);
}
try {
    $proxy->queryAll('UPDATE products SET name="x"');
    chk('queryAll 写语句被拒', false);
} catch (Throwable $e) {
    chk('queryAll 写语句被拒', true);
}

echo "\n结果: {$pass} 通过 / {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
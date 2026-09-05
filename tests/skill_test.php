<?php
/**
 * 技能（Skill）冒烟测试：验证「授权系统为核心、被授权站点为壳」的远程技能调用链路
 * 依赖：先执行 php install/migrate_v1.2.php 与 php tests/seed.php
 * 用法: php tests/skill_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? '127.0.0.1';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once dirname(__DIR__) . '/core/Bootstrap.php';
require_once dirname(__DIR__) . '/sdk/dcai_client.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $res, string $detail = ''): void
{
    global $pass, $fail;
    $res ? $pass++ : $fail++;
    echo ($res ? "  ✓ " : "  ✗ ") . $name . ($res ? '' : "  $detail") . "\n";
}

$db = dcai_db();

// 清 SDK 缓存
$dcai = new DCAI_Client();
$cacheDir = $dcai->config()['cache_dir'];
@array_map('unlink', glob($cacheDir . '*.cache'));

echo "=== 技能·产品级（access_model=0）===\n";
$skills = $dcai->getSkills();
$hasGreet = false;
foreach ($skills as $s) {
    if ($s['skill_code'] === 'demo_greet') {
        $hasGreet = true;
        $fns = array_column($s['functions'], 'function_code');
        ok('技能 demo_greet 可见且含 greet 函数', in_array('greet', $fns, true), json_encode($fns));
        $loc = filesize(__FILE__);
        ok('技能返回已脱敏（不含核心代码字段）', !isset($s['functions'][0]['code']), '泄漏了 code 字段');
        break;
    }
}
ok('getSkills 能获取产品级技能列表', $hasGreet, json_encode($skills));

try {
    $r = $dcai->callSkill('demo_greet', 'greet', ['name' => '技能测试']);
    $okCall = is_array($r) && isset($r['hello']) && strpos((string)$r['hello'], '技能测试') !== false;
    ok('callSkill(greet) 返回核心逻辑结果', $okCall, json_encode($r));
    // 日志应已落库（成功）
    $logOk = (int)$db->queryValue("SELECT COUNT(*) FROM skill_invoke_logs WHERE skill_id = (SELECT id FROM skills WHERE skill_code='demo_greet') AND status = 1") > 0;
    ok('技能调用成功日志已落库', $logOk);
} catch (Throwable $e) {
    ok('callSkill(greet) 返回核心逻辑结果', false, $e->getMessage());
}

// 参数校验：name 超长应失败
try {
    $dcai->callSkill('demo_greet', 'greet', ['name' => str_repeat('A', 99)]);
    ok('参数校验拦截超长 name', false, '未被拦截');
} catch (Throwable $e) {
    ok('参数校验拦截超长 name', stripos($e->getMessage(), '校验') !== false, $e->getMessage());
}

// 不存在的函数应失败
try {
    $dcai->callSkill('demo_greet', 'nope');
    ok('调用不存在函数被拒', false);
} catch (Throwable $e) {
    ok('调用不存在函数被拒', true);
}

echo "=== 技能·按授权码（access_model=1）===\n";
$skillGated = $db->queryOne("SELECT id FROM skills WHERE skill_code='demo_license_skill'");
$inst = $db->queryOne("SELECT * FROM instances WHERE product_id = (SELECT id FROM products WHERE product_code='shop_v2') ORDER BY id DESC LIMIT 1");
$licenseId = (int)$inst['license_id'];

// 先撤销任何既有授权
$db->delete('skill_grants', 'skill_id = ?', [(int)$skillGated['id']]);

try {
    $dcai->callSkill('demo_license_skill', 'secret', ['k' => 'A']);
    ok('未授权调用按码技能被拒', false, '竟然成功了');
} catch (Throwable $e) {
    ok('未授权调用按码技能被拒', stripos($e->getMessage(), '无权') !== false, $e->getMessage());
}

// 通过后台同款服务授予授权码，验证被授权站点即可调用
$grantOk = DCAI_SkillService::setGrant((int)$skillGated['id'], $licenseId, 1, 1);
ok('后台授权接口 setGrant 生效', $grantOk);

try {
    $r = $dcai->callSkill('demo_license_skill', 'secret', ['k' => 'B']);
    $okGrant = is_array($r) && isset($r['secret']);
    ok('授权后调用按码技能成功', $okGrant, json_encode($r));
} catch (Throwable $e) {
    ok('授权后调用按码技能成功', false, $e->getMessage());
}

// 撤销授权应恢复拒绝
$db->update('skill_grants', ['status' => 0], 'skill_id = ?', [(int)$skillGated['id']]);
try {
    $dcai->callSkill('demo_license_skill', 'secret', ['k' => 'C']);
    ok('撤销授权后再次调用被拒', false);
} catch (Throwable $e) {
    ok('撤销授权后再次调用被拒', true);
}

echo "\n----------------------------------------\n";
echo "技能测试通过: $pass  |  失败: $fail\n";
exit($fail > 0 ? 1 : 0);
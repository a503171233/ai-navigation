<?php
/**
 * SDK 集成测试：模拟被授权程序完整使用流程
 * 用法: php tests/sdk_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

// CLI 环境下注入模拟 Web 环境
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once dirname(__DIR__) . '/sdk/dcai_client.php';
require_once dirname(__DIR__) . '/sdk/dcai_updater.php';

$pass = 0;
$fail = 0;
function ok(string $name, bool $res, string $detail = ''): void
{
    global $pass, $fail;
    $res ? $pass++ : $fail++;
    echo ($res ? "  ✓ " : "  ✗ ") . $name . ($res ? '' : "  $detail") . "\n";
}

$dcai = new DCAI_Client();
$cacheDir = $dcai->config()['cache_dir'];
@array_map('unlink', glob($cacheDir . '*.cache'));

echo "=== SDK verify() ===\n";
$v1 = $dcai->verify();
ok('首次验证通过（发起远程请求）', $v1);
$info = $dcai->getVerifiedInfo();
ok('getVerifiedInfo 返回授权信息', is_array($info) && !empty($info['expire_at']));

$v2 = $dcai->verify();
ok('二次验证命中本地缓存令牌（不发请求）', $v2);
ok('本地令牌 RSA 验签通过', !empty($dcai->getVerifiedInfo()['token']));

echo "=== SDK registerInstance + heartbeat ===\n";
$reg = $dcai->registerInstance();
ok('实例注册成功', !empty($reg['instance_id']) && !empty($reg['instance_token']), json_encode($reg));
$hb = $dcai->heartbeat();
ok('心跳成功', !empty($hb['ok']) && ($hb['code'] ?? -1) === 0, json_encode($hb));

echo "=== SDK runHooks（心跳+命令轮询）===\n";
$dcai->runHooks();
ok('runHooks 正常执行', true);

echo "=== SDK 弹窗 ===\n";
$popups = $dcai->getPopups();
$okPopups = is_array($popups) && ($popups['code'] ?? -1) === 0;
ok('获取弹窗列表', $okPopups, json_encode($popups));
if (!empty($popups['data']['popups'])) {
    $okReport = $dcai->reportPopupShown((int)$popups['data']['popups'][0]['id']);
    ok('弹窗展示上报', $okReport);
}

echo "=== SDK 远程模块 ===\n";
try {
    $result = $dcai->callModule('demo_hello', ['name' => 'SDK测试']);
    ok('callModule 成功', isset($result['hello']), json_encode($result));
} catch (Throwable $e) {
    ok('callModule 成功', false, $e->getMessage());
}

echo "=== SDK 更新检测 ===\n";
$update = $dcai->checkUpdate();
ok('checkUpdate 返回（无更新时 null）', $update === null || is_array($update), json_encode($update));

echo "=== SDK 自定义命令回调 ===\n";
$dcai->onCommand('my_action', function (array $payload) {
    return '自定义命令已执行: ' . ($payload['msg'] ?? '');
});
ok('注册自定义命令回调', true);

echo "\n----------------------------------------\n";
echo "SDK 测试通过: $pass  |  失败: $fail\n";
exit($fail > 0 ? 1 : 0);

<?php
$pageTitle = '系统设置';
$activeMenu = 'setting';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('setting');

$db = dcai_db();

// 保存基础设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    DCAI_Csrf::check();
    DCAI_Settings::set('site_name', trim($_POST['site_name'] ?? ''));
    DCAI_Settings::set('verify_ttl', (int)($_POST['verify_ttl'] ?? 3600));
    DCAI_Settings::set('heartbeat_threshold', max(30, (int)($_POST['heartbeat_threshold'] ?? 180)));
    DCAI_Settings::set('rate_verify', (int)($_POST['rate_verify'] ?? 30));
    DCAI_Settings::set('rate_api', (int)($_POST['rate_api'] ?? 120));
    DCAI_Admin::opLog('保存系统设置');
    dcai_flash('success', '设置已保存');
    header('Location: ' . DCAI_Admin::adminUrl('settings.php'));
    exit;
}

// 保存通知配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notify') {
    DCAI_Csrf::check();
    DCAI_Settings::set('notify_enabled', (int)($_POST['notify_enabled'] ?? 0));
    DCAI_Settings::set('notify_webhook', trim($_POST['notify_webhook'] ?? ''));
    DCAI_Settings::set('notify_email', trim($_POST['notify_email'] ?? ''));
    DCAI_Admin::opLog('保存通知配置');
    dcai_flash('success', '通知配置已保存');
    header('Location: ' . DCAI_Admin::adminUrl('settings.php'));
    exit;
}

// 测试通知
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_notify') {
    DCAI_Csrf::check();
    [$ok, $msg] = DCAI_Notify::sendTest();
    dcai_flash($ok ? 'success' : 'danger', $msg);
    header('Location: ' . DCAI_Admin::adminUrl('settings.php'));
    exit;
}

// 保存商城配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_store') {
    DCAI_Csrf::check();
    DCAI_Settings::set('store_enabled', (int)($_POST['store_enabled'] ?? 0));
    DCAI_Settings::set('store_epay_gateway', rtrim(trim($_POST['store_epay_gateway'] ?? ''), '/'));
    DCAI_Settings::set('store_epay_pid', trim($_POST['store_epay_pid'] ?? ''));
    DCAI_Settings::set('store_epay_key', trim($_POST['store_epay_key'] ?? ''));
    DCAI_Settings::set('store_manual_account', trim($_POST['store_manual_account'] ?? ''));
    DCAI_Admin::opLog('保存商城配置');
    dcai_flash('success', '商城配置已保存');
    header('Location: ' . DCAI_Admin::adminUrl('settings.php'));
    exit;
}

// 重置 app_secret
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_secret') {
    DCAI_Csrf::check();
    $cfgFile = DCAI_CONFIG_FILE;
    $cfg = dcai_config();
    $cfg['sdk']['app_secret'] = bin2hex(random_bytes(32));
    $content = '<?php ' . PHP_EOL . '// 由后台自动生成 - ' . dcai_now() . PHP_EOL . 'return ' . var_export($cfg, true) . ';' . PHP_EOL;
    if (@file_put_contents($cfgFile, $content, LOCK_EX) !== false) {
        DCAI_Admin::opLog('重置 app_secret');
        dcai_flash('success', 'app_secret 已重置，请同步更新所有 SDK 配置');
    } else {
        dcai_flash('danger', '写入配置失败，请检查 config/ 权限');
    }
    header('Location: ' . DCAI_Admin::adminUrl('settings.php'));
    exit;
}

// 轮换 RSA 密钥
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rotate_rsa') {
    DCAI_Csrf::check();
    $options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
    if (PHP_OS_FAMILY === 'Windows' || !is_file('/etc/ssl/openssl.cnf')) {
        foreach ([dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', dirname(PHP_BINARY) . '/ssl/openssl.cnf'] as $c) {
            if (is_file($c)) { $options['config'] = $c; break; }
        }
    }
    $rsa = openssl_pkey_new($options);
    if (!$rsa) {
        dcai_flash('danger', 'RSA 密钥生成失败: ' . openssl_error_string());
    } else {
        openssl_pkey_export($rsa, $priv, null, $options);
        $pub = openssl_pkey_get_details($rsa)['key'];
        $cfg = dcai_config();
        $cfg['security']['rsa_private_key'] = $priv;
        $cfg['security']['rsa_public_key'] = $pub;
        $content = '<?php ' . PHP_EOL . '// 由后台自动生成 - ' . dcai_now() . PHP_EOL . 'return ' . var_export($cfg, true) . ';' . PHP_EOL;
        if (@file_put_contents(DCAI_CONFIG_FILE, $content, LOCK_EX) !== false) {
            DCAI_Admin::opLog('轮换 RSA 密钥');
            dcai_flash('warning', 'RSA 密钥已轮换。请立即同步更新所有 SDK 内置公钥，旧验证令牌将失效。');
        } else {
            dcai_flash('danger', '写入配置失败');
        }
    }
    header('Location: ' . DCAI_Admin::adminUrl('settings.php'));
    exit;
}

$settings = DCAI_Settings::all();
$appSecret = (string)dcai_config('sdk.app_secret', '');
$publicKey = (string)dcai_config('security.rsa_public_key', '');
$baseUrl = (string)dcai_config('app.base_url', '');
$storeEnabled = (int)($settings['store_enabled'] ?? dcai_config('store.enabled', 0));

require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-title">基础设置</div>
    <form method="post" style="max-width:640px;">
        <?php echo DCAI_Csrf::field(); ?>
        <input type="hidden" name="action" value="save">
        <div class="form-grid">
            <div class="form-row"><label>站点名称</label><input type="text" name="site_name" value="<?php echo DCAI_Util::e($settings['site_name'] ?? dcai_config('app.name', 'DCAI 授权系统')); ?>"></div>
            <div class="form-row"><label>验证令牌 TTL（秒）</label><input type="number" name="verify_ttl" value="<?php echo (int)($settings['verify_ttl'] ?? dcai_config('security.verify_ttl', 3600)); ?>" min="60"></div>
            <div class="form-row"><label>离线判定阈值（秒）</label><input type="number" name="heartbeat_threshold" value="<?php echo (int)($settings['heartbeat_threshold'] ?? dcai_config('security.heartbeat_threshold', 180)); ?>" min="30"></div>
            <div class="form-row"><label>verify 限流（次/分钟）</label><input type="number" name="rate_verify" value="<?php echo (int)($settings['rate_verify'] ?? dcai_config('security.rate_limit.verify', 30)); ?>" min="1"></div>
            <div class="form-row"><label>API 限流（次/分钟/实例）</label><input type="number" name="rate_api" value="<?php echo (int)($settings['rate_api'] ?? dcai_config('security.rate_limit.api', 120)); ?>" min="1"></div>
            <div class="form-row"><label>系统访问地址</label><input type="text" value="<?php echo DCAI_Util::e($baseUrl); ?>" disabled><div class="help-text">修改请编辑 config/config.php</div></div>
        </div>
        <button type="submit" class="btn btn-primary">保存设置</button>
    </form>
</div>

<div class="card">
    <div class="card-title">商城配置（对外授权商城）</div>
    <form method="post" style="max-width:640px;">
        <?php echo DCAI_Csrf::field(); ?>
        <input type="hidden" name="action" value="save_store">
        <div class="form-grid">
            <div class="form-row"><label>开启商城前台</label>
                <select name="store_enabled">
                    <option value="0" <?php echo $storeEnabled ? '' : 'selected'; ?>>关闭（根路径跳转后台）</option>
                    <option value="1" <?php echo $storeEnabled ? 'selected' : ''; ?>>开启（根路径跳转商城 /shop/home）</option>
                </select>
            </div>
            <div class="form-row full"><label>易支付网关地址</label><input type="text" name="store_epay_gateway" value="<?php echo DCAI_Util::e($settings['store_epay_gateway'] ?? ''); ?>" placeholder="https://pay.example.com"><div class="help-text">留空则商城只提供「人工转账」支付方式</div></div>
            <div class="form-row"><label>易支付商户 ID (pid)</label><input type="text" name="store_epay_pid" value="<?php echo DCAI_Util::e($settings['store_epay_pid'] ?? ''); ?>"></div>
            <div class="form-row"><label>易支付商户密钥 (key)</label><input type="text" name="store_epay_key" value="<?php echo DCAI_Util::e($settings['store_epay_key'] ?? ''); ?>"></div>
            <div class="form-row full"><label>人工转账收款账号说明</label><textarea name="store_manual_account" rows="2"><?php echo DCAI_Util::e($settings['store_manual_account'] ?? ''); ?></textarea><div class="help-text">显示在人工支付页，例如：支付宝 138****0000 / 微信：xxx</div></div>
        </div>
        <button type="submit" class="btn btn-primary">保存商城配置</button>
        <a class="btn btn-outline" href="<?php echo DCAI_Admin::adminUrl('../shop/home'); ?>" target="_blank">查看商城前台</a>
    </form>
</div>

<div class="card">
    <div class="card-title">通知配置（到期提醒 / 离线告警）</div>
    <form method="post" style="max-width:640px;">
        <?php echo DCAI_Csrf::field(); ?>
        <input type="hidden" name="action" value="save_notify">
        <div class="form-grid">
            <div class="form-row"><label>启用通知</label>
                <select name="notify_enabled">
                    <option value="0" <?php echo (int)($settings['notify_enabled'] ?? 0) ? '' : 'selected'; ?>>关闭</option>
                    <option value="1" <?php echo (int)($settings['notify_enabled'] ?? 0) ? 'selected' : ''; ?>>启用</option>
                </select>
            </div>
            <div class="form-row full"><label>Webhook 地址</label><input type="text" name="notify_webhook" value="<?php echo DCAI_Util::e($settings['notify_webhook'] ?? ''); ?>" placeholder="Server酱/钉钉/企业微信机器人 URL"><div class="help-text">POST JSON：{title, content}。到期提醒每 7 天推一次，离线批量告警每 6 小时最多一次</div></div>
            <div class="form-row"><label>接收邮箱</label><input type="email" name="notify_email" value="<?php echo DCAI_Util::e($settings['notify_email'] ?? ''); ?>" placeholder="admin@example.com"><div class="help-text">需服务器 PHP 支持 mail()/SMTP</div></div>
        </div>
        <button type="submit" class="btn btn-primary">保存通知配置</button>
        <button type="submit" class="btn btn-outline" formaction="<?php echo DCAI_Admin::adminUrl('settings.php'); ?>" name="action" value="test_notify" onclick="return confirm('发送一条测试通知？');">发送测试通知</button>
    </form>
</div>

<div class="card">
    <div class="card-title">SDK 密钥</div>
    <div class="form-row">
        <label>app_secret（SDK 首次验证签名密钥）</label>
        <input type="text" class="mono" value="<?php echo DCAI_Util::e($appSecret); ?>" readonly onclick="this.select()">
        <div class="help-text">该密钥内置于所有被授权程序的 SDK 配置中，重置后旧配置将失效</div>
    </div>
    <div class="actions">
        <form method="post" style="display:inline;">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="reset_secret">
            <button class="btn btn-danger-outline" data-confirm-danger="确认重置 app_secret？所有已分发 SDK 需同步更新。">重置 app_secret</button>
        </form>
        <form method="post" style="display:inline;">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="rotate_rsa">
            <button class="btn btn-danger-outline" data-confirm-danger="确认轮换 RSA 密钥？所有 SDK 内置公钥需同步更新，未到期验证令牌将失效。">轮换 RSA 密钥</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-title">RSA 公钥（SDK 内置验签公钥）</div>
    <textarea class="code-area" rows="8" readonly onclick="this.select()"><?php echo DCAI_Util::e($publicKey); ?></textarea>
    <div class="help-text">随安装包分发到 SDK 配置中，用于本地验证授权令牌</div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
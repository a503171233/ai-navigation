<?php
/**
 * DCAI 授权系统 安装向导 V1.1
 * 流程：环境检测 → 数据库配置(可测试连接) → 导入表结构 → 创建超级管理员 → 生成密钥写入配置
 * 支持两种访问方式：
 *   1) 站点根目录=项目根目录：  /install/index.php
 *   2) 站点根目录=public/：     /install/index.php（public/install/index.php 转发到本文件）
 * 安装完成后请删除 install/ 与 public/install/ 目录。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

$ROOT = dirname(__DIR__);
$CONFIG_FILE = $ROOT . '/config/config.php';
$STEP = (int)($_GET['step'] ?? 1);
$errors = [];
$notice = [];

// ---------- 存储目录骨架（自动创建，保证可写） ----------
$storageDirs = [
    '/storage', '/storage/backups', '/storage/logs', '/storage/cache',
    '/storage/cache/nonces', '/storage/cache/ratelimit', '/storage/cache/sandbox',
    '/storage/cache/sessions', '/storage/packages', '/storage/updates', '/storage/system_updates',
];
foreach ($storageDirs as $d) {
    if (!is_dir($ROOT . $d)) {
        @mkdir($ROOT . $d, 0775, true);
    }
}

// ---------- 已安装检测 ----------
$installed = false;
if (is_file($CONFIG_FILE)) {
    $cfg = require $CONFIG_FILE;
    $installed = !empty($cfg['installed']);
    if ($installed) {
        $notice[] = '系统已安装。如需重新安装，请删除 config/config.php 后刷新本页。';
    }
}

// ---------- 数据库升级（已安装实例） ----------
$upgradeMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upgrade_db') {
    if (is_file($CONFIG_FILE)) {
        $cfg = require $CONFIG_FILE;
        if (!empty($cfg['installed']) && !empty($cfg['db'])) {
            try {
                $db = $cfg['db'];
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
                $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $schema = file_get_contents($ROOT . '/install/schema.sql');
                $pdo->exec($schema);
                if (is_file($ROOT . '/install/migrate_v1.1.php')) {
                    require $ROOT . '/install/migrate_v1.1.php';
                }
                $upgradeMsg = '数据库结构升级完成（幂等执行全部 CREATE TABLE 与 V1.1 增量迁移）。';
            } catch (Throwable $e) {
                $upgradeMsg = '数据库升级失败：' . $e->getMessage();
            }
        } else {
            $upgradeMsg = 'config.php 中未找到有效的数据库配置。';
        }
    } else {
        $upgradeMsg = '未找到 config.php，无法执行升级。';
    }
}

// ---------- 数据库连通性测试（AJAX） ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_db') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $host = trim($_POST['db_host'] ?? '127.0.0.1');
        $port = (int)($_POST['db_port'] ?? 3306);
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = (string)($_POST['db_pass'] ?? '');
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $okCreate = false;
        if ($name !== '') {
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $name) . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $okCreate = true;
            } catch (Throwable $e2) {
                $okCreate = false;
            }
            if (!$okCreate) {
                $pdo->exec("USE `" . str_replace('`', '', $name) . "`");
            }
        }
        echo json_encode(['ok' => true, 'msg' => '连接成功' . ($name !== '' ? ($okCreate ? '，数据库已创建/存在' : '，数据库已存在') : '')], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => '连接失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------- 环境检测 ----------
$checks = [
    ['PHP 版本 >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION],
    ['扩展 pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '已加载' : '未加载'],
    ['扩展 openssl', extension_loaded('openssl'), extension_loaded('openssl') ? '已加载' : '未加载'],
    ['扩展 mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? '已加载' : '未加载'],
    ['扩展 fileinfo', extension_loaded('fileinfo'), extension_loaded('fileinfo') ? '已加载' : '未加载'],
    ['扩展 zip', extension_loaded('zip'), extension_loaded('zip') ? '已加载' : '未加载'],
    ['扩展 curl', extension_loaded('curl'), extension_loaded('curl') ? '已加载' : '未加载'],
    ['storage 目录可写', is_writable($ROOT . '/storage'), is_writable($ROOT . '/storage') ? '可写' : '不可写'],
    ['config 目录可写', is_writable($ROOT . '/config'), is_writable($ROOT . '/config') ? '可写' : '不可写'],
    ['config.php 不存在(全新安装)', !$installed, $installed ? '已存在，将走升级模式' : '可安装'],
];

// ---------- 执行安装 ----------
$dbConfig = null;
$adminCreated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    $db = [
        'host' => trim($_POST['db_host'] ?? '127.0.0.1'),
        'port' => (int)($_POST['db_port'] ?? 3306),
        'name' => trim($_POST['db_name'] ?? 'dcai_auth'),
        'user' => trim($_POST['db_user'] ?? ''),
        'pass' => (string)($_POST['db_pass'] ?? ''),
        'charset' => 'utf8mb4',
    ];
    $adminUsername = trim($_POST['admin_username'] ?? 'admin');
    $adminNickname = trim($_POST['admin_nickname'] ?? '管理员');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $adminConfirm = (string)($_POST['admin_confirm'] ?? '');
    $baseUrl = rtrim(trim($_POST['base_url'] ?? ''), '/');

    if ($db['name'] === '' || $db['user'] === '' || $adminUsername === '' || $adminPassword === '') {
        $errors[] = '请完整填写数据库与管理员信息';
    } elseif (strlen($adminPassword) < 6) {
        $errors[] = '管理员密码至少 6 位';
    } elseif ($adminPassword !== $adminConfirm) {
        $errors[] = '两次输入的密码不一致';
    } else {
        try {
            // 先连接（不指定库），尝试建库；无权限则尝试 USE 已存在库
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']);
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $dbName = str_replace('`', '', $db['name']);
            $created = false;
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $created = true;
            } catch (Throwable $e) {
                $created = false;
            }
            $pdo->exec("USE `$dbName`");

            // 导入 schema（幂等）
            $schema = file_get_contents($ROOT . '/install/schema.sql');
            $pdo->exec($schema);

            // 创建超级管理员
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, nickname, email, role, status, permissions, twofa_secret, last_login_at, last_login_ip, created_at, updated_at)
                                   VALUES (?, ?, ?, ?, 1, 1, '', '', NULL, '', ?, ?)");
            $stmt->execute([
                $adminUsername,
                password_hash($adminPassword, PASSWORD_BCRYPT),
                $adminNickname !== '' ? $adminNickname : $adminUsername,
                $adminEmail,
                $now, $now,
            ]);

            // 生成 RSA 密钥
            $opensslConfig = null;
            foreach ([dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', dirname(PHP_BINARY) . '/ssl/openssl.cnf'] as $c) {
                if (is_file($c)) { $opensslConfig = $c; break; }
            }
            $keyOptions = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
            if ($opensslConfig) { $keyOptions['config'] = $opensslConfig; }
            $rsa = openssl_pkey_new($keyOptions);
            if (!$rsa) {
                throw new RuntimeException('RSA 密钥生成失败: ' . openssl_error_string());
            }
            openssl_pkey_export($rsa, $privKey, null, $keyOptions);
            $details = openssl_pkey_get_details($rsa);
            $pubKey = $details['key'];

            if ($baseUrl === '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }

            // 生成完整配置（含 V1.1 商城/通知）
            $configContent = "<?php\n"
                . "/** 由安装向导自动生成 - " . date('Y-m-d H:i:s') . " 请勿修改本文件中的密钥 */\n"
                . "return [\n"
                . "  'installed' => true,\n"
                . "  'db' => " . var_export($db, true) . ",\n"
                . "  'app' => [\n"
                . "    'name' => 'DCAI 授权系统',\n"
                . "    'version' => '1.1.1',\n"
                . "    'base_url' => " . var_export($baseUrl, true) . ",\n"
                . "    'timezone' => 'Asia/Shanghai',\n"
                . "    'debug' => false,\n"
                . "  ],\n"
                . "  'security' => [\n"
                . "    'rsa_private_key' => " . var_export($privKey, true) . ",\n"
                . "    'rsa_public_key' => " . var_export($pubKey, true) . ",\n"
                . "    'aes_key' => " . var_export(bin2hex(random_bytes(32)), true) . ",\n"
                . "    'verify_ttl' => 3600,\n"
                . "    'heartbeat_threshold' => 180,\n"
                . "    'login_max_fail' => 5,\n"
                . "    'login_lock_minutes' => 15,\n"
                . "    'rate_limit' => ['verify' => 30, 'api' => 120],\n"
                . "    'timestamp_max_diff' => 300,\n"
                . "    'nonce_ttl' => 300,\n"
                . "    'trusted_proxies' => [],\n"
                . "    'download_secret' => " . var_export(bin2hex(random_bytes(32)), true) . ",\n"
                . "  ],\n"
                . "  'storage' => [\n"
                . "    'path' => dirname(__DIR__) . '/storage',\n"
                . "    'max_package_size' => 209715200,\n"
                . "    'allowed_ext' => ['zip'],\n"
                . "  ],\n"
                . "  'sdk' => [\n"
                . "    'app_secret' => " . var_export(bin2hex(random_bytes(32)), true) . ",\n"
                . "    'heartbeat_interval' => 60,\n"
                . "  ],\n"
                . "  'notify' => [\n"
                . "    'enabled' => 0,\n"
                . "    'webhook' => '',\n"
                . "    'email' => '',\n"
                . "  ],\n"
                . "  'store' => [\n"
                . "    'enabled' => 0,\n"
                . "    'epay_gateway' => '',\n"
                . "    'epay_pid' => 0,\n"
                . "    'epay_key' => '',\n"
                . "    'manual_account' => '',\n"
                . "  ],\n"
                . "  'log' => [\n"
                . "    'level' => 'info',\n"
                . "    'file' => dirname(__DIR__) . '/storage/logs/app.log',\n"
                . "    'retention_days' => 90,\n"
                . "  ],\n"
                . "  'update_source' => [\n"
                . "    'enabled' => 0,\n"
                . "    'manifest' => '',\n"
                . "    'auth_token' => '',\n"
                . "    'timeout' => 15,\n"
                . "  ],\n"
                . "];\n";

            if (@file_put_contents($CONFIG_FILE, $configContent, LOCK_EX) === false) {
                throw new RuntimeException('写入 config.php 失败，请检查 config/ 目录权限');
            }
            @chmod($CONFIG_FILE, 0600);

            $dbConfig = $db;
            $adminCreated = true;
        } catch (Throwable $e) {
            $errors[] = '安装失败：' . $e->getMessage();
        }
    }
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$autoBase = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装向导 - DCAI 授权系统</title>
<style>
:root { --primary:#4f46e5; --success:#16a34a; --danger:#dc2626; --bg:#f1f5f9; --card:#fff; --text:#1f2937; --muted:#6b7280; --border:#e5e7eb; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:-apple-system,"Segoe UI","Microsoft YaHei",sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding:28px 16px; }
.wizard { max-width:720px; margin:0 auto; }
.card { background:var(--card); border-radius:14px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:28px; }
h2 { font-size:22px; margin-bottom:6px; }
.sub { color:var(--muted); font-size:13.5px; margin-bottom:20px; }
.steps { display:flex; gap:8px; margin-bottom:24px; }
.step-item { flex:1; text-align:center; padding:10px; border-radius:8px; background:#e8edf5; color:#5b6b7f; font-size:13px; font-weight:500; }
.step-item.active { background:var(--primary); color:#fff; }
.step-item.done { background:#dcfce7; color:#166534; }
.alert { padding:12px 14px; border-radius:8px; margin-bottom:12px; font-size:13.5px; line-height:1.6; }
.alert-success { background:#f0fdf4; color:#14532d; border:1px solid #bbf7d0; }
.alert-danger { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
.alert-warning { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
.check-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid var(--border); font-size:13.5px; }
.check-row .ok { color:var(--success); font-weight:600; }
.check-row .fail { color:var(--danger); font-weight:600; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-row { display:flex; flex-direction:column; gap:5px; }
.form-row.full { grid-column:1/-1; }
.form-row label { font-size:13px; color:#374151; font-weight:500; }
.form-row input { padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; }
.form-row input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(79,70,229,.12); }
.form-row .help { font-size:12px; color:var(--muted); }
.section-title { font-size:15px; font-weight:600; margin:20px 0 12px; padding-top:16px; border-top:1px solid var(--border); }
.btn { display:inline-block; padding:10px 18px; border-radius:8px; font-size:14px; border:1px solid transparent; cursor:pointer; text-decoration:none; text-align:center; transition:opacity .15s; }
.btn:disabled { opacity:.5; cursor:not-allowed; }
.btn-primary { background:var(--primary); color:#fff; }
.btn-primary:hover { opacity:.9; }
.btn-success { background:var(--success); color:#fff; }
.btn-outline { background:transparent; color:var(--primary); border-color:var(--primary); }
.btn-outline:hover { background:#eef2ff; }
.btn-block { display:block; width:100%; }
.btn-row { display:flex; gap:10px; margin-top:18px; flex-wrap:wrap; }
.spinner { display:inline-block; width:14px; height:14px; border:2px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; vertical-align:-2px; margin-right:6px; }
@keyframes spin { to { transform:rotate(360deg); } }
.db-test-result { font-size:13px; margin-top:8px; }
.db-test-result.ok { color:var(--success); }
.db-test-result.fail { color:var(--danger); }
.mono { font-family:Consolas,monospace; font-size:12.5px; background:#f8fafc; padding:2px 5px; border-radius:4px; }
code { font-family:Consolas,monospace; background:#f8fafc; padding:1px 5px; border-radius:4px; }
ol { padding-left:18px; line-height:2; }
</style>
</head>
<body>
<div class="wizard">
    <div class="card">
        <h2>🔐 DCAI 授权系统 安装向导</h2>
        <p class="sub">配置数据库并创建超级管理员，安装完成后请删除 <code>install/</code> 目录。</p>

        <div class="steps">
            <div class="step-item <?php echo $STEP === 1 ? 'active' : 'done'; ?>">环境检测</div>
            <div class="step-item <?php echo $STEP === 2 ? 'active' : ''; ?>">数据库与管理员</div>
            <div class="step-item <?php echo $adminCreated ? 'done' : ''; ?>">完成</div>
        </div>

        <?php foreach ($notice as $n): ?>
            <div class="alert alert-warning"><?php echo e($n); ?></div>
        <?php endforeach; ?>
        <?php if ($upgradeMsg !== ''): ?>
            <div class="alert alert-<?php echo strpos($upgradeMsg, '失败') !== false ? 'danger' : 'success'; ?>"><?php echo e($upgradeMsg); ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger"><?php echo e($err); ?></div>
        <?php endforeach; ?>

        <?php if ($adminCreated && $dbConfig): ?>
            <div class="alert alert-success">
                ✅ <strong>安装成功！</strong><br>
                数据库：<code><?php echo e($dbConfig['name']); ?></code> @ <code><?php echo e($dbConfig['host'] . ':' . $dbConfig['port']); ?></code><br>
                管理员账号：<code><?php echo e($_POST['admin_username'] ?? 'admin'); ?></code>
            </div>
            <div class="alert alert-warning">
                ⚠️ 出于安全考虑，请立即完成以下步骤：
                <ol>
                    <li>删除 <code>install/</code> 与 <code>public/install/</code> 目录</li>
                    <li>确认 <code>config/config.php</code> 权限为 600（含 RSA 私钥与 app_secret）</li>
                    <li>若站点根目录指向 <code>public/</code>，请配置伪静态：<code>try_files $uri $uri/ /index.php?$query_string;</code></li>
                    <li>配置定时任务：<code>php /www/wwwroot/dcai_auth/cron/offline.php</code>（每分钟）</li>
                    <li>前往后台登录并创建产品/授权码</li>
                </ol>
            </div>
            <div class="btn-row">
                <a href="../admin/login.php" class="btn btn-primary">进入后台登录 →</a>
                <a href="../shop/home" class="btn btn-outline">商城首页</a>
            </div>

        <?php elseif ($STEP === 1): ?>
            <div class="section-title">运行环境检测</div>
            <?php $allPass = true; foreach ($checks as [$name, $pass, $detail]): $allPass = $allPass && $pass; ?>
                <div class="check-row">
                    <span><?php echo e($name); ?></span>
                    <span class="<?php echo $pass ? 'ok' : 'fail'; ?>"><?php echo $pass ? '✓ ' : '✗ '; ?><?php echo e($detail); ?></span>
                </div>
            <?php endforeach; ?>
            <div class="btn-row">
                <a href="?step=2" class="btn <?php echo $allPass ? 'btn-primary' : 'btn-outline'; ?>" <?php echo $allPass ? '' : 'onclick="alert(\'环境不满足要求，请先修复后再安装\');return false;"'; ?>>
                    下一步：数据库与管理员配置 →
                </a>
            </div>

        <?php elseif ($STEP === 2): ?>
            <form method="post" id="installForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="install">
                <div class="section-title">数据库配置</div>
                <div class="form-grid">
                    <div class="form-row"><label>数据库主机</label><input type="text" name="db_host" value="127.0.0.1" required></div>
                    <div class="form-row"><label>端口</label><input type="number" name="db_port" value="3306" required></div>
                    <div class="form-row full"><label>数据库名（不存在将自动创建，若账号无建库权限请先在面板创建）</label><input type="text" name="db_name" value="dcai_auth" required></div>
                    <div class="form-row"><label>用户名</label><input type="text" name="db_user" required></div>
                    <div class="form-row"><label>密码</label><input type="password" name="db_pass"></div>
                    <div class="form-row full">
                        <label>系统访问地址</label>
                        <input type="text" name="base_url" value="<?php echo e($autoBase); ?>" placeholder="https://auth.example.com">
                        <span class="help">用于 SDK 生成下载链接等，一般保持默认即可</span>
                    </div>
                </div>
                <div class="btn-row" style="margin-top:10px;">
                    <button type="button" class="btn btn-outline" id="testDbBtn" onclick="testDb()">测试数据库连接</button>
                    <span id="dbTestResult" class="db-test-result"></span>
                </div>

                <div class="section-title">超级管理员</div>
                <div class="form-grid">
                    <div class="form-row"><label>登录账号</label><input type="text" name="admin_username" value="admin" required></div>
                    <div class="form-row"><label>昵称</label><input type="text" name="admin_nickname" value="管理员"></div>
                    <div class="form-row"><label>邮箱（可选）</label><input type="email" name="admin_email"></div>
                    <div class="form-row"><label>密码（至少 6 位）</label><input type="password" name="admin_password" required minlength="6"></div>
                    <div class="form-row"><label>确认密码</label><input type="password" name="admin_confirm" required minlength="6"></div>
                </div>
                <div class="btn-row">
                    <a href="?step=1" class="btn btn-outline">← 上一步</a>
                    <button type="submit" class="btn btn-success" id="submitBtn">开始安装</button>
                </div>
            </form>
            <script>
            function validateForm() {
                var p1 = document.querySelector('[name=admin_password]').value;
                var p2 = document.querySelector('[name=admin_confirm]').value;
                if (p1 !== p2) { alert('两次输入的密码不一致'); return false; }
                var btn = document.getElementById('submitBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner"></span>安装中…';
                return true;
            }
            function testDb() {
                var btn = document.getElementById('testDbBtn');
                var res = document.getElementById('dbTestResult');
                btn.disabled = true;
                res.className = 'db-test-result';
                res.textContent = '测试中…';
                var data = new FormData();
                data.append('action', 'test_db');
                ['db_host','db_port','db_name','db_user','db_pass'].forEach(function(k){
                    data.append(k, document.querySelector('[name='+k+']').value);
                });
                fetch('?step=2', { method:'POST', body:data })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        res.className = 'db-test-result ' + (j.ok ? 'ok' : 'fail');
                        res.textContent = j.msg;
                    })
                    .catch(function(){ res.className='db-test-result fail'; res.textContent='测试请求失败，请检查网络'; })
                    .finally(function(){ btn.disabled = false; });
            }
            </script>

        <?php else: ?>
            <div class="alert alert-warning">未知步骤，请 <a href="?step=1">返回环境检测</a>。</div>
        <?php endif; ?>

        <?php if ($installed): ?>
            <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
                <form method="post">
                    <input type="hidden" name="action" value="upgrade_db">
                    <button type="submit" class="btn btn-outline">升级数据库结构（已安装实例 → V1.1 商城化）</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

<?php
/**
 * 后台登录页
 */
require_once dirname(__DIR__, 2) . '/core/Bootstrap.php';
DCAI_Admin::startSession();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!DCAI_Csrf::verify()) {
        $error = 'CSRF 校验失败，请刷新页面重试';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = '请输入账号和密码';
        } else {
            [$ok, $msg] = DCAI_Admin::checkLoginAttempts($username);
            if (!$ok) {
                $error = $msg;
            } else {
                $user = dcai_db()->queryOne('SELECT * FROM admin_users WHERE username = ?', [$username]);
                if ($user && (int)$user['status'] === 1 && password_verify($password, $user['password_hash'])) {
                    // 2FA：若已绑定验证器，先进入两步验证
                    if (!empty($user['twofa_secret'])) {
                        session_regenerate_id(true);
                        $_SESSION['dcai_2fa_pending'] = $user['id'];
                        $_SESSION['dcai_2fa_username'] = $user['username'];
                        header('Location: ' . DCAI_Admin::adminUrl('2fa.php'));
                        exit;
                    }
                    session_regenerate_id(true);
                    DCAI_Admin::login($user);
                    if ((int)$user['role'] !== 1) {
                        // 普通管理员加载权限点
                        $perms = DCAI_Util::parseLines($user['permissions'] ?? '');
                        DCAI_Admin::setPerms($perms);
                    }
                    dcai_db()->update('admin_users', [
                        'last_login_at' => dcai_now(),
                        'last_login_ip' => dcai_client_ip(),
                        'updated_at'    => dcai_now(),
                    ], 'id = ?', [(int)$user['id']]);
                    DCAI_Admin::clearLoginFail($username);
                    DCAI_Admin::opLog('登录后台');
                    header('Location: ' . DCAI_Admin::adminUrl('dashboard.php'));
                    exit;
                }
                $failCount = DCAI_Admin::recordLoginFail($username);
                $error = '账号或密码错误' . ($failCount >= 3 ? "（失败 {$failCount} 次，5 次将锁定）" : '');
            }
        }
    }
}

// 已登录直接跳转
if (DCAI_Admin::id() !== null) {
    header('Location: ' . DCAI_Admin::adminUrl('dashboard.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 - <?php echo DCAI_Util::e(dcai_config('app.name', 'DCAI 授权系统')); ?></title>
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="login-body">
<div class="login-box">
    <div class="logo-row">
        <span class="logo">🔐</span>
        <h2><?php echo DCAI_Util::e(dcai_config('app.name', 'DCAI 授权系统')); ?></h2>
    </div>
    <p class="sub">请使用管理员账号登录</p>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo DCAI_Util::e($error); ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo DCAI_Util::e(DCAI_Csrf::token()); ?>">
        <div class="form-row">
            <label>账号</label>
            <input type="text" name="username" required autofocus placeholder="请输入登录账号">
        </div>
        <div class="form-row">
            <label>密码</label>
            <input type="password" name="password" required placeholder="请输入密码">
        </div>
        <button type="submit" class="btn btn-primary btn-block" style="margin-top:6px;">登 录</button>
    </form>
</div>
<script>
// 登录按钮防重复提交
document.addEventListener('DOMContentLoaded', function () {
    document.querySelector('form').addEventListener('submit', function () {
        var btn = this.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.classList.add('loading'); }
    });
});
</script>
</body>
</html>

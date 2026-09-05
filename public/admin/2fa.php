<?php
/**
 * 后台两步验证页：密码验证通过后，若管理员已绑定 Google 验证器，在此输入动态码完成登录
 */
require_once dirname(__DIR__, 2) . '/core/Bootstrap.php';
DCAI_Admin::startSession();

// 校验是否处于 2FA 待验证状态
$pendingUid = $_SESSION['dcai_2fa_pending'] ?? null;
$pendingUser = null;
if ($pendingUid !== null) {
    $pendingUser = dcai_db()->queryOne('SELECT * FROM admin_users WHERE id = ?', [(int)$pendingUid]);
}
if (!$pendingUser || empty($pendingUser['twofa_secret'])) {
    // 非法进入：清状态回登录页
    unset($_SESSION['dcai_2fa_pending'], $_SESSION['dcai_2fa_username']);
    header('Location: ' . DCAI_Admin::adminUrl('login.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!DCAI_Csrf::verify()) {
        $error = 'CSRF 校验失败，请刷新页面重试';
    } else {
        $code = trim((string)($_POST['code'] ?? ''));
        if (DCAI_Totp::verify($pendingUser['twofa_secret'], $code)) {
            // 2FA 通过：完成登录
            session_regenerate_id(true);
            DCAI_Admin::login($pendingUser);
            if ((int)$pendingUser['role'] !== 1) {
                $perms = DCAI_Util::parseLines($pendingUser['permissions'] ?? '');
                DCAI_Admin::setPerms($perms);
            }
            dcai_db()->update('admin_users', [
                'last_login_at' => dcai_now(),
                'last_login_ip' => dcai_client_ip(),
                'updated_at'    => dcai_now(),
            ], 'id = ?', [(int)$pendingUser['id']]);
            DCAI_Admin::clearLoginFail($pendingUser['username']);
            unset($_SESSION['dcai_2fa_pending'], $_SESSION['dcai_2fa_username']);
            DCAI_Admin::opLog('登录后台（两步验证）');
            header('Location: ' . DCAI_Admin::adminUrl('dashboard.php'));
            exit;
        }
        $error = '动态验证码错误或已过期，请重试';
    }
}

// 已登录则直接跳转
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
<title>两步验证 - <?php echo DCAI_Util::e(dcai_config('app.name', 'DCAI 授权系统')); ?></title>
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="login-body">
<div class="login-box">
    <div class="logo-row">
        <span class="logo">🔐</span>
        <h2><?php echo DCAI_Util::e(dcai_config('app.name', 'DCAI 授权系统')); ?></h2>
    </div>
    <p class="sub">账号 <?php echo DCAI_Util::e($_SESSION['dcai_2fa_username'] ?? ''); ?> 已开启两步验证<br>请输入 Google 验证器中的 6 位动态码</p>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo DCAI_Util::e($error); ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo DCAI_Util::e(DCAI_Csrf::token()); ?>">
        <div class="form-row">
            <label>动态验证码</label>
            <input type="text" name="code" required autofocus placeholder="6 位数字" inputmode="numeric" pattern="\d{6}" maxlength="6" style="letter-spacing:4px;text-align:center;font-size:20px;">
        </div>
        <button type="submit" class="btn btn-primary btn-block" style="margin-top:6px;">验 证</button>
    </form>
    <p style="margin-top:14px;text-align:center;"><a href="<?php echo DCAI_Admin::adminUrl('logout.php'); ?>">返回重新登录</a></p>
</div>
</body>
</html>

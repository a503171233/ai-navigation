<?php
/**
 * 买家登录
 */
require __DIR__ . '/_init.php';

// 已登录跳转
if ($currentBuyer) {
    header('Location: ' . shop_url('home'));
    exit;
}

$error = '';

// 买家登录
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    // 失败次数锁定（与后台登录同策略）
    [$lockOk, $lockMsg] = DCAI_Store::loginLockState($email, dcai_client_ip());
    if (!$lockOk) {
        $error = $lockMsg;
    } else {
        $lg = DCAI_Store::buyerLogin($email, $password);
        if (!$lg[0]) {
            $error = $lg[1];
            DCAI_Store::recordLoginFail($email, dcai_client_ip());
        } else {
            DCAI_Store::clearLoginFail($email, dcai_client_ip());
            session_regenerate_id(true);
            $_SESSION['dcai_buyer_id'] = (int)$lg[1]['id'];
            shop_flash('success', '登录成功，欢迎回来！');
            header('Location: ' . shop_url('home'));
            exit;
        }
    }
}

$pageTitle = '买家登录';
shop_layout_start($pageTitle);
?>
<div class="auth-box">
    <div class="auth-title">买家登录</div>
    <div class="auth-sub">登录后查看订单与授权码</div>
    <?php if ($error !== ''): ?>
        <div class="shop-alert shop-alert-danger"><?php echo DCAI_Util::e($error); ?></div>
    <?php endif; ?>
    <form method="post">
        <?php echo shop_csrf_field(); ?>
        <div class="form-group">
            <label>邮箱</label>
            <input type="email" name="email" class="form-control" required autofocus value="<?php echo DCAI_Util::e($_POST['email'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>密码</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">登 录</button>
    </form>
    <p class="muted mt-16" style="text-align:center;">还没有账号？<a href="<?php echo shop_url('register'); ?>">立即注册</a></p>
</div>
<?php shop_layout_end(); ?>
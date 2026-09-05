<?php
/**
 * 买家注册
 */
require __DIR__ . '/_init.php';

if ($currentBuyer) {
    header('Location: ' . shop_url('home'));
    exit;
}

$error = '';

// 注册买家
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    $nickname = trim((string)($_POST['nickname'] ?? ''));
    // 服务端兜底校验（前端关闭原生校验后，完整性由服务端保证）
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif (strlen($password) < 6) {
        $error = '密码至少 6 位';
    } elseif ($password !== $password2) {
        $error = '两次输入的密码不一致';
    } else {
        $reg = DCAI_Store::buyerRegister($email, $password, $nickname);
        if ($reg[0]) {
            // 注册成功：自动登录并跳转
            $_SESSION['dcai_buyer_id'] = (int)$reg[1]['id'];
            shop_flash('success', '注册成功，已自动登录！');
            header('Location: ' . shop_url('home'));
            exit;
        }
        $error = $reg[1];
    }
}

$pageTitle = '买家注册';
shop_layout_start($pageTitle);
?>
<div class="auth-box">
    <div class="auth-title">注册买家账号</div>
    <div class="auth-sub">注册后可下单购买并管理授权</div>
    <?php if ($error !== ''): ?>
        <div class="shop-alert shop-alert-danger"><?php echo DCAI_Util::e($error); ?></div>
    <?php endif; ?>
    <form method="post" data-validate novalidate>
        <?php echo shop_csrf_field(); ?>
        <div class="form-group">
            <label>邮箱（登录账号）</label>
            <input type="email" name="email" class="form-control" required autofocus value="<?php echo DCAI_Util::e($_POST['email'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>昵称（可选）</label>
            <input type="text" name="nickname" class="form-control" value="<?php echo DCAI_Util::e($_POST['nickname'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>密码（至少 6 位）</label>
            <input type="password" name="password" id="reg-password" class="form-control" required minlength="6">
        </div>
        <div class="form-group">
            <label>确认密码</label>
            <input type="password" name="password2" id="reg-password2" class="form-control" required minlength="6">
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">注 册</button>
    </form>
    <p class="muted mt-16" style="text-align:center;">已有账号？<a href="<?php echo shop_url('login'); ?>">直接登录</a></p>
</div>
<script>
// 两次密码一致性即时校验
(function () {
    var p1 = document.getElementById('reg-password');
    var p2 = document.getElementById('reg-password2');
    function check() {
        if (p2.value === '') { p2.classList.remove('invalid'); return; }
        if (p1.value !== p2.value) { p2.classList.add('invalid'); } else { p2.classList.remove('invalid'); }
    }
    if (p1 && p2) {
        p1.addEventListener('input', check);
        p2.addEventListener('input', check);
    }
})();
</script>
<?php shop_layout_end(); ?>
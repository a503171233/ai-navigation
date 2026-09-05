<?php
/**
 * 商城门户公共初始化（前台买家会话 + 公共布局辅助）
 * 用法: require __DIR__ . '/_init.php';
 */
require_once dirname(__DIR__, 2) . '/core/Bootstrap.php';

// 买家会话
if (session_status() === PHP_SESSION_NONE) {
    session_name('DCAI_STORE');
    // 显式指定可写会话目录（避免默认 save_path 不可写导致登录态丢失）
    $sessDir = (string)dcai_config('storage.path', DCAI_ROOT . '/storage') . '/cache/sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0755, true);
    }
    session_save_path($sessDir);
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$currentBuyer = null;
$buyerId = $_SESSION['dcai_buyer_id'] ?? null;
if ($buyerId !== null) {
    $currentBuyer = dcai_db()->queryOne('SELECT * FROM buyers WHERE id = ? AND status = 1', [(int)$buyerId]);
    if (!$currentBuyer) {
        unset($_SESSION['dcai_buyer_id']);
        $buyerId = null;
    }
}

$flash = $_SESSION['dcai_shop_flash'] ?? null;
unset($_SESSION['dcai_shop_flash']);

function shop_flash(string $type, string $msg): void
{
    $_SESSION['dcai_shop_flash'] = ['type' => $type, 'msg' => $msg];
}

// ---------- 商城前台 CSRF 防护（买家会话内独立令牌） ----------
function shop_csrf_token(): string
{
    if (empty($_SESSION['dcai_shop_csrf'])) {
        $_SESSION['dcai_shop_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['dcai_shop_csrf'];
}

function shop_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(shop_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function shop_csrf_ok(): bool
{
    $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return $token !== '' && hash_equals((string)($_SESSION['dcai_shop_csrf'] ?? ''), $token);
}

function shop_csrf_check(): void
{
    if (!shop_csrf_ok()) {
        http_response_code(400);
        exit('页面校验失败，请刷新后重试');
    }
}

/** 商城基础 URL（相对 /shop） */
function shop_url(string $path = ''): string
{
    return '/shop/' . ltrim($path, '/');
}

function shop_unit_label(string $unit): string
{
    return [
        'month'     => '月付',
        'quarter'   => '季付',
        'half_year' => '半年付',
        'year'      => '年付',
        'perpetual' => '永久',
    ][$unit] ?? $unit;
}

function shop_amount(int|float $price): string
{
    return '¥' . number_format((float)$price, 2);
}

function shop_layout_start(string $title): void
{
    $siteName = (string)dcai_config('app.name', 'DCAI 授权商城');
    $buyer = $GLOBALS['currentBuyer'];
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo DCAI_Util::e($title); ?> - <?php echo DCAI_Util::e($siteName); ?></title>
<link rel="stylesheet" href="/shop/assets/shop.css?v=20260905">
<script src="/shop/assets/shop.js?v=20260905" defer></script>
</head>
<body>
<header class="shop-header">
    <div class="shop-container shop-flex">
        <div class="shop-left">
            <button type="button" class="shop-menubtn" id="shopMenuBtn" aria-label="展开菜单">☰</button>
            <a href="/shop/home" class="shop-brand"><?php echo DCAI_Util::e($siteName); ?></a>
        </div>
        <nav class="shop-nav" id="shopNav">
            <div class="nav-group">
                <button type="button" class="nav-group-head<?php echo in_array($title, ['产品列表', '我的订单', '我的授权'], true) ? ' open' : ''; ?>" data-nav-toggle>
                    <span class="nav-group-title">购物</span><span class="nav-arrow">−</span>
                </button>
                <div class="nav-group-body">
                    <a href="/shop/home">产品</a>
                    <?php if ($buyer): ?>
                        <a href="/shop/orders">我的订单</a>
                        <a href="/shop/licenses">我的授权</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($buyer): ?>
            <div class="nav-group">
                <button type="button" class="nav-group-head" data-nav-toggle>
                    <span class="nav-group-title"><?php echo DCAI_Util::e($buyer['nickname'] ?: $buyer['username']); ?></span><span class="nav-arrow">+</span>
                </button>
                <div class="nav-group-body hidden">
                    <a href="/shop/licenses">我的授权</a>
                    <a href="/shop/orders">我的订单</a>
                    <a href="/shop/trial">领取试用</a>
                    <a href="/shop/logout" class="nav-logout">退出登录</a>
                </div>
            </div>
            <?php else: ?>
            <div class="nav-group">
                <button type="button" class="nav-group-head" data-nav-toggle>
                    <span class="nav-group-title">账户</span><span class="nav-arrow">+</span>
                </button>
                <div class="nav-group-body hidden">
                    <a href="/shop/login">登录</a>
                    <a href="/shop/register">注册</a>
                </div>
            </div>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="shop-main">
<div class="shop-container">
<?php if (!empty($GLOBALS['flash'])): ?>
    <div class="shop-alert shop-alert-<?php echo DCAI_Util::e($GLOBALS['flash']['type']); ?>"><?php echo DCAI_Util::e($GLOBALS['flash']['msg']); ?></div>
<?php endif; ?>
<?php
}

function shop_layout_end(): void
{
    ?>
</div>
</main>
<footer class="shop-footer">
    <div class="shop-container">Powered by DCAI 授权系统</div>
</footer>
</body>
</html>
<?php
}
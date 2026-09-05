<?php
/**
 * 自助申请试用（Trial）
 * 流程：需登录 → 选择开放试用的产品 → 一键申请 → 自动生成试用授权码 → 跳转「我的授权」
 */
require __DIR__ . '/_init.php';

if (!$currentBuyer) {
    shop_flash('warning', '请先登录后再申请试用');
    header('Location: ' . shop_url('login?next=trial'));
    exit;
}

$productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
$product = null;
if ($productId > 0) {
    $product = dcai_db()->queryOne(
        'SELECT * FROM products WHERE id = ? AND status = 1 AND trial_enabled = 1 AND trial_days > 0',
        [$productId]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $productId > 0) {
    $product = dcai_db()->queryOne(
        'SELECT * FROM products WHERE id = ? AND status = 1 AND trial_enabled = 1 AND trial_days > 0',
        [$productId]
    );
    if (!$product) {
        $error = '该产品未开放试用';
    } else {
        [$ok, $res] = DCAI_Store::applyTrial($productId, (int)$buyerId);
        if ($ok) {
            shop_flash('success', '试用授权码已生成：' . $res['license_key'] . '（试用 ' . (int)$res['trial_days'] . ' 天）');
            header('Location: ' . shop_url('licenses'));
            exit;
        }
        $error = is_string($res) ? $res : '申请试用失败';
    }
}

$pageTitle = '免费试用';
shop_layout_start($pageTitle);
?>
<h1 style="font-size:22px;margin-bottom:4px;">免费试用</h1>
<p class="muted" style="margin-bottom:20px;">先体验再购买，每个账号每款产品限领一次试用授权</p>

<?php if ($error ?? ''): ?>
    <div class="shop-alert shop-alert-danger"><?php echo DCAI_Util::e($error); ?></div>
<?php endif; ?>

<?php if (!$product): ?>
    <?php
    // 展示所有开放试用的产品
    $trialProducts = dcai_db()->query('SELECT * FROM products WHERE status = 1 AND trial_enabled = 1 AND trial_days > 0 ORDER BY id DESC');
    // 当前买家已申请的试用（source=3），用于卡片显示"已申请"状态
    $applied = [];
    if ($trialProducts) {
        $ids = array_map('intval', array_column($trialProducts, 'id'));
        $marks = $ids ? dcai_db()->query(
            'SELECT product_id, status, expire_at FROM licenses WHERE buyer_id = ? AND source = 3 AND product_id IN (' . implode(',', $ids) . ')',
            [$buyerId]
        ) : [];
        foreach ($marks as $mk) { $applied[(int)$mk['product_id']] = $mk; }
    }
    ?>
    <?php if (!$trialProducts): ?>
        <div class="card empty">
            <div class="ico">🎁</div>
            暂无可申请试用的产品，敬请期待。
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($trialProducts as $p):
                $had = $applied[(int)$p['id']] ?? null;
            ?>
            <div class="product-card trial-card">
                <?php if ($had): ?><span class="trial-ribbon <?php echo (int)$had['status'] === 1 && strtotime((string)$had['expire_at']) > time() ? 'used' : 'ended'; ?>"><?php echo (int)$had['status'] === 1 && strtotime((string)$had['expire_at']) > time() ? '试用中' : '已用尽'; ?></span><?php endif; ?>
                <div class="product-thumb"><?php echo DCAI_Util::e($p['sale_icon'] ?: '🎁'); ?></div>
                <div class="product-body">
                    <div class="product-name"><?php echo DCAI_Util::e($p['name']); ?></div>
                    <div class="product-desc"><?php echo DCAI_Util::e(mb_substr($p['sale_intro'] ?: $p['description'] ?: '', 0, 60)); ?></div>
                    <div class="product-meta">
                        <div class="product-price">免费试用 <small><?php echo (int)$p['trial_days']; ?> 天</small></div>
                        <?php if ($had): ?>
                            <span class="btn btn-sm disabled-btn">已申请</span>
                        <?php else: ?>
                            <a href="<?php echo shop_url('trial?product_id=' . (int)$p['id']); ?>" class="btn btn-primary btn-sm">立即申请</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="auth-box" style="max-width:520px;">
        <div class="auth-title">申请试用 <?php echo DCAI_Util::e($product['name']); ?></div>
        <div class="auth-sub">试用 <?php echo (int)$product['trial_days']; ?> 天，到期后可购买正式授权</div>
        <div class="shop-alert" style="margin:16px 0;">
            <strong>试用说明：</strong><br>
            · 每账号每产品限领 1 次试用授权<br>
            · 试用授权含机器绑定（1 台），支持离线激活<br>
            · 到期后授权自动失效，不影响购买正式版
        </div>
        <form method="post" data-confirm="确认申请该产品试用授权？">
            <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
            <button type="submit" class="btn btn-primary btn-block btn-lg">立即领取试用授权</button>
        </form>
    </div>
<?php endif; ?>
<?php shop_layout_end(); ?>

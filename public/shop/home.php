<?php
/**
 * 商城首页：在售产品列表
 */
require __DIR__ . '/_init.php';

$db = dcai_db();
$products = $db->query('SELECT * FROM products WHERE status = 1 AND (for_sale = 1 OR trial_enabled = 1) ORDER BY id DESC');

$pageTitle = '产品列表';
shop_layout_start($pageTitle);
?>
<h1 style="font-size:22px;margin-bottom:4px;">授权产品</h1>
<p class="muted" style="margin-bottom:20px;">购买后自动发放授权码，可在「我的授权」中绑定域名使用</p>

<?php if (!$products): ?>
    <div class="card empty">商城暂无可售产品，敬请期待。</div>
<?php else: ?>
    <div class="product-grid">
        <?php foreach ($products as $p): ?>
        <div class="product-card">
            <div class="product-thumb"><?php echo DCAI_Util::e($p['sale_icon'] ?: '📦'); ?></div>
            <div class="product-body">
                <div class="product-name"><?php echo DCAI_Util::e($p['name']); ?></div>
                <div class="product-desc"><?php echo DCAI_Util::e(mb_substr($p['sale_intro'] ?: $p['description'] ?: '', 0, 60)); ?></div>
                <div class="product-meta">
                    <div class="product-price"><?php echo (float)$p['sale_price'] > 0 ? shop_amount($p['sale_price']) . '<small>/' . shop_unit_label($p['price_unit']) . '</small>' : '联系购买'; ?></div>
                    <div class="product-actions" style="display:flex;gap:8px;">
                        <?php if ((float)$p['sale_price'] > 0): ?>
                            <a href="<?php echo shop_url('order?product_id=' . (int)$p['id']); ?>" class="btn btn-primary btn-sm">立即购买</a>
                        <?php endif; ?>
                        <?php if ((int)$p['trial_enabled'] === 1 && (int)$p['trial_days'] > 0): ?>
                            <a href="<?php echo shop_url('trial?product_id=' . (int)$p['id']); ?>" class="btn btn-outline btn-sm">免费试用<?php echo (int)$p['trial_days']; ?>天</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php shop_layout_end(); ?>

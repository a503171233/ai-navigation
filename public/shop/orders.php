<?php
/**
 * 我的订单列表
 */
require __DIR__ . '/_init.php';

if (!$currentBuyer) {
    header('Location: ' . shop_url('login'));
    exit;
}

$db = dcai_db();
$orders = $db->query('SELECT * FROM orders WHERE buyer_id = ? ORDER BY id DESC', [(int)$currentBuyer['id']]);

$pageTitle = '我的订单';
shop_layout_start($pageTitle);
?>
<h1 style="font-size:22px;margin-bottom:20px;">我的订单</h1>
<?php if (!$orders): ?>
    <div class="card empty">暂无订单。<a href="<?php echo shop_url('home'); ?>">去逛逛商城</a></div>
<?php else: ?>
<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>订单号</th><th>产品</th><th>金额</th><th>支付状态</th><th>发货</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o):
                $payBadge = (int)$o['pay_status'] === 1 ? '<span class="badge badge-green">已支付</span>'
                    : ((int)$o['pay_status'] === 0 ? '<span class="badge badge-orange">待支付</span>' : '<span class="badge badge-gray">已取消</span>');
                $ship = (int)$o['ship_status'] === 1 ? '<span class="badge badge-blue">已发码</span>'
                    : ((int)$o['ship_status'] === 2 ? '<span class="badge badge-blue">已发货</span>' : '<span class="badge badge-gray">未发货</span>');
                ?>
                <tr>
                    <td class="mono"><?php echo DCAI_Util::e($o['order_no']); ?></td>
                    <td><?php echo DCAI_Util::e($o['product_name']); ?></td>
                    <td><?php echo shop_amount($o['amount']); ?></td>
                    <td><?php echo $payBadge; ?></td>
                    <td><?php echo $ship; ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($o['created_at']); ?></td>
                    <td>
                        <?php if ((int)$o['pay_status'] === 0): ?>
                        <a href="<?php echo shop_url('pay?order_no=' . urlencode($o['order_no'])); ?>" class="btn btn-primary btn-sm">去支付</a>
                        <?php elseif ($o['license_id']): ?>
                        <a href="<?php echo shop_url('pay_result?order_no=' . urlencode($o['order_no'])); ?>" class="btn btn-outline btn-sm">查看授权码</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php shop_layout_end(); ?>
<?php
/**
 * 支付结果页：展示支付状态与授权码
 */
require __DIR__ . '/_init.php';

if (!$currentBuyer) {
    header('Location: ' . shop_url('login'));
    exit;
}

$db = dcai_db();
$orderNo = (string)($_GET['order_no'] ?? '');
$order = $db->queryOne('SELECT * FROM orders WHERE order_no = ? AND buyer_id = ?', [$orderNo, (int)$currentBuyer['id']]);
if (!$order) {
    shop_flash('danger', '订单不存在');
    header('Location: ' . shop_url('orders'));
    exit;
}

$license = null;
if ($order['license_id']) {
    $license = $db->queryOne('SELECT * FROM licenses WHERE id = ?', [(int)$order['license_id']]);
}
$paid = (int)$order['pay_status'] === 1;

$pageTitle = '支付结果';
shop_layout_start($pageTitle);
?>
<div class="card" style="max-width:640px;margin:0 auto;">
    <?php if ($paid): ?>
    <div class="shop-alert shop-alert-success" style="font-size:15px;">🎉 支付成功！授权码已自动生成：</div>
    <?php else: ?>
    <div class="shop-alert shop-alert-warning">订单状态：待支付/待确认。若您已完成人工转账，请等待管理员确认发货。</div>
    <?php endif; ?>

    <table class="table">
        <tr><td style="width:120px;">订单号</td><td class="mono"><?php echo DCAI_Util::e($order['order_no']); ?></td></tr>
        <tr><td>产品</td><td><?php echo DCAI_Util::e($order['product_name']); ?></td></tr>
        <tr><td>金额</td><td><?php echo shop_amount($order['amount']); ?></td></tr>
        <?php if ($license): ?>
        <tr><td>授权码</td>
            <td>
                <div class="mono" style="font-size:18px;font-weight:600;background:#f6f8fa;padding:10px;border-radius:6px;letter-spacing:1px;"><?php echo DCAI_Util::e($license['license_key']); ?></div>
                <div class="form-help">请复制并妥善保存，用于被授权程序的 SDK 配置</div>
            </td>
        </tr>
        <tr><td>到期时间</td><td><?php echo $license['expire_at'] ? DCAI_Util::e($license['expire_at']) : '<span class="badge badge-purple">永久授权</span>'; ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="mt-16" style="display:flex;gap:10px;">
        <?php if ($license): ?>
        <a href="<?php echo shop_url('licenses'); ?>" class="btn btn-primary">我的授权管理</a>
        <?php endif; ?>
        <a href="<?php echo shop_url('orders'); ?>" class="btn btn-outline">返回订单列表</a>
    </div>
</div>
<?php shop_layout_end(); ?>
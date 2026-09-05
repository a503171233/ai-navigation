<?php
/**
 * 商城下单页
 * GET: 选择产品与支付方式
 * POST: 创建订单并跳转支付
 */
require __DIR__ . '/_init.php';

if (!$currentBuyer) {
    shop_flash('warning', '请先登录后再购买');
    header('Location: ' . shop_url('login'));
    exit;
}

$db = dcai_db();
$productId = (int)($_GET['product_id'] ?? ($_POST['product_id'] ?? 0));
$product = $db->queryOne('SELECT * FROM products WHERE id = ? AND for_sale = 1 AND status = 1 AND sale_price > 0', [$productId]);
if (!$product) {
    shop_flash('danger', '产品不存在或未上架');
    header('Location: ' . shop_url('home'));
    exit;
}

$error = '';
$orderNo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $channel = (string)($_POST['pay_channel'] ?? 'manual');
    $note = trim((string)($_POST['customer_note'] ?? ''));
    if (!in_array($channel, ['manual', 'epay', 'alipay', 'wxpay'], true)) {
        $channel = 'manual';
    }
    [$ok, $res] = DCAI_Store::createOrder($productId, (int)$currentBuyer['id'], $channel, $note, 1);
    if (!$ok) {
        $error = $res;
    } else {
        $orderNo = $res['order_no'];
        // 跳转支付
        header('Location: ' . shop_url('pay?order_no=' . urlencode($res['order_no'])));
        exit;
    }
}

$pageTitle = '购买 ' . $product['name'];
shop_layout_start($pageTitle);
?>
<div class="card">
    <div class="card-title">确认订单</div>
    <?php if ($error !== ''): ?>
        <div class="shop-alert shop-alert-danger"><?php echo DCAI_Util::e($error); ?></div>
    <?php endif; ?>
    <table class="table" style="max-width:640px;">
        <tr><td style="width:120px;">产品</td><td><?php echo DCAI_Util::e($product['name']); ?></td></tr>
        <tr><td>计费</td><td><?php echo shop_unit_label($product['price_unit']); ?></td></tr>
        <tr><td>价格</td><td class="amount-total"><?php echo shop_amount($product['sale_price']); ?></td></tr>
    </table>
    <form method="post" class="mt-16" style="max-width:640px;">
        <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
        <div class="form-group">
            <label>支付方式</label>
            <div class="pay-methods">
                <?php if ((string)dcai_config('store.epay_gateway', '') !== ''): ?>
                <label class="pay-method"><input type="radio" name="pay_channel" value="epay" checked> 易支付（支付宝/微信）</label>
                <?php endif; ?>
                <label class="pay-method"><input type="radio" name="pay_channel" value="manual" <?php echo (string)dcai_config('store.epay_gateway', '') === '' ? 'checked' : ''; ?>> 人工转账（客服确认）</label>
            </div>
        </div>
        <div class="form-group">
            <label>备注（可选：填写部署域名/IP 等）</label>
            <input type="text" name="customer_note" class="form-control" maxlength="250" placeholder="例：www.example.com">
        </div>
        <button type="submit" class="btn btn-primary btn-lg">提交订单并支付</button>
    </form>
</div>
<?php shop_layout_end(); ?>
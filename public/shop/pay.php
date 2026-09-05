<?php
/**
 * 支付页：展示订单并拉起支付
 *  - 人工转账: 显示收款二维码/账号 + 待确认提示
 *  - 易支付: 跳转支付网关
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

// 已支付则直接展示结果
if ((int)$order['pay_status'] === 1) {
    header('Location: ' . shop_url('pay_result?order_no=' . urlencode($orderNo)));
    exit;
}

// 拉起易支付
$epayGateway = (string)dcai_config('store.epay_gateway', '');
if ($order['pay_channel'] === 'epay' && $epayGateway !== '') {
    $epayPid = (int)dcai_config('store.epay_pid', 0);
    $epayKey = (string)dcai_config('store.epay_key', '');
    $base = rtrim((string)dcai_config('app.base_url', ''), '/');
    $params = [
        'pid'          => $epayPid,
        'type'         => 'alipay',
        'out_trade_no' => $order['order_no'],
        'notify_url'   => $base . '/shop/epay_notify.php',
        'return_url'   => $base . '/shop/pay_result.php?order_no=' . $order['order_no'],
        'name'         => '购买授权-' . $order['product_name'],
        'money'        => number_format((float)$order['amount'], 2, '.', ''),
        'sign_type'    => 'MD5',
    ];
    $params['sign'] = DCAI_Store::epaySign($params, $epayKey);
    $payUrl = rtrim($epayGateway, '/') . '/submit.php?' . http_build_query($params);
    header('Location: ' . $payUrl);
    exit;
}

$pageTitle = '订单支付';
shop_layout_start($pageTitle);
?>
<div class="card" style="max-width:640px;margin:0 auto;">
    <div class="card-title">订单 #<?php echo DCAI_Util::e($order['order_no']); ?></div>
    <table class="table">
        <tr><td style="width:120px;">产品</td><td><?php echo DCAI_Util::e($order['product_name']); ?></td></tr>
        <tr><td>金额</td><td class="amount-total"><?php echo shop_amount($order['amount']); ?></td></tr>
        <tr><td>状态</td><td><span class="badge badge-orange">待支付</span></td></tr>
    </table>
    <?php if ($order['pay_channel'] === 'manual'): ?>
    <div class="shop-alert shop-alert-warning mt-16">
        <strong>人工转账购买：</strong>请转账至以下账号，并在付款后联系客服（或等待管理员在后台确认发货）。
        <div class="mt-8" style="font-size:13px;line-height:1.9;">
            <?php
            $manualAccount = (string)dcai_config('store.manual_account', '');
            echo $manualAccount !== '' ? nl2br(DCAI_Util::e($manualAccount)) : '请联系客服获取收款方式。';
            ?>
        </div>
    </div>
    <?php else: ?>
    <div class="shop-alert shop-alert-warning mt-16">正在跳转支付… 若未自动跳转，请<a href="javascript:location.reload()">刷新重试</a>。</div>
    <?php endif; ?>
    <div class="mt-16"><a href="<?php echo shop_url('orders'); ?>" class="btn btn-outline">返回订单列表</a>
        <a href="<?php echo shop_url('pay_result?order_no=' . urlencode($orderNo)); ?>" class="btn btn-primary" style="margin-left:8px;">我已完成支付</a>
    </div>
</div>
<?php shop_layout_end(); ?>
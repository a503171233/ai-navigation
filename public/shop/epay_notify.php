<?php
/**
 * 易支付异步通知回调
 * 收到支付成功通知后：验签 → 标记订单已支付 → 自动发码
 * 返回 'success' 给易支付表示已收到（易支付要求）
 */
require_once dirname(__DIR__, 2) . '/core/Bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$epayKey = (string)dcai_config('store.epay_key', '');
if ($epayKey === '') {
    http_response_code(500);
    exit('epay not configured');
}

$params = $_POST ?: $_GET;
if (empty($params['out_trade_no']) || empty($params['trade_no']) || empty($params['sign'])) {
    http_response_code(400);
    exit('invalid params');
}

// 验签
if (!DCAI_Store::epayVerify($params, $epayKey)) {
    http_response_code(400);
    exit('sign fail');
}

$orderNo = (string)$params['out_trade_no'];
$db = dcai_db();
$order = $db->queryOne('SELECT * FROM orders WHERE order_no = ?', [$orderNo]);
if (!$order) {
    http_response_code(404);
    exit('order not found');
}

// 幂等处理
if ((int)$order['pay_status'] === 1 && (int)$order['ship_status'] === 1 && $order['license_id']) {
    exit('success'); // 已处理过
}

[$ok, $res] = DCAI_Store::paySuccess((int)$order['id'], (string)$params['trade_no'], 'epay');
if (!$ok) {
    http_response_code(500);
    exit('process fail: ' . $res);
}

// 记录日志
try {
    dcai_db()->insert('operation_logs', [
        'admin_id'   => 0,
        'action'     => '易支付回调',
        'detail'     => '订单 ' . $orderNo . ' 支付成功自动发码',
        'ip'         => dcai_client_ip(),
        'created_at' => dcai_now(),
    ]);
} catch (Throwable $e) {
}

exit('success');
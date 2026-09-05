<?php
/**
 * 后台订单管理
 */
$pageTitle = '订单管理';
$activeMenu = 'order';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('order');

$db = dcai_db();

// 人工发货：标记订单已支付+发码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ship') {
    DCAI_Csrf::check();
    $orderId = (int)($_POST['id'] ?? 0);
    $order = $db->queryOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$order) {
        dcai_flash('danger', '订单不存在');
    } elseif ((int)$order['pay_status'] !== 0) {
        // 已支付：直接发码
        if ((int)$order['ship_status'] === 0) {
            $note = trim((string)($_POST['ship_note'] ?? ''));
            $expireAt = null;
            if ((int)$order['duration_days'] > 0) {
                $expireAt = date('Y-m-d H:i:s', strtotime("+{$order['duration_days']} days"));
            }
            [$ok, $lid] = DCAI_LicenseService::create([
                'product_id'     => (int)$order['product_id'],
                'customer_name'  => '订单#' . $order['order_no'],
                'customer_email' => (string)$db->queryValue('SELECT email FROM buyers WHERE id = ?', [(int)$order['buyer_id']]),
                'max_instances'  => 1,
                'expire_at'      => $expireAt,
                'remark'         => '订单 ' . $order['order_no'],
                'status'         => 1,
                'source'         => 2,
                'buyer_id'       => (int)$order['buyer_id'],
                'order_id'       => (int)$order['id'],
            ]);
            if ($ok) {
                $db->update('orders', [
                    'license_id'  => (int)$lid,
                    'ship_status' => 2,
                    'ship_note'   => mb_substr($note, 0, 250) ?: '人工发货',
                    'pay_status'  => 1,
                    'paid_at'     => dcai_now(),
                    'updated_at'  => dcai_now(),
                ], 'id = ?', [$orderId]);
                DCAI_Admin::opLog('人工发货', ['order_id' => $orderId, 'license_id' => $lid]);
                dcai_flash('success', '已生成授权码并完成发货');
            } else {
                dcai_flash('danger', '授权码生成失败: ' . $lid);
            }
        } else {
            dcai_flash('warning', '该订单已发货');
        }
    } else {
        // 手动标记已支付+发码
        $db->update('orders', ['pay_status' => 1, 'paid_at' => dcai_now(), 'pay_channel' => 'manual', 'updated_at' => dcai_now()], 'id = ? AND pay_status = 0', [$orderId]);
        dcai_flash('success', '已标记为已支付，请再发码');
    }
    header('Location: ' . DCAI_Admin::adminUrl('orders.php'));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$offset = ($page - 1) * $per;
$whereSql = '';
$params = [];
$status = (string)($_GET['status'] ?? '');
if ($status === 'paid') { $whereSql = ' WHERE pay_status = 1'; }
elseif ($status === 'pending') { $whereSql = ' WHERE pay_status = 0'; }
elseif ($status === 'shipped') { $whereSql = ' WHERE ship_status > 0'; }

$total = (int)$db->queryValue("SELECT COUNT(*) FROM orders $whereSql", $params);
$orders = $db->query("SELECT o.*, b.username AS buyer_name, CONCAT(LEFT(l.license_key,6),'****',RIGHT(l.license_key,4)) AS license_key_masked FROM orders o LEFT JOIN buyers b ON b.id = o.buyer_id LEFT JOIN licenses l ON l.id = o.license_id $whereSql ORDER BY o.id DESC LIMIT $per OFFSET $offset", $params);

// 统计
$stats = [
    'total'  => $total,
    'paid'   => (int)$db->queryValue("SELECT COUNT(*) FROM orders WHERE pay_status = 1"),
    'pending' => (int)$db->queryValue("SELECT COUNT(*) FROM orders WHERE pay_status = 0"),
    'revenue' => (float)$db->queryValue("SELECT COALESCE(SUM(amount),0) FROM orders WHERE pay_status = 1"),
];

require __DIR__ . '/includes/header.php';
?>
<div class="stats">
    <div class="stat-card blue"><div class="num"><?php echo $stats['total']; ?></div><div class="label">总订单</div></div>
    <div class="stat-card green"><div class="num"><?php echo $stats['paid']; ?></div><div class="label">已支付</div></div>
    <div class="stat-card orange"><div class="num"><?php echo $stats['pending']; ?></div><div class="label">待支付</div></div>
    <div class="stat-card red"><div class="num">¥<?php echo number_format($stats['revenue'], 2); ?></div><div class="label">总收入</div></div>
</div>

<div class="toolbar">
    <div class="filter-group">
        <a href="<?php echo DCAI_Admin::adminUrl('orders.php'); ?>" class="btn btn-outline btn-sm <?php echo $status === '' ? 'active' : ''; ?>">全部</a>
        <a href="?status=pending" class="btn btn-outline btn-sm <?php echo $status === 'pending' ? 'active' : ''; ?>">待支付</a>
        <a href="?status=paid" class="btn btn-outline btn-sm <?php echo $status === 'paid' ? 'active' : ''; ?>">已支付</a>
        <a href="?status=shipped" class="btn btn-outline btn-sm <?php echo $status === 'shipped' ? 'active' : ''; ?>">已发货</a>
    </div>
    <div class="spacer"></div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>订单号</th><th>买家</th><th>产品</th><th>金额</th><th>支付</th><th>发货</th><th>授权码</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?php echo (int)$o['id']; ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($o['order_no']); ?></td>
                    <td><?php echo DCAI_Util::e($o['buyer_name'] ?: $o['buyer_id']); ?></td>
                    <td><?php echo DCAI_Util::e($o['product_name']); ?></td>
                    <td>¥<?php echo number_format((float)$o['amount'], 2); ?></td>
                    <td><?php echo (int)$o['pay_status'] === 1 ? '<span class="badge green">已支付</span>' : '<span class="badge orange">待支付</span>'; ?></td>
                    <td><?php echo (int)$o['ship_status'] > 0 ? '<span class="badge green">已发货</span>' : '<span class="badge gray">未发货</span>'; ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($o['license_key_masked'] ?: '-'); ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($o['created_at']); ?></td>
                    <td>
                        <?php if ((int)$o['ship_status'] === 0): ?>
                        <form method="post" style="display:inline;">
                            <?php echo DCAI_Csrf::field(); ?>
                            <input type="hidden" name="action" value="ship">
                            <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                            <button class="btn btn-outline btn-xs" <?php echo (int)$o['pay_status'] === 0 ? 'data-confirm="确认手动标记已支付并发码？"' : ''; ?>><?php echo (int)$o['pay_status'] === 0 ? '确认支付并发码' : '立即发码'; ?></button>
                        </form>
                        <?php else: ?>
                        <span class="muted">已发货</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="10" class="empty">暂无订单</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
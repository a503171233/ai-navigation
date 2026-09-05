<?php
$pageTitle = '仪表盘';
$activeMenu = 'dashboard';
require __DIR__ . '/includes/init.php';

$db = dcai_db();

// 离线判定（惰性，复用服务层方法）
DCAI_InstanceService::markOffline();

$stats = [
    'products'   => (int)$db->queryValue('SELECT COUNT(*) FROM products'),
    'licenses'   => (int)$db->queryValue('SELECT COUNT(*) FROM licenses'),
    'instances'  => (int)$db->queryValue('SELECT COUNT(*) FROM instances'),
    'online'     => (int)$db->queryValue('SELECT COUNT(*) FROM instances WHERE status = 1'),
    'offline'    => (int)$db->queryValue('SELECT COUNT(*) FROM instances WHERE status = 0'),
    'disabled'   => (int)$db->queryValue('SELECT COUNT(*) FROM instances WHERE status = 2'),
    'todayVerifyOk'   => (int)$db->queryValue("SELECT COUNT(*) FROM verify_logs WHERE result = 1 AND created_at >= ?", [date('Y-m-d 00:00:00')]),
    'todayVerifyFail' => (int)$db->queryValue("SELECT COUNT(*) FROM verify_logs WHERE result = 0 AND created_at >= ?", [date('Y-m-d 00:00:00')]),
    'pendingCmds' => (int)$db->queryValue('SELECT COUNT(*) FROM instance_commands WHERE status IN (0,1)'),
    'popups'      => (int)$db->queryValue('SELECT COUNT(*) FROM popups WHERE status = 1'),
    'modules'     => (int)$db->queryValue('SELECT COUNT(*) FROM remote_modules WHERE status = 1'),
    'updates'     => (int)$db->queryValue('SELECT COUNT(*) FROM updates WHERE status = 1'),
];

// 商城统计（表不存在时静默 0）
$storeStats = ['orders' => 0, 'paid_orders' => 0, 'revenue' => 0.0, 'buyers' => 0];
try {
    $storeStats['orders'] = (int)$db->queryValue('SELECT COUNT(*) FROM orders');
    $storeStats['paid_orders'] = (int)$db->queryValue('SELECT COUNT(*) FROM orders WHERE pay_status = 1');
    $storeStats['revenue'] = (float)$db->queryValue('SELECT COALESCE(SUM(amount),0) FROM orders WHERE pay_status = 1');
    $storeStats['buyers'] = (int)$db->queryValue('SELECT COUNT(*) FROM buyers');
} catch (Throwable $e) {
    // orders/buyers 表不存在（未升级 V1.1）
}

// 近 7 天验证趋势（按天分组）
$trend = [];
try {
    $rows = $db->query(
        "SELECT DATE(created_at) d,
                SUM(CASE WHEN result = 1 THEN 1 ELSE 0 END) AS ok_cnt,
                SUM(CASE WHEN result = 0 THEN 1 ELSE 0 END) AS fail_cnt
         FROM verify_logs WHERE created_at >= ?
         GROUP BY DATE(created_at) ORDER BY d ASC LIMIT 14",
        [date('Y-m-d', strtotime('-13 days'))]
    );
    foreach ($rows as $row) {
        $trend[] = ['d' => $row['d'], 'ok' => (int)$row['ok_cnt'], 'fail' => (int)$row['fail_cnt']];
    }
} catch (Throwable $e) {
}

$recentVerify = $db->query('SELECT * FROM verify_logs ORDER BY id DESC LIMIT 8');
$recentInstances = $db->query('SELECT i.*, p.name AS product_name, l.license_key FROM instances i LEFT JOIN products p ON p.id = i.product_id LEFT JOIN licenses l ON l.id = i.license_id ORDER BY i.last_heartbeat_at DESC LIMIT 6');
$recentOps = $db->query('SELECT o.*, a.username FROM operation_logs o LEFT JOIN admin_users a ON a.id = o.admin_id ORDER BY o.id DESC LIMIT 6');
$recentCommands = $db->query('SELECT c.*, i.domain FROM instance_commands c LEFT JOIN instances i ON i.id = c.instance_id ORDER BY c.id DESC LIMIT 6');

require __DIR__ . '/includes/header.php';
?>
<div class="stats">
    <div class="stat-card purple"><div class="num"><?php echo $stats['products']; ?></div><div class="label">产品数</div></div>
    <div class="stat-card orange"><div class="num"><?php echo $stats['licenses']; ?></div><div class="label">授权码</div></div>
    <div class="stat-card blue"><div class="num"><?php echo $stats['instances']; ?></div><div class="label">实例总数</div></div>
    <div class="stat-card green"><div class="num"><?php echo $stats['online']; ?></div><div class="label">在线实例</div></div>
    <div class="stat-card red"><div class="num"><?php echo $stats['disabled']; ?></div><div class="label">已禁用实例</div></div>
    <div class="stat-card"><div class="num"><?php echo $stats['todayVerifyOk']; ?>/<?php echo $stats['todayVerifyFail']; ?></div><div class="label">今日验证通过/失败</div></div>
    <div class="stat-card orange"><div class="num"><?php echo $stats['pendingCmds']; ?></div><div class="label">待执行命令</div></div>
    <div class="stat-card purple"><div class="num"><?php echo $stats['modules']; ?></div><div class="label">启用模块</div></div>
    <?php if ($storeStats['orders'] > 0 || $storeStats['buyers'] > 0): ?>
    <div class="stat-card green"><div class="num">¥<?php echo number_format($storeStats['revenue'], 2); ?></div><div class="label">商城收入(<?php echo $storeStats['paid_orders']; ?>单)</div></div>
    <div class="stat-card blue"><div class="num"><?php echo $storeStats['buyers']; ?></div><div class="label">商城买家</div></div>
    <?php endif; ?>
</div>

<?php if ($trend): ?>
<div class="card">
    <div class="card-title">近 14 天授权验证趋势</div>
    <div style="display:flex;align-items:flex-end;gap:8px;height:140px;padding:10px 4px 0;">
        <?php
        $maxV = max(array_map(fn($t) => max($t['ok'], $t['fail']), $trend));
        $maxV = max(1, $maxV);
        foreach ($trend as $t):
            $okH = (int)round($t['ok'] / $maxV * 100);
            $failH = (int)round($t['fail'] / $maxV * 100);
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;">
            <div style="display:flex;align-items:flex-end;gap:2px;height:120px;width:100%;">
                <div title="通过 <?php echo $t['ok']; ?>" style="width:45%;background:#22c55e;border-radius:2px 2px 0 0;height:<?php echo $okH; ?>%;"></div>
                <div title="失败 <?php echo $t['fail']; ?>" style="width:45%;background:#ef4444;border-radius:2px 2px 0 0;height:<?php echo $failH; ?>%;"></div>
            </div>
            <div class="muted" style="font-size:11px;"><?php echo DCAI_Util::e(substr($t['d'], 5)); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="help-text">绿色=验证通过，红色=验证失败</div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">最近授权验证 <a href="<?php echo DCAI_Admin::adminUrl('logs.php'); ?>?type=verify" class="btn btn-outline btn-sm">查看全部</a></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>时间</th><th>产品</th><th>授权码</th><th>域名</th><th>IP</th><th>结果</th><th>原因</th></tr></thead>
            <tbody>
            <?php foreach ($recentVerify as $row): ?>
                <tr>
                    <td><?php echo DCAI_Util::e($row['created_at']); ?></td>
                    <td><?php echo (int)$row['product_id']; ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($row['license_key_masked']); ?></td>
                    <td><?php echo DCAI_Util::e($row['domain']); ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($row['ip']); ?></td>
                    <td><?php echo (int)$row['result'] === 1 ? '<span class="badge green">通过</span>' : '<span class="badge red">失败</span>'; ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($row['reason']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentVerify): ?><tr><td colspan="7" class="empty">暂无验证记录</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-title">最近活跃实例 <a href="<?php echo DCAI_Admin::adminUrl('instances.php'); ?>" class="btn btn-outline btn-sm">实例管理</a></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>实例 ID</th><th>产品</th><th>域名</th><th>版本</th><th>状态</th><th>最后心跳</th></tr></thead>
            <tbody>
            <?php foreach ($recentInstances as $row):
                $st = (int)$row['status'];
                $badge = $st === 1 ? '<span class="badge green">在线</span>' : ($st === 2 ? '<span class="badge red">已禁用</span>' : '<span class="badge gray">离线</span>');
                ?>
                <tr>
                    <td class="mono"><?php echo DCAI_Util::e(substr($row['instance_id'], 0, 8)); ?>…</td>
                    <td><?php echo DCAI_Util::e($row['product_name'] ?: $row['product_id']); ?></td>
                    <td><?php echo DCAI_Util::e($row['domain']); ?></td>
                    <td><?php echo DCAI_Util::e($row['version']); ?></td>
                    <td><?php echo $badge; ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($row['last_heartbeat_at'] ?: '-'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentInstances): ?><tr><td colspan="6" class="empty">暂无实例</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-title">最近后台操作</div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>时间</th><th>操作人</th><th>动作</th><th>详情</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($recentOps as $row): ?>
                <tr>
                    <td><?php echo DCAI_Util::e($row['created_at']); ?></td>
                    <td><?php echo DCAI_Util::e($row['username'] ?: $row['admin_id']); ?></td>
                    <td><?php echo DCAI_Util::e($row['action']); ?></td>
                    <td class="muted" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e(mb_substr($row['detail'] ?? '', 0, 60)); ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($row['ip']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentOps): ?><tr><td colspan="5" class="empty">暂无操作记录</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

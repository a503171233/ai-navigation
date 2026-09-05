<?php
$pageTitle = '命令中心';
$activeMenu = 'command';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('command');

$db = dcai_db();

// 中止命令
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    DCAI_CommandService::cancel($id);
    DCAI_Admin::opLog('中止命令', ['command_id' => $id]);
    dcai_flash('success', '命令已中止');
    header('Location: ' . DCAI_Admin::adminUrl('commands.php'));
    exit;
}

// 超时标记
if (($_GET['act'] ?? '') === 'timeout') {
    $n = DCAI_CommandService::markTimeout();
    dcai_flash('success', "已标记 {$n} 条超时命令");
    header('Location: ' . DCAI_Admin::adminUrl('commands.php'));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$where = [];
$params = [];
if (!empty($_GET['instance_id'])) { $where[] = 'c.instance_id = ?'; $params[] = (int)$_GET['instance_id']; }
if (!empty($_GET['type'])) { $where[] = 'c.command_type = ?'; $params[] = $_GET['type']; }
if (isset($_GET['status']) && $_GET['status'] !== '') { $where[] = 'c.status = ?'; $params[] = (int)$_GET['status']; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->queryValue("SELECT COUNT(*) FROM instance_commands c $whereSql", $params);
$offset = ($page - 1) * $per;
$commands = $db->query("SELECT c.*, i.domain, i.instance_id AS uuid, i.product_id
    FROM instance_commands c LEFT JOIN instances i ON i.id = c.instance_id
    $whereSql ORDER BY c.id DESC LIMIT $per OFFSET $offset", $params);

$statusMap = [
    0 => ['待执行', 'gray'], 1 => ['已下发', 'blue'], 2 => ['执行成功', 'green'],
    3 => ['执行失败', 'red'], 4 => ['超时/中止', 'orange'],
];

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <form class="filters" method="get" style="display:flex;gap:10px;">
        <input type="text" name="instance_id" placeholder="实例ID" value="<?php echo DCAI_Util::e($_GET['instance_id'] ?? ''); ?>" style="width:90px;">
        <select name="type">
            <option value="">全部类型</option>
            <?php foreach (DCAI_CommandService::TYPES as $t): ?>
                <option value="<?php echo $t; ?>" <?php echo ($_GET['type'] ?? '') === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">全部状态</option>
            <?php foreach ($statusMap as $k => [$label]): ?>
                <option value="<?php echo $k; ?>" <?php echo ($_GET['status'] ?? '') === (string)$k ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline">查询</button>
    </form>
    <div class="spacer"></div>
    <a class="btn btn-outline" href="<?php echo DCAI_Admin::adminUrl('commands.php'); ?>?act=timeout" data-confirm="将已下发超过 60 秒的命令标记为超时？">标记超时</a>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>实例</th><th>类型</th><th>参数</th><th>状态</th><th>结果</th><th>下发时间</th><th>领取</th><th>执行</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($commands as $cmd):
                [$label, $cls] = $statusMap[(int)$cmd['status']] ?? ['未知', 'gray'];
                $payload = json_decode($cmd['payload'] ?? '', true) ?: [];
                $result = json_decode($cmd['result'] ?? '', true);
                ?>
                <tr>
                    <td><?php echo (int)$cmd['id']; ?></td>
                    <td class="muted"><?php echo $cmd['domain'] ? DCAI_Util::e($cmd['domain']) : '#' . (int)$cmd['instance_id']; ?></td>
                    <td><span class="badge blue"><?php echo DCAI_Util::e($cmd['command_type']); ?></span></td>
                    <td class="mono" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e(json_encode($payload, JSON_UNESCAPED_UNICODE)); ?></td>
                    <td><span class="badge <?php echo $cls; ?>"><?php echo $label; ?></span></td>
                    <td class="mono" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;"><?php echo $result !== null ? DCAI_Util::e(json_encode($result, JSON_UNESCAPED_UNICODE)) : '-'; ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($cmd['issued_at']); ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($cmd['picked_at'] ?: '-'); ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($cmd['executed_at'] ?: '-'); ?></td>
                    <td>
                        <?php if (in_array((int)$cmd['status'], [0, 1], true)): ?>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?php echo (int)$cmd['id']; ?>">
                                <button class="btn btn-danger-outline btn-xs" data-confirm="确认中止该命令？">中止</button>
                            </form>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$commands): ?><tr><td colspan="10" class="empty">暂无命令记录</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

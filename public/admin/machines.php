<?php
$pageTitle = '机器绑定';
$activeMenu = 'machine';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('machine');

$db = dcai_db();

// 解绑
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unbind') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $row = $db->queryOne('SELECT * FROM machine_bindings WHERE id = ?', [$id]);
    if ($row) {
        DCAI_MachineService::unbind((int)$row['license_id'], (string)$row['machine_code']);
        DCAI_Admin::opLog('解绑机器', ['license_id' => $row['license_id'], 'machine' => substr($row['machine_code'], 0, 12)]);
        dcai_flash('success', '机器已解绑');
    } else {
        dcai_flash('danger', '绑定记录不存在');
    }
    header('Location: ' . DCAI_Admin::adminUrl('machines.php'));
    exit;
}

// 强制绑定（手工录入）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bind') {
    DCAI_Csrf::check();
    $licenseId = (int)($_POST['license_id'] ?? 0);
    $machineCode = strtolower(trim((string)($_POST['machine_code'] ?? '')));
    $machineName = trim((string)($_POST['machine_name'] ?? ''));
    if ($licenseId <= 0 || !preg_match('/^[a-f0-9]{32,64}$/', $machineCode)) {
        dcai_flash('danger', '请选择授权码并填写合法的机器码（32-64 位十六进制）');
    } else {
        DCAI_MachineService::bind($licenseId, $machineCode, $machineName);
        DCAI_Admin::opLog('手工绑定机器', ['license_id' => $licenseId, 'machine' => substr($machineCode, 0, 12)]);
        dcai_flash('success', '机器绑定成功');
    }
    header('Location: ' . DCAI_Admin::adminUrl('machines.php'));
    exit;
}

// 筛选
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$where = [];
$params = [];
if (!empty($_GET['license_id'])) { $where[] = 'm.license_id = ?'; $params[] = (int)$_GET['license_id']; }
$kw = trim($_GET['kw'] ?? '');
if ($kw !== '') {
    $where[] = '(m.machine_code LIKE ? OR m.machine_name LIKE ? OR l.license_key LIKE ? OR p.name LIKE ?)';
    $like = "%$kw%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter === 'bound') { $where[] = 'm.status = 1'; }
elseif ($statusFilter === 'unbound') { $where[] = 'm.status = 0'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->queryValue("SELECT COUNT(*) FROM machine_bindings m $whereSql", $params);
$offset = ($page - 1) * $per;
$rows = $db->query(
    "SELECT m.*, l.license_key, p.name AS product_name
     FROM machine_bindings m
     LEFT JOIN licenses l ON l.id = m.license_id
     LEFT JOIN products p ON p.id = l.product_id
     $whereSql ORDER BY m.last_seen_at DESC LIMIT $per OFFSET $offset",
    $params
);

$licenses = $db->query(
    "SELECT l.id, l.license_key, p.name AS product_name FROM licenses l
     LEFT JOIN products p ON p.id = l.product_id
     WHERE l.status = 1 ORDER BY l.id DESC"
);

// ---------- 配额聚合（统计卡 + 行内进度条） ----------
$quotaMap = [];
$licWithLimit = $db->query(
    "SELECT l.id AS license_id, l.machine_limit,
            (SELECT COUNT(*) FROM machine_bindings mb WHERE mb.license_id = l.id AND mb.status = 1) AS bound_cnt,
            p.name AS product_name, l.license_key
     FROM licenses l LEFT JOIN products p ON p.id = l.product_id
     WHERE l.machine_limit > 0"
);
$fullCount = 0; $nearCount = 0;
foreach ($licWithLimit as $q) {
    $q['bound_cnt'] = (int)$q['bound_cnt'];
    $q['machine_limit'] = (int)$q['machine_limit'];
    $quotaMap[(int)$q['license_id']] = $q;
    if ($q['bound_cnt'] >= $q['machine_limit']) { $fullCount++; }
    elseif ($q['bound_cnt'] >= $q['machine_limit'] * 0.8) { $nearCount++; }
}
$boundTotal = (int)$db->queryValue('SELECT COUNT(*) FROM machine_bindings WHERE status = 1');
$unboundTotal = (int)$db->queryValue('SELECT COUNT(*) FROM machine_bindings WHERE status = 0');

require __DIR__ . '/includes/header.php';
?>
<div class="quota-stats">
    <div class="stat-card green"><div class="num"><?php echo $boundTotal; ?></div><div class="label">当前绑定机器</div></div>
    <div class="stat-card blue"><div class="num"><?php echo $unboundTotal; ?></div><div class="label">已解绑机器</div></div>
    <div class="stat-card <?php echo $fullCount > 0 ? 'red' : 'green'; ?>"><div class="num"><?php echo $fullCount; ?></div><div class="label">已达上限授权码</div></div>
    <div class="stat-card <?php echo $nearCount > 0 ? 'orange' : 'green'; ?>"><div class="num"><?php echo $nearCount; ?></div><div class="label">接近上限（≥80%）</div></div>
</div>

<div class="toolbar">
    <form class="filters" method="get" style="display:flex;gap:10px;">
        <select name="license_id">
            <option value="">全部授权码</option>
            <?php foreach ($licenses as $lic): ?>
                <option value="<?php echo (int)$lic['id']; ?>" <?php echo (int)($_GET['license_id'] ?? 0) === (int)$lic['id'] ? 'selected' : ''; ?>><?php echo DCAI_Util::e($lic['product_name'] . ' · ' . DCAI_LicenseService::mask($lic['license_key'])); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">全部状态</option>
            <option value="bound" <?php echo $statusFilter === 'bound' ? 'selected' : ''; ?>>已绑定</option>
            <option value="unbound" <?php echo $statusFilter === 'unbound' ? 'selected' : ''; ?>>已解绑</option>
        </select>
        <input type="text" name="kw" placeholder="机器码/机器名/授权码/产品" value="<?php echo DCAI_Util::e($kw); ?>">
        <button class="btn btn-outline">查询</button>
    </form>
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="bindModal">＋ 手工绑定机器</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>授权码</th><th>产品</th><th>机器码</th><th>机器名</th><th>机器配额</th><th>首次绑定</th><th>最后活跃</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $q = $quotaMap[(int)$r['license_id']] ?? null; ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td class="mono"><?php echo DCAI_Util::e(DCAI_LicenseService::mask($r['license_key'] ?? '')); ?></td>
                    <td><?php echo DCAI_Util::e($r['product_name'] ?: '-'); ?></td>
                    <td>
                        <div class="mc-cell">
                            <span class="mc-val" id="mc-<?php echo (int)$r['id']; ?>" data-short="<?php echo DCAI_Util::e(substr($r['machine_code'], 0, 16) . '…'); ?>"><?php echo DCAI_Util::e(substr($r['machine_code'], 0, 16) . '…'); ?></span>
                            <button type="button" class="copy-btn" data-copy="<?php echo DCAI_Util::e($r['machine_code']); ?>">复制</button>
                            <button type="button" class="reveal-btn" data-reveal="mc-<?php echo (int)$r['id']; ?>" data-full="<?php echo DCAI_Util::e($r['machine_code']); ?>" data-show-label="显示完整" data-hide-label="收起">显示完整</button>
                        </div>
                    </td>
                    <td><?php echo DCAI_Util::e($r['machine_name'] ?: '-'); ?></td>
                    <td>
                        <?php if ($q): $pct = (int)min(100, round($q['bound_cnt'] / $q['machine_limit'] * 100)); ?>
                            <div class="quota-bar">
                                <div class="bar"><i class="<?php echo $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warn' : ''); ?>" style="width:<?php echo $pct; ?>%"></i></div>
                                <span class="txt"><?php echo $q['bound_cnt']; ?>/<?php echo $q['machine_limit']; ?></span>
                            </div>
                        <?php else: ?>
                            <span class="muted">不限</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?php echo DCAI_Util::e($r['first_seen_at']); ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($r['last_seen_at'] ?: '-'); ?></td>
                    <td><?php echo (int)$r['status'] === 1 ? '<span class="badge green">已绑定</span>' : '<span class="badge gray">已解绑</span>'; ?></td>
                    <td>
                        <?php if ((int)$r['status'] === 1): ?>
                        <form method="post" style="display:inline;">
                            <?php echo DCAI_Csrf::field(); ?>
                            <input type="hidden" name="action" value="unbind">
                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                            <button class="btn btn-danger-outline btn-xs" data-confirm-danger="解绑后该机器将无法通过此授权码验证，确定解绑？">解绑</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="10" class="empty">暂无机器绑定记录</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>

<div class="modal-mask" id="bindModal">
    <div class="modal">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="bind">
            <div class="modal-head"><h3>手工绑定机器</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-row full"><label>授权码 <span class="req">*</span></label>
                        <select name="license_id" required><option value="">请选择</option>
                            <?php foreach ($licenses as $lic): ?><option value="<?php echo (int)$lic['id']; ?>"><?php echo DCAI_Util::e($lic['product_name'] . ' · ' . DCAI_LicenseService::mask($lic['license_key'])); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row full"><label>机器码 <span class="req">*</span></label>
                        <input type="text" name="machine_code" required placeholder="32-64 位十六进制指纹（客户端生成后上报）" class="mono">
                    </div>
                    <div class="form-row full"><label>机器名（可选）</label><input type="text" name="machine_name" placeholder="如：客户服务器 A"></div>
                </div>
                <div class="help-text">机器码由被授权程序的 SDK 自动采集硬件指纹生成（HMAC-SHA256 摘要），正常情况下无需手工录入；此功能用于线下/客服协助场景。</div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">绑定</button></div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
$pageTitle = '授权码管理';
$activeMenu = 'license';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('license');

$db = dcai_db();

// 创建
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    DCAI_Csrf::check();
    $count = min(100, max(1, (int)($_POST['batch_count'] ?? 1)));
    $data = [
        'product_id'      => (int)($_POST['product_id'] ?? 0),
        'customer_name'   => trim($_POST['customer_name'] ?? ''),
        'customer_email'  => trim($_POST['customer_email'] ?? ''),
        'allowed_domains' => trim($_POST['allowed_domains'] ?? ''),
        'allowed_ips'     => trim($_POST['allowed_ips'] ?? ''),
        'max_instances'   => (int)($_POST['max_instances'] ?? 1),
        'machine_limit'   => (int)($_POST['machine_limit'] ?? 1),
        'trial_days'      => (int)($_POST['trial_days'] ?? 0),
        'expire_at'       => trim($_POST['expire_at'] ?? ''),
        'remark'          => trim($_POST['remark'] ?? ''),
    ];
    if (!$data['product_id'] || $data['expire_at'] === '') {
        dcai_flash('danger', '请选择产品并设置到期时间');
    } else {
        if ($count === 1) {
            [$ok, $res] = DCAI_LicenseService::create($data);
            if ($ok) {
                DCAI_Admin::opLog('创建授权码', ['product_id' => $data['product_id'], 'id' => $res]);
                dcai_flash('success', '授权码生成成功');
            } else {
                dcai_flash('danger', $res);
            }
        } else {
            [$ok, $res] = DCAI_LicenseService::batchCreate($data, $count);
            if ($ok) {
                DCAI_Admin::opLog('批量创建授权码', ['count' => $count, 'product_id' => $data['product_id']]);
                dcai_flash('success', "已批量生成 {$count} 个授权码");
            } else {
                dcai_flash('danger', $res);
            }
        }
    }
    header('Location: ' . DCAI_Admin::adminUrl('licenses.php'));
    exit;
}

// 更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $db->update('licenses', [
        'customer_name'   => trim($_POST['customer_name'] ?? ''),
        'customer_email'  => trim($_POST['customer_email'] ?? ''),
        'allowed_domains' => trim($_POST['allowed_domains'] ?? ''),
        'allowed_ips'     => trim($_POST['allowed_ips'] ?? ''),
        'max_instances'   => max(1, (int)($_POST['max_instances'] ?? 1)),
        'machine_limit'   => max(0, (int)($_POST['machine_limit'] ?? 1)),
        'trial_days'      => max(0, (int)($_POST['trial_days'] ?? 0)),
        'expire_at'       => trim($_POST['expire_at'] ?? ''),
        'remark'          => trim($_POST['remark'] ?? ''),
        'status'          => (int)($_POST['status'] ?? 1),
        'updated_at'      => dcai_now(),
    ], 'id = ?', [$id]);
    DCAI_Admin::opLog('编辑授权码', ['id' => $id]);
    dcai_flash('success', '授权码已更新');
    header('Location: ' . DCAI_Admin::adminUrl('licenses.php'));
    exit;
}

// 启停
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['value'] ?? 0);
    $db->update('licenses', ['status' => $val, 'updated_at' => dcai_now()], 'id = ?', [$id]);
    DCAI_Admin::opLog('切换授权码状态', ['id' => $id, 'status' => $val]);
    dcai_flash('success', '操作成功');
    header('Location: ' . DCAI_Admin::adminUrl('licenses.php'));
    exit;
}

// 删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $db->delete('licenses', 'id = ?', [$id]);
    DCAI_Admin::opLog('删除授权码', ['id' => $id]);
    dcai_flash('success', '授权码已删除');
    header('Location: ' . DCAI_Admin::adminUrl('licenses.php'));
    exit;
}

// 导出 CSV
if (($_GET['export'] ?? '') === 'csv') {
    $sql = 'SELECT l.*, p.name AS product_name FROM licenses l LEFT JOIN products p ON p.id = l.product_id ORDER BY l.id DESC';
    $rows = $db->query($sql);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="licenses_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', '授权码', '产品', '客户', '邮箱', '允许域名', '允许IP', '最大实例', '到期时间', '状态', '备注']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['license_key'], $r['product_name'], $r['customer_name'], $r['customer_email'],
            $r['allowed_domains'], $r['allowed_ips'], $r['max_instances'], $r['expire_at'],
            (int)$r['status'] === 1 ? '有效' : '禁用', $r['remark'],
        ]);
    }
    fclose($out);
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$productFilter = (int)($_GET['product_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$kw = trim($_GET['kw'] ?? '');

$where = [];
$params = [];
if ($productFilter) { $where[] = 'l.product_id = ?'; $params[] = $productFilter; }
if ($statusFilter === 'valid') { $where[] = 'l.status = 1 AND l.expire_at >= ?'; $params[] = dcai_now(); }
elseif ($statusFilter === 'disabled') { $where[] = 'l.status = 0'; }
elseif ($statusFilter === 'expired') { $where[] = 'l.status = 1 AND l.expire_at < ?'; $params[] = dcai_now(); }
if ($kw !== '') { $where[] = '(l.license_key LIKE ? OR l.customer_name LIKE ? OR l.customer_email LIKE ?)'; $like = "%$kw%"; $params[] = $like; $params[] = $like; $params[] = $like; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->queryValue("SELECT COUNT(*) FROM licenses l $whereSql", $params);
$offset = ($page - 1) * $per;
$licenses = $db->query("SELECT l.*, p.name AS product_name FROM licenses l LEFT JOIN products p ON p.id = l.product_id $whereSql ORDER BY l.id DESC LIMIT $per OFFSET $offset", $params);

$products = $db->query('SELECT id, name FROM products ORDER BY id DESC');

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <form class="filters" method="get" style="display:flex;gap:10px;">
        <select name="product_id">
            <option value="">全部产品</option>
            <?php foreach ($products as $prod): ?>
                <option value="<?php echo (int)$prod['id']; ?>" <?php echo $productFilter === (int)$prod['id'] ? 'selected' : ''; ?>><?php echo DCAI_Util::e($prod['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">全部状态</option>
            <option value="valid" <?php echo $statusFilter === 'valid' ? 'selected' : ''; ?>>有效</option>
            <option value="disabled" <?php echo $statusFilter === 'disabled' ? 'selected' : ''; ?>>已禁用</option>
            <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>已过期</option>
        </select>
        <input type="text" name="kw" placeholder="授权码/客户/邮箱" value="<?php echo DCAI_Util::e($kw); ?>">
        <button class="btn btn-outline">查询</button>
    </form>
    <div class="spacer"></div>
    <a class="btn btn-outline" href="<?php echo DCAI_Admin::adminUrl('licenses.php'); ?>?export=csv<?php echo $productFilter ? '&product_id=' . $productFilter : ''; ?>" data-confirm="导出当前筛选的全部授权码？">导出 CSV</a>
    <button class="btn btn-primary" data-modal-open="createModal">＋ 生成授权码</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>授权码</th><th>产品</th><th>客户</th><th>最大实例</th><th>到期时间</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($licenses as $l):
                $now = time();
                $expired = strtotime($l['expire_at']) < $now;
                $badge = (int)$l['status'] === 0 ? '<span class="badge gray">已禁用</span>' : ($expired ? '<span class="badge red">已过期</span>' : '<span class="badge green">有效</span>');
                // 试用标识：trial_days>0 且未过试用截止时间
                $trialDays = (int)($l['trial_days'] ?? 0);
                $trialBadge = '';
                if ($trialDays > 0) {
                    $trialEnd = strtotime($l['created_at']) + $trialDays * 86400;
                    $trialRemain = (int)ceil(($trialEnd - $now) / 86400);
                    $trialBadge = $trialRemain > 0
                        ? '<span class="badge blue">试用中 · 剩 ' . $trialRemain . ' 天</span>'
                        : '<span class="badge orange">试用已到期</span>';
                }
                ?>
                <tr>
                    <td><?php echo (int)$l['id']; ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($l['license_key']); ?></td>
                    <td><?php echo DCAI_Util::e($l['product_name'] ?: $l['product_id']); ?></td>
                    <td><?php echo DCAI_Util::e($l['customer_name'] ?: '-'); ?></td>
                    <td><?php echo (int)$l['max_instances']; ?> <span class="muted">/ 机器<?php echo (int)($l['machine_limit'] ?? 0) > 0 ? (int)$l['machine_limit'] : '∞'; ?></span></td>
                    <td class="muted"><?php echo DCAI_Util::e($l['expire_at']); ?></td>
                    <td><?php echo $badge; ?> <?php echo $trialBadge; ?></td>
                    <td>
                        <div class="actions">
                            <button class="btn btn-outline btn-xs" data-modal-open="edit-<?php echo (int)$l['id']; ?>">编辑</button>
                            <a class="btn btn-outline btn-xs" href="<?php echo DCAI_Admin::adminUrl('instances.php'); ?>?license_id=<?php echo (int)$l['id']; ?>">实例</a>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
                                <input type="hidden" name="value" value="<?php echo (int)$l['status'] ? 0 : 1; ?>">
                                <button class="btn btn-outline btn-xs"><?php echo (int)$l['status'] ? '禁用' : '启用'; ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
                                <button class="btn btn-danger-outline btn-xs" data-confirm-danger="删除该授权码？关联实例将失去授权依据。">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$licenses): ?><tr><td colspan="8" class="empty">暂无授权码</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>

<div class="modal-mask" id="createModal">
    <div class="modal">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-head"><h3>生成授权码</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-row"><label>产品 <span class="req">*</span></label>
                        <select name="product_id" required><option value="">请选择</option>
                            <?php foreach ($products as $prod): ?><option value="<?php echo (int)$prod['id']; ?>"><?php echo DCAI_Util::e($prod['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row"><label>批量数量</label><input type="number" name="batch_count" value="1" min="1" max="100"></div>
                    <div class="form-row"><label>客户名称</label><input type="text" name="customer_name"></div>
                    <div class="form-row"><label>客户邮箱</label><input type="email" name="customer_email"></div>
                    <div class="form-row"><label>最大实例数</label><input type="number" name="max_instances" value="1" min="1"></div>
                    <div class="form-row"><label>绑定机器上限（0=不限）</label><input type="number" name="machine_limit" value="0" min="0" placeholder="0"></div>
                    <div class="form-row"><label>试用天数（0=正式授权）</label><input type="number" name="trial_days" value="0" min="0" placeholder="0"></div>
                    <div class="form-row"><label>到期时间 <span class="req">*</span></label><input type="datetime-local" name="expire_at" required></div>
                    <div class="form-row full"><label>允许域名（每行一个，支持 *.example.com）</label><textarea name="allowed_domains" rows="3"></textarea></div>
                    <div class="form-row full"><label>允许 IP（每行一个，支持 CIDR 如 1.2.3.0/24）</label><textarea name="allowed_ips" rows="3"></textarea></div>
                    <div class="form-row full"><label>备注</label><input type="text" name="remark"></div>
                </div>
                <div class="help-text">授权码格式：DCAI-XXXXX-XXXXX-XXXXX-XXXXX（base32 随机，全局唯一）</div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">生成</button></div>
        </form>
    </div>
</div>

<?php foreach ($licenses as $l): ?>
<div class="modal-mask" id="edit-<?php echo (int)$l['id']; ?>">
    <div class="modal">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
            <div class="modal-head"><h3>编辑授权码</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-row"><label>授权码</label><input type="text" value="<?php echo DCAI_Util::e($l['license_key']); ?>" disabled></div>
                <div class="form-grid">
                    <div class="form-row"><label>客户名称</label><input type="text" name="customer_name" value="<?php echo DCAI_Util::e($l['customer_name']); ?>"></div>
                    <div class="form-row"><label>客户邮箱</label><input type="email" name="customer_email" value="<?php echo DCAI_Util::e($l['customer_email']); ?>"></div>
                    <div class="form-row"><label>最大实例数</label><input type="number" name="max_instances" value="<?php echo (int)$l['max_instances']; ?>" min="1"></div>
                    <div class="form-row"><label>绑定机器上限（0=不限）</label><input type="number" name="machine_limit" value="<?php echo (int)($l['machine_limit'] ?? 0); ?>" min="0"></div>
                    <div class="form-row"><label>试用天数（0=正式授权）</label><input type="number" name="trial_days" value="<?php echo (int)($l['trial_days'] ?? 0); ?>" min="0"></div>
                    <div class="form-row"><label>到期时间 <span class="req">*</span></label>
                        <input type="datetime-local" name="expire_at" value="<?php echo DCAI_Util::e(str_replace(' ', 'T', $l['expire_at'])); ?>" required>
                    </div>
                    <div class="form-row full"><label>允许域名</label><textarea name="allowed_domains" rows="3"><?php echo DCAI_Util::e($l['allowed_domains']); ?></textarea></div>
                    <div class="form-row full"><label>允许 IP</label><textarea name="allowed_ips" rows="3"><?php echo DCAI_Util::e($l['allowed_ips']); ?></textarea></div>
                    <div class="form-row"><label>状态</label>
                        <select name="status"><option value="1" <?php echo (int)$l['status'] ? 'selected' : ''; ?>>有效</option><option value="0" <?php echo !(int)$l['status'] ? 'selected' : ''; ?>>禁用</option></select>
                    </div>
                    <div class="form-row"><label>备注</label><input type="text" name="remark" value="<?php echo DCAI_Util::e($l['remark']); ?>"></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

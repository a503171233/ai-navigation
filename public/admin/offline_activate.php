<?php
/**
 * 离线激活管理（内网/断网场景）
 * 流程：客户端生成激活请求文件 → 管理员导入并签发 → 下载激活文件交回客户端
 */
$pageTitle = '离线激活';
$activeMenu = 'offline';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('offline');

$db = dcai_db();

// ---------- 导入激活请求文件并签发 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    DCAI_Csrf::check();
    $raw = trim((string)($_POST['request_json'] ?? ''));
    $machineName = trim((string)($_POST['machine_name'] ?? ''));
    $expireAt = trim((string)($_POST['expire_at'] ?? ''));
    if ($raw === '') {
        dcai_flash('danger', '请粘贴激活请求文件内容');
    } else {
        [$ok, $data] = DCAI_OfflineActivationService::parseRequest($raw);
        if (!$ok) {
            dcai_flash('danger', $data);
        } else {
            // 查授权码
            $license = $db->queryOne('SELECT * FROM licenses WHERE license_key = ?', [$data['license_key']]);
            if (!$license) {
                dcai_flash('danger', '授权码不存在：' . $data['license_key']);
            } elseif ((int)$license['status'] !== 1) {
                dcai_flash('danger', '授权码已禁用');
            } else {
                $mc = strtolower(trim((string)$data['machine_code']));
                if (!preg_match('/^[a-f0-9]{32,64}$/', $mc)) {
                    dcai_flash('danger', '机器码格式不合法（32-64 位十六进制）');
                } else {
                    [$ok2, $res] = DCAI_OfflineActivationService::issue(
                        (int)$license['id'],
                        $mc,
                        $machineName !== '' ? $machineName : (string)($data['machine_name'] ?? ''),
                        $expireAt !== '' ? $expireAt : null
                    );
                    if ($ok2) {
                        // 同时把机器加入机器绑定（离线场景机器也应受机器数约束）
                        DCAI_MachineService::bind((int)$license['id'], $mc, $machineName !== '' ? $machineName : (string)($data['machine_name'] ?? ''));
                        DCAI_Admin::opLog('离线激活签发', ['license_id' => (int)$license['id'], 'machine' => substr($mc, 0, 12)]);
                        dcai_flash('success', '离线激活文件已签发，可下载交回客户端');
                        $_SESSION['dcai_offline_last'] = $res;
                    } else {
                        dcai_flash('danger', $res);
                    }
                }
            }
        }
    }
    header('Location: ' . DCAI_Admin::adminUrl('offline_activate.php'));
    exit;
}

// ---------- 作废 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    DCAI_OfflineActivationService::revoke($id);
    DCAI_Admin::opLog('作废离线激活', ['id' => $id]);
    dcai_flash('success', '离线激活已作废');
    header('Location: ' . DCAI_Admin::adminUrl('offline_activate.php'));
    exit;
}

// ---------- 下载激活文件 ----------
if (($_GET['download'] ?? '') !== '') {
    $id = (int)$_GET['download'];
    $row = $db->queryOne('SELECT * FROM offline_activations WHERE id = ?', [$id]);
    if (!$row || empty($row['activate_file'])) {
        dcai_flash('danger', '激活记录不存在');
        header('Location: ' . DCAI_Admin::adminUrl('offline_activate.php'));
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="dcai_activation_' . $row['request_id'] . '.json"');
    echo $row['activate_file'];
    exit;
}

// ---------- 列表 ----------
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$where = [];
$params = [];
if (!empty($_GET['license_id'])) { $where[] = 'o.license_id = ?'; $params[] = (int)$_GET['license_id']; }
$kw = trim($_GET['kw'] ?? '');
if ($kw !== '') {
    $where[] = '(o.machine_code LIKE ? OR o.machine_name LIKE ? OR l.license_key LIKE ?)';
    $like = "%$kw%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter === 'active') { $where[] = 'o.status = 1'; }
elseif ($statusFilter === 'revoked') { $where[] = 'o.status = 0'; }
elseif ($statusFilter === 'expiring') { $where[] = 'o.status = 1 AND o.expire_at IS NOT NULL AND o.expire_at > NOW() AND o.expire_at <= DATE_ADD(NOW(), INTERVAL 30 DAY)'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->queryValue("SELECT COUNT(*) FROM offline_activations o $whereSql", $params);
$offset = ($page - 1) * $per;
$rows = $db->query(
    "SELECT o.*, l.license_key, p.name AS product_name
     FROM offline_activations o
     LEFT JOIN licenses l ON l.id = o.license_id
     LEFT JOIN products p ON p.id = o.product_id
     $whereSql ORDER BY o.id DESC LIMIT $per OFFSET $offset",
    $params
);

$licenses = $db->query(
    "SELECT l.id, l.license_key, p.name AS product_name FROM licenses l
     LEFT JOIN products p ON p.id = l.product_id
     WHERE l.status = 1 ORDER BY l.id DESC"
);

$lastActivation = $_SESSION['dcai_offline_last'] ?? null;
unset($_SESSION['dcai_offline_last']);

require __DIR__ . '/includes/header.php';
?>
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
            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>已签发</option>
            <option value="expiring" <?php echo $statusFilter === 'expiring' ? 'selected' : ''; ?>>即将到期（30 天内）</option>
            <option value="revoked" <?php echo $statusFilter === 'revoked' ? 'selected' : ''; ?>>已作废</option>
        </select>
        <input type="text" name="kw" placeholder="机器码/机器名/授权码" value="<?php echo DCAI_Util::e($kw); ?>">
        <button class="btn btn-outline">查询</button>
    </form>
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="importModal">＋ 导入请求签发</button>
</div>

<?php if ($lastActivation): ?>
<div class="alert alert-success">
    <strong>签发成功！</strong> 激活文件已生成，可直接复制下方 JSON，或在下方列表「下载」按钮获取文件：<br>
    <div style="position:relative;">
        <textarea class="mono" id="activation-file-json" rows="6" style="width:100%;font-size:12px;" readonly><?php echo DCAI_Util::e($lastActivation['file_json']); ?></textarea>
        <button type="button" class="copy-btn" style="position:absolute;top:8px;right:8px;background:#fff;" data-copy-from="#activation-file-json" data-copy-label="已复制 ✓">📋 一键复制</button>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>授权码</th><th>产品</th><th>机器码</th><th>机器名</th><th>请求ID</th><th>到期时间</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $expiring = false;
                if ((int)$r['status'] === 1 && !empty($r['expire_at'])) {
                    $ts = strtotime($r['expire_at']);
                    if ($ts !== false && $ts <= time() + 30 * 86400 && $ts > time()) { $expiring = true; }
                }
            ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td class="mono"><?php echo DCAI_Util::e(DCAI_LicenseService::mask($r['license_key'] ?? '')); ?></td>
                    <td><?php echo DCAI_Util::e($r['product_name'] ?: '-'); ?></td>
                    <td class="mono" title="<?php echo DCAI_Util::e($r['machine_code']); ?>"><?php echo DCAI_Util::e(substr($r['machine_code'], 0, 16) . '…'); ?></td>
                    <td><?php echo DCAI_Util::e($r['machine_name'] ?: '-'); ?></td>
                    <td class="mono muted"><?php echo DCAI_Util::e(substr($r['request_id'], 0, 8)); ?></td>
                    <td class="expire-cell">
                        <?php if ($r['expire_at']): ?>
                            <span class="<?php echo $expiring ? 'badge expiring' : 'muted'; ?>"><?php echo DCAI_Util::e($r['expire_at']); ?></span>
                            <?php if ($expiring): ?><div class="deadline">即将到期</div><?php endif; ?>
                        <?php else: ?>
                            <span class="muted">随授权码</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int)$r['status'] === 1 ? '<span class="badge green">已签发</span>' : '<span class="badge gray">已作废</span>'; ?></td>
                    <td>
                        <div class="actions">
                            <?php if ((int)$r['status'] === 1): ?>
                            <a class="btn btn-outline btn-xs" href="<?php echo DCAI_Admin::adminUrl('offline_activate.php'); ?>?download=<?php echo (int)$r['id']; ?>">下载</a>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button class="btn btn-danger-outline btn-xs" data-confirm-danger="作废后该激活文件将失效，确定？">作废</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="empty">暂无离线激活记录。请先让客户在被授权程序中生成「激活请求文件」，再点击右上角导入签发。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>

<div class="modal-mask" id="importModal">
    <div class="modal">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="import">
            <div class="modal-head"><h3>导入激活请求并签发</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-row full">
                    <label>激活请求 JSON <span class="req">*</span></label>
                    <textarea name="request_json" rows="8" required class="mono" placeholder='{"type":"dcai_offline_request","version":1,...}'></textarea>
                    <div class="help-text">由被授权程序 SDK 的「生成离线激活请求」功能导出，形如 dcai_offline_request.json</div>
                </div>
                <div class="form-grid">
                    <div class="form-row"><label>机器名称（可选，覆盖请求内名称）</label><input type="text" name="machine_name"></div>
                    <div class="form-row"><label>离线授权到期时间（可选）</label><input type="datetime-local" name="expire_at"></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">签发激活文件</button></div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

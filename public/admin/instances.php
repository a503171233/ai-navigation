<?php
$pageTitle = '实例管理';
$activeMenu = 'instance';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('instance');

$db = dcai_db();

// 离线判定
$threshold = (int)dcai_config('security.heartbeat_threshold', 180);
$deadline = date('Y-m-d H:i:s', time() - $threshold);
$db->execute('UPDATE instances SET status = 0, updated_at = ? WHERE status = 1 AND (last_heartbeat_at IS NULL OR last_heartbeat_at < ?)', [dcai_now(), $deadline]);

// 下发命令
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'command') {
    DCAI_Csrf::check();
    $instanceIds = array_filter(array_map('intval', (array)($_POST['instance_ids'] ?? [])));
    $type = (string)($_POST['command_type'] ?? '');
    $payload = [];
    if ($type === 'config_push' && !empty($_POST['config_json'])) {
        $payload['config'] = json_decode($_POST['config_json'], true) ?: [];
    }
    if (($type === 'maintenance_on' || $type === 'maintenance_off') && !empty($_POST['notice'])) {
        $payload['notice'] = $_POST['notice'];
    }
    if ($type === 'update' && !empty($_POST['target_version'])) {
        $payload['target_version'] = $_POST['target_version'];
    }
    if (!$instanceIds || !in_array($type, DCAI_CommandService::TYPES, true)) {
        dcai_flash('danger', '请选择实例并填写命令类型');
    } else {
        $count = 0;
        foreach ($instanceIds as $iid) {
            DCAI_CommandService::issue($iid, $type, $payload, DCAI_Admin::id());
            $count++;
        }
        DCAI_Admin::opLog('下发远程命令', ['type' => $type, 'instances' => $instanceIds, 'payload' => $payload]);
        dcai_flash('success', "已向 {$count} 个实例下发命令「{$type}」");
    }
    header('Location: ' . DCAI_Admin::adminUrl('instances.php'));
    exit;
}

// 远程启停实例
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $status = (int)($_POST['value'] ?? 0);
    DCAI_InstanceService::setStatus($id, $status);
    DCAI_Admin::opLog('远程设置实例状态', ['instance' => $id, 'status' => $status]);
    dcai_flash('success', $status === 2 ? '实例已远程禁用' : ($status === 1 ? '实例已恢复在线' : '实例已置为离线'));
    header('Location: ' . DCAI_Admin::adminUrl('instances.php'));
    exit;
}

// 筛选
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$where = [];
$params = [];
if (!empty($_GET['product_id'])) { $where[] = 'i.product_id = ?'; $params[] = (int)$_GET['product_id']; }
if (!empty($_GET['license_id'])) { $where[] = 'i.license_id = ?'; $params[] = (int)$_GET['license_id']; }
if (isset($_GET['status']) && $_GET['status'] !== '') { $where[] = 'i.status = ?'; $params[] = (int)$_GET['status']; }
$kw = trim($_GET['kw'] ?? '');
if ($kw !== '') {
    $where[] = '(i.domain LIKE ? OR i.ip LIKE ? OR i.instance_id LIKE ?)';
    $like = "%$kw%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->queryValue("SELECT COUNT(*) FROM instances i $whereSql", $params);
$offset = ($page - 1) * $per;
$instances = $db->query("SELECT i.*, p.name AS product_name, p.product_code, l.license_key
    FROM instances i
    LEFT JOIN products p ON p.id = i.product_id
    LEFT JOIN licenses l ON l.id = i.license_id
    $whereSql ORDER BY i.last_heartbeat_at DESC LIMIT $per OFFSET $offset", $params);

$products = $db->query('SELECT id, name FROM products ORDER BY id DESC');
$licenses = $db->query('SELECT id, license_key FROM licenses ORDER BY id DESC LIMIT 500');

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <form class="filters" method="get" style="display:flex;gap:10px;">
        <select name="product_id">
            <option value="">全部产品</option>
            <?php foreach ($products as $prod): ?>
                <option value="<?php echo (int)$prod['id']; ?>" <?php echo (int)($_GET['product_id'] ?? 0) === (int)$prod['id'] ? 'selected' : ''; ?>><?php echo DCAI_Util::e($prod['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">全部状态</option>
            <option value="1" <?php echo ($_GET['status'] ?? '') === '1' ? 'selected' : ''; ?>>在线</option>
            <option value="0" <?php echo ($_GET['status'] ?? '') === '0' ? 'selected' : ''; ?>>离线</option>
            <option value="2" <?php echo ($_GET['status'] ?? '') === '2' ? 'selected' : ''; ?>>已禁用</option>
        </select>
        <input type="text" name="kw" placeholder="域名/IP/实例ID" value="<?php echo DCAI_Util::e($kw); ?>">
        <button class="btn btn-outline">查询</button>
    </form>
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="commandModal">⚡ 下发命令</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th><input type="checkbox" data-check-all="instance_ids"></th>
                <th>实例 ID</th><th>产品</th><th>域名</th><th>IP</th><th>版本</th>
                <th>状态</th><th>最后心跳</th><th>授权码</th><th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($instances as $i):
                $st = (int)$i['status'];
                $badge = $st === 1 ? '<span class="badge green">在线</span>' : ($st === 2 ? '<span class="badge red">已禁用</span>' : '<span class="badge gray">离线</span>');
                $info = json_decode($i['server_info'] ?? '{}', true) ?: [];
                ?>
                <tr>
                    <td><input type="checkbox" name="instance_ids" value="<?php echo (int)$i['id']; ?>"></td>
                    <td class="mono"><?php echo DCAI_Util::e(substr($i['instance_id'], 0, 13)); ?>…</td>
                    <td><?php echo DCAI_Util::e($i['product_name'] ?: $i['product_id']); ?></td>
                    <td><?php echo DCAI_Util::e($i['domain']); ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($i['ip']); ?></td>
                    <td><?php echo DCAI_Util::e($i['version'] ?: '-'); ?></td>
                    <td><?php echo $badge; ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($i['last_heartbeat_at'] ?: '-'); ?></td>
                    <td class="mono" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e($i['license_key'] ? DCAI_Util::maskLicenseKey($i['license_key']) : '-'); ?></td>
                    <td>
                        <div class="actions">
                            <button class="btn btn-outline btn-xs" data-modal-open="detail-<?php echo (int)$i['id']; ?>">详情</button>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="id" value="<?php echo (int)$i['id']; ?>">
                                <input type="hidden" name="value" value="<?php echo $st === 2 ? 1 : 2; ?>">
                                <button class="btn <?php echo $st === 2 ? 'btn-success' : 'btn-danger-outline'; ?> btn-xs" data-confirm-danger="<?php echo $st === 2 ? '确认恢复该实例？' : '确认远程禁用该实例？被授权程序将立即停止运行。'; ?>">
                                    <?php echo $st === 2 ? '恢复' : '禁用'; ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$instances): ?><tr><td colspan="10" class="empty">暂无实例</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>

<!-- 下发命令 -->
<div class="modal-mask" id="commandModal">
    <div class="modal">
        <form method="post" onsubmit="return collectSelected()">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="command">
            <div class="modal-head"><h3>下发远程命令</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-row"><label>目标实例 <span class="req">*</span></label>
                    <div id="selected-instances" style="font-size:12.5px;color:var(--text-2);margin-bottom:6px;">当前页面勾选的实例将作为目标</div>
                    <input type="hidden" name="instance_ids" id="instance_ids_hidden">
                </div>
                <div class="form-row"><label>命令类型 <span class="req">*</span></label>
                    <select name="command_type" id="cmd-type">
                        <?php foreach (DCAI_CommandService::TYPES as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row" id="cfg-json-wrap" style="display:none;"><label>配置 JSON（config_push）</label><textarea name="config_json" class="code-area" rows="5">{"site_name": "新站名"}</textarea></div>
                <div class="form-row" id="notice-wrap" style="display:none;"><label>维护公告（maintenance_on/off）</label><input type="text" name="notice" value="系统升级中"></div>
                <div class="form-row" id="version-wrap" style="display:none;"><label>目标版本（update）</label><input type="text" name="target_version" placeholder="如 1.1.0"></div>
                <div class="alert alert-warning" id="danger-tip" style="display:none;">⚠️ disable 命令为 kill switch，被授权程序将拒绝继续运行！</div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">确认下发</button></div>
        </form>
    </div>
</div>

<?php foreach ($instances as $i): ?>
<div class="modal-mask" id="detail-<?php echo (int)$i['id']; ?>">
    <div class="modal">
        <div class="modal-head"><h3>实例详情 #<?php echo (int)$i['id']; ?></h3><button type="button" class="close" data-modal-close>×</button></div>
        <div class="modal-body">
            <?php
            $info = json_decode($i['server_info'] ?? '{}', true) ?: [];
            $dbi = json_decode($i['db_info'] ?? '{}', true) ?: [];
            ?>
            <dl class="detail-grid">
                <dt>实例 ID</dt><dd class="mono"><?php echo DCAI_Util::e($i['instance_id']); ?></dd>
                <dt>产品</dt><dd><?php echo DCAI_Util::e($i['product_name'] ?: '-') . ' <span class="muted mono">' . DCAI_Util::e($i['product_code'] ?: '') . '</span>'; ?></dd>
                <dt>授权码</dt><dd class="mono"><?php echo DCAI_Util::e($i['license_key'] ? DCAI_Util::maskLicenseKey($i['license_key']) : '-'); ?></dd>
                <dt>域名</dt><dd><?php echo DCAI_Util::e($i['domain']); ?></dd>
                <dt>服务器 IP</dt><dd class="mono"><?php echo DCAI_Util::e($i['ip'] ?: '-'); ?></dd>
                <dt>程序版本</dt><dd><?php echo DCAI_Util::e($i['version'] ?: '-'); ?></dd>
                <dt>状态</dt><dd><?php echo $st = (int)$i['status'] === 1 ? '<span class="badge green">在线</span>' : ((int)$i['status'] === 2 ? '<span class="badge red">已禁用</span>' : '<span class="badge gray">离线</span>'); ?></dd>
                <dt>首次注册</dt><dd><?php echo DCAI_Util::e($i['first_seen_at']); ?></dd>
                <dt>最后心跳</dt><dd><?php echo DCAI_Util::e($i['last_heartbeat_at'] ?: '-'); ?></dd>
                <dt>服务器环境</dt><dd class="mono" style="font-size:12px;"><?php echo DCAI_Util::e($info ? json_encode($info, JSON_UNESCAPED_UNICODE) : '-'); ?></dd>
                <dt>数据库环境</dt><dd class="mono" style="font-size:12px;"><?php echo DCAI_Util::e($dbi ? json_encode($dbi, JSON_UNESCAPED_UNICODE) : '-'); ?></dd>
            </dl>
        </div>
        <div class="modal-foot"><button class="btn btn-outline" data-modal-close>关闭</button></div>
    </div>
</div>
<?php endforeach; ?>

<script>
document.getElementById('cmd-type').addEventListener('change', function () {
    var v = this.value;
    document.getElementById('cfg-json-wrap').style.display = v === 'config_push' ? '' : 'none';
    document.getElementById('notice-wrap').style.display = (v === 'maintenance_on' || v === 'maintenance_off') ? '' : 'none';
    document.getElementById('version-wrap').style.display = v === 'update' ? '' : 'none';
    document.getElementById('danger-tip').style.display = v === 'disable' ? '' : 'none';
});
function collectSelected() {
    var boxes = document.querySelectorAll('input[name="instance_ids"]:checked');
    var ids = Array.from(boxes).map(function (b) { return b.value; });
    if (!ids.length) {
        // 校验失败：解除按钮 loading，避免表单被卡死
        var btn = document.querySelector('#commandModal button[type="submit"]');
        if (btn) { btn.disabled = false; btn.classList.remove('loading'); }
        if (window.dcaiToast) { window.dcaiToast('请先勾选目标实例', 'warning'); } else { alert('请先勾选目标实例'); }
        return false;
    }
    document.getElementById('instance_ids_hidden').value = ids.join(',');
    return true;
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

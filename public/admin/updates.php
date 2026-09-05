<?php
$pageTitle = '更新管理';
$activeMenu = 'update';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('update');

$db = dcai_db();

// 上传更新包
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    DCAI_Csrf::check();
    $productId = (int)($_POST['product_id'] ?? 0);
    $file = $_FILES['update_pkg'] ?? null;
    if ($productId <= 0) {
        dcai_flash('danger', '请选择产品');
    } elseif (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        dcai_flash('danger', '请选择 zip 更新包');
    } else {
        [$ok, $res, $err] = DCAI_UpdateService::upload($file, $productId, DCAI_Admin::id());
        if ($ok) {
            DCAI_Admin::opLog('上传更新包', $res);
            dcai_flash('success', "更新包 v{$res['version']} 上传成功，当前为待发布状态");
        } else {
            dcai_flash('danger', '上传失败：' . $err);
        }
    }
    header('Location: ' . DCAI_Admin::adminUrl('updates.php'));
    exit;
}

// 发布/撤回（统一走服务层，同步产品 current_version，避免待发布版本污染产品版本）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publish') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['value'] ?? 1);
    [$ok, $res, $err] = DCAI_UpdateService::publish($id, $val === 1 ? 1 : 2);
    if (!$ok) {
        dcai_flash('danger', $err);
        header('Location: ' . DCAI_Admin::adminUrl('updates.php'));
        exit;
    }
    DCAI_Admin::opLog($val === 1 ? '发布更新包' : '撤回更新包', ['id' => $id]);
    $notice = $val === 1
        ? '更新包已发布，已自动向 ' . (int)($res['notified_instances'] ?? 0) . ' 个在线实例下发自动更新命令'
        : '更新包已撤回';
    dcai_flash('success', $notice);
    header('Location: ' . DCAI_Admin::adminUrl('updates.php'));
    exit;
}

// 更新 is_force
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_force') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['value'] ?? 0);
    $db->update('updates', ['is_force' => $val], 'id = ?', [$id]);
    DCAI_Admin::opLog('设置强制更新', ['id' => $id, 'is_force' => $val]);
    dcai_flash('success', '操作成功');
    header('Location: ' . DCAI_Admin::adminUrl('updates.php'));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$productFilter = (int)($_GET['product_id'] ?? 0);
$whereSql = '';
$params = [];
if ($productFilter) { $whereSql = 'WHERE u.product_id = ?'; $params[] = $productFilter; }
$total = (int)$db->queryValue("SELECT COUNT(*) FROM updates u $whereSql", $params);
$offset = ($page - 1) * $per;
$updates = $db->query("SELECT u.*, p.name AS product_name, p.product_code FROM updates u LEFT JOIN products p ON p.id = u.product_id $whereSql ORDER BY u.id DESC LIMIT $per OFFSET $offset", $params);
$products = $db->query('SELECT id, name FROM products ORDER BY id DESC');

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div class="filters">
        <select onchange="location.href='<?php echo DCAI_Admin::adminUrl('updates.php'); ?>' + (this.value ? '?product_id=' + this.value : '')">
            <option value="">全部产品</option>
            <?php foreach ($products as $prod): ?>
                <option value="<?php echo (int)$prod['id']; ?>" <?php echo $productFilter === (int)$prod['id'] ? 'selected' : ''; ?>><?php echo DCAI_Util::e($prod['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="uploadModal">⬆ 上传更新包</button>
</div>

<div class="card">
    <p class="section-sub">更新包 zip 顶层必须包含 <code>update.json</code>：<code>{"version":"1.1.0","min_version":"1.0.0","changelog":"修复若干Bug","is_force":false}</code></p>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>产品</th><th>版本</th><th>最低版本</th><th>大小</th><th>MD5</th><th>强制</th><th>状态</th><th>更新日志</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($updates as $u): ?>
                <tr>
                    <td><?php echo (int)$u['id']; ?></td>
                    <td><?php echo DCAI_Util::e($u['product_name'] ?: $u['product_id']); ?></td>
                    <td><span class="badge purple"><?php echo DCAI_Util::e($u['version']); ?></span></td>
                    <td><?php echo DCAI_Util::e($u['min_version'] ?: '-'); ?></td>
                    <td><?php echo DCAI_Util::humanSize((int)$u['package_size']); ?></td>
                    <td class="mono" style="max-width:110px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e($u['package_md5']); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php echo DCAI_Csrf::field(); ?>
                            <input type="hidden" name="action" value="set_force">
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <input type="hidden" name="value" value="<?php echo (int)$u['is_force'] ? 0 : 1; ?>">
                            <button class="badge <?php echo (int)$u['is_force'] ? 'red' : 'gray'; ?>" style="border:none;cursor:pointer;"><?php echo (int)$u['is_force'] ? '强制' : '可选'; ?></button>
                        </form>
                    </td>
                    <td><?php echo (int)$u['status'] === 1 ? '<span class="badge green">已发布</span>' : ((int)$u['status'] === 0 ? '<span class="badge gray">待发布</span>' : '<span class="badge orange">已撤回</span>'); ?></td>
                    <td class="muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e(mb_substr($u['changelog'] ?? '', 0, 40)); ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($u['created_at']); ?></td>
                    <td>
                        <div class="actions">
                            <?php if ((int)$u['status'] === 1): ?>
                                <form method="post" style="display:inline;">
                                    <?php echo DCAI_Csrf::field(); ?>
                                    <input type="hidden" name="action" value="publish">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <input type="hidden" name="value" value="2">
                                    <button class="btn btn-outline btn-xs" data-confirm="确认撤回该更新包？">撤回</button>
                                </form>
                            <?php else: ?>
                                <form method="post" style="display:inline;">
                                    <?php echo DCAI_Csrf::field(); ?>
                                    <input type="hidden" name="action" value="publish">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <input type="hidden" name="value" value="1">
                                    <button class="btn btn-success btn-xs">发布</button>
                                </form>
                            <?php endif; ?>
                            <button class="btn btn-outline btn-xs" data-modal-open="log-<?php echo (int)$u['id']; ?>">应用记录</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$updates): ?><tr><td colspan="11" class="empty">暂无更新包</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>

<div class="modal-mask" id="uploadModal">
    <div class="modal">
        <form method="post" enctype="multipart/form-data">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="upload">
            <div class="modal-head"><h3>上传更新包</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-row"><label>产品 <span class="req">*</span></label>
                    <select name="product_id" required>
                        <option value="">请选择</option>
                        <?php foreach ($products as $prod): ?><option value="<?php echo (int)$prod['id']; ?>"><?php echo DCAI_Util::e($prod['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label>更新包 zip <span class="req">*</span></label><input type="file" name="update_pkg" accept=".zip" required></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">上传</button></div>
        </form>
    </div>
</div>

<?php foreach ($updates as $u):
    $applyLogs = $db->query('SELECT al.*, i.domain FROM update_apply_logs al LEFT JOIN instances i ON i.id = al.instance_id WHERE al.update_id = ? ORDER BY al.id DESC LIMIT 50', [(int)$u['id']]);
    ?>
    <div class="modal-mask" id="log-<?php echo (int)$u['id']; ?>">
        <div class="modal" style="max-width:760px;">
            <div class="modal-head"><h3>更新应用记录 #<?php echo (int)$u['id']; ?></h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>ID</th><th>实例</th><th>版本变化</th><th>状态</th><th>错误</th><th>时间</th></tr></thead>
                        <tbody>
                        <?php foreach ($applyLogs as $al):
                            $map = [0 => ['下载中', 'gray'], 1 => ['已下载', 'blue'], 2 => ['应用成功', 'green'], 3 => ['应用失败', 'red']];
                            [$l, $c] = $map[(int)$al['status']] ?? ['未知', 'gray'];
                            ?>
                            <tr>
                                <td><?php echo (int)$al['id']; ?></td>
                                <td class="muted"><?php echo DCAI_Util::e($al['domain'] ?: '#' . (int)$al['instance_id']); ?></td>
                                <td><?php echo DCAI_Util::e($al['from_version'] ?: '-'); ?> → <?php echo DCAI_Util::e($al['to_version']); ?></td>
                                <td><span class="badge <?php echo $c; ?>"><?php echo $l; ?></span></td>
                                <td class="muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e($al['error'] ?: '-'); ?></td>
                                <td class="muted"><?php echo DCAI_Util::e($al['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$applyLogs): ?><tr><td colspan="6" class="empty">暂无应用记录</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-foot"><button class="btn btn-outline" data-modal-close>关闭</button></div>
        </div>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

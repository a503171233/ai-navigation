<?php
$pageTitle = '远程模块';
$activeMenu = 'module';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('module');

$db = dcai_db();

// 创建/更新
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    DCAI_Csrf::check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $code = trim($_POST['module_code'] ?? '');
    $data = [
        'product_id'    => (int)($_POST['product_id'] ?? 0),
        'name'          => trim($_POST['name'] ?? ''),
        'description'   => trim($_POST['description'] ?? ''),
        'module_type'   => (int)($_POST['module_type'] ?? 1),
        'code'          => (string)($_POST['code'] ?? ''),
        'upstream_url'  => trim($_POST['upstream_url'] ?? ''),
        'sql_template'  => trim($_POST['sql_template'] ?? ''),
        'params_schema' => trim($_POST['params_schema'] ?? ''),
        'status'        => (int)($_POST['status'] ?? 1),
    ];
    if ($data['product_id'] <= 0 || $data['name'] === '' || $code === '') {
        dcai_flash('danger', '请填写模块编码、名称并选择产品');
    } elseif ($db->queryValue('SELECT COUNT(*) FROM remote_modules WHERE module_code = ? AND (? = 0 OR id <> ?)', [$code, $id, $id])) {
        dcai_flash('danger', '模块编码已存在');
    } else {
        $now = dcai_now();
        if ($action === 'create') {
            $data['module_code'] = $code;
            $data['version'] = '1.0.0';
            $data['created_by'] = DCAI_Admin::id();
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $db->insert('remote_modules', $data);
            DCAI_Admin::opLog('创建远程模块', ['code' => $code]);
            dcai_flash('success', '模块创建成功');
        } elseif ($action === 'update') {
            // 版本 +1（递增最后一段）
            $old = $db->queryOne('SELECT version FROM remote_modules WHERE id = ?', [$id]);
            $parts = DCAI_Version::parse($old['version'] ?? '1.0.0');
            $parts[2]++;
            if ($parts[2] > 99) { $parts[2] = 0; $parts[1]++; }
            $newVersion = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
            $data['module_code'] = $code;
            $data['version'] = $newVersion;
            $data['updated_at'] = $now;
            $db->update('remote_modules', $data, 'id = ?', [$id]);
            DCAI_Admin::opLog('编辑远程模块', ['id' => $id, 'version' => $newVersion]);
            dcai_flash('success', "模块已更新，版本升至 v{$newVersion}");
        }
    }
    header('Location: ' . DCAI_Admin::adminUrl('modules.php'));
    exit;
}

// 启停/删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['value'] ?? 0);
    $db->update('remote_modules', ['status' => $val, 'updated_at' => dcai_now()], 'id = ?', [$id]);
    DCAI_Admin::opLog('切换模块状态', ['id' => $id, 'status' => $val]);
    dcai_flash('success', '操作成功');
    header('Location: ' . DCAI_Admin::adminUrl('modules.php'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $logCount = (int)$db->queryValue('SELECT COUNT(*) FROM module_invoke_logs WHERE module_id = ?', [$id]);
    if ($logCount > 0) {
        $db->update('remote_modules', ['status' => 0, 'updated_at' => dcai_now()], 'id = ?', [$id]);
        DCAI_Admin::opLog('软禁用模块（存在调用日志）', ['id' => $id]);
        dcai_flash('warning', '模块存在调用日志，已执行停用而非删除');
    } else {
        $db->delete('remote_modules', 'id = ?', [$id]);
        DCAI_Admin::opLog('删除模块', ['id' => $id]);
        dcai_flash('success', '模块已删除');
    }
    header('Location: ' . DCAI_Admin::adminUrl('modules.php'));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$modules = $db->query('SELECT m.*, p.name AS product_name,
    (SELECT COUNT(*) FROM module_invoke_logs l WHERE l.module_id = m.id) AS call_count
    FROM remote_modules m LEFT JOIN products p ON p.id = m.product_id ORDER BY m.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$total = (int)$db->queryValue('SELECT COUNT(*) FROM remote_modules');
$products = $db->query('SELECT id, name FROM products ORDER BY id DESC');
$typeMap = [1 => 'PHP 代码型', 2 => 'HTTP 转发型', 3 => '数据查询型'];

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="createModal">＋ 新建模块</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>编码</th><th>名称</th><th>产品</th><th>类型</th><th>版本</th><th>调用次数</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($modules as $m): ?>
                <tr>
                    <td><?php echo (int)$m['id']; ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($m['module_code']); ?></td>
                    <td><?php echo DCAI_Util::e($m['name']); ?></td>
                    <td><?php echo DCAI_Util::e($m['product_name'] ?: $m['product_id']); ?></td>
                    <td><span class="badge blue"><?php echo $typeMap[(int)$m['module_type']] ?? '-'; ?></span></td>
                    <td><?php echo DCAI_Util::e($m['version']); ?></td>
                    <td><?php echo (int)$m['call_count']; ?></td>
                    <td><?php echo (int)$m['status'] ? '<span class="badge green">启用</span>' : '<span class="badge gray">停用</span>'; ?></td>
                    <td>
                        <div class="actions">
                            <button class="btn btn-outline btn-xs" data-modal-open="edit-<?php echo (int)$m['id']; ?>">编辑</button>
                            <a class="btn btn-outline btn-xs" href="<?php echo DCAI_Admin::adminUrl('logs.php'); ?>?type=module&module_id=<?php echo (int)$m['id']; ?>">日志</a>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                                <input type="hidden" name="value" value="<?php echo (int)$m['status'] ? 0 : 1; ?>">
                                <button class="btn btn-outline btn-xs"><?php echo (int)$m['status'] ? '停用' : '启用'; ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                                <button class="btn btn-danger-outline btn-xs" data-confirm-danger="删除该模块？">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$modules): ?><tr><td colspan="9" class="empty">暂无远程模块</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>

<!-- 新建 -->
<div class="modal-mask" id="createModal">
    <div class="modal" style="max-width:780px;">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-head"><h3>新建远程模块</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <?php $m = []; include __DIR__ . '/includes/module_form.php'; ?>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">创建</button></div>
        </form>
    </div>
</div>

<?php foreach ($modules as $m): ?>
<div class="modal-mask" id="edit-<?php echo (int)$m['id']; ?>">
    <div class="modal" style="max-width:780px;">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
            <div class="modal-head"><h3>编辑模块 #<?php echo (int)$m['id']; ?>（当前 v<?php echo DCAI_Util::e($m['version']); ?>）</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <?php include __DIR__ . '/includes/module_form.php'; ?>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">保存（版本+1）</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

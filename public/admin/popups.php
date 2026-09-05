<?php
$pageTitle = '弹窗管理';
$activeMenu = 'popup';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('popup');

$db = dcai_db();

// 创建/更新
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    DCAI_Csrf::check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    // 时间字段格式校验（可为空；非空时必须是合法 datetime）
    $startRaw = trim((string)($_POST['start_at'] ?? ''));
    $endRaw = trim((string)($_POST['end_at'] ?? ''));
    if ($startRaw !== '' && strtotime($startRaw) === false) {
        dcai_flash('danger', '开始时间格式不正确');
        header('Location: ' . DCAI_Admin::adminUrl('popups.php'));
        exit;
    }
    if ($endRaw !== '' && strtotime($endRaw) === false) {
        dcai_flash('danger', '结束时间格式不正确');
        header('Location: ' . DCAI_Admin::adminUrl('popups.php'));
        exit;
    }
    if ($startRaw !== '' && $endRaw !== '' && strtotime($endRaw) < strtotime($startRaw)) {
        dcai_flash('danger', '结束时间不能早于开始时间');
        header('Location: ' . DCAI_Admin::adminUrl('popups.php'));
        exit;
    }

    $data = [
        'product_id'          => (int)($_POST['product_id'] ?? 0),
        'title'               => trim($_POST['title'] ?? ''),
        'content'             => DCAI_Util::sanitizeHtml((string)($_POST['content'] ?? '')),
        'popup_type'          => in_array((int)($_POST['popup_type'] ?? 1), [1, 2, 3], true) ? (int)($_POST['popup_type'] ?? 1) : 1,
        'target_type'         => in_array((int)($_POST['target_type'] ?? 0), [0, 1], true) ? (int)($_POST['target_type'] ?? 0) : 0,
        'target_instance_ids' => json_encode(array_map('intval', array_filter((array)($_POST['target_instance_ids'] ?? []))), JSON_UNESCAPED_UNICODE),
        'start_at'            => $startRaw !== '' ? $startRaw : null,
        'end_at'              => $endRaw !== '' ? $endRaw : null,
        'max_show_per_instance' => max(0, (int)($_POST['max_show_per_instance'] ?? 0)),
        'status'              => in_array((int)($_POST['status'] ?? 1), [0, 1], true) ? (int)($_POST['status'] ?? 1) : 1,
    ];
    if ($data['product_id'] <= 0 || $data['title'] === '') {
        dcai_flash('danger', '请填写产品与标题');
    } else {
        if ($action === 'create') {
            $data['created_by'] = DCAI_Admin::id();
            $data['created_at'] = dcai_now();
            $data['updated_at'] = dcai_now();
            $db->insert('popups', $data);
            DCAI_Admin::opLog('创建弹窗', ['title' => $data['title']]);
            dcai_flash('success', '弹窗创建成功');
        } elseif ($action === 'update') {
            $data['updated_at'] = dcai_now();
            $db->update('popups', $data, 'id = ?', [$id]);
            DCAI_Admin::opLog('编辑弹窗', ['id' => $id]);
            dcai_flash('success', '弹窗已更新');
        }
    }
    header('Location: ' . DCAI_Admin::adminUrl('popups.php'));
    exit;
}

// 启停/删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['value'] ?? 0);
    $db->update('popups', ['status' => $val, 'updated_at' => dcai_now()], 'id = ?', [$id]);
    DCAI_Admin::opLog('切换弹窗状态', ['id' => $id, 'status' => $val]);
    dcai_flash('success', '操作成功');
    header('Location: ' . DCAI_Admin::adminUrl('popups.php'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $db->delete('popups', 'id = ?', [$id]);
    $db->delete('popup_show_logs', 'popup_id = ?', [$id]);
    DCAI_Admin::opLog('删除弹窗', ['id' => $id]);
    dcai_flash('success', '弹窗已删除');
    header('Location: ' . DCAI_Admin::adminUrl('popups.php'));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$productFilter = (int)($_GET['product_id'] ?? 0);
$whereSql = '';
$params = [];
if ($productFilter) { $whereSql = 'WHERE p.product_id = ?'; $params[] = $productFilter; }

$total = (int)$db->queryValue("SELECT COUNT(*) FROM popups p $whereSql", $params);
$offset = ($page - 1) * $per;
$popups = $db->query("SELECT p.*, pr.name AS product_name,
    (SELECT COUNT(*) FROM popup_show_logs s WHERE s.popup_id = p.id) AS total_shown
    FROM popups p LEFT JOIN products pr ON pr.id = p.product_id
    $whereSql ORDER BY p.id DESC LIMIT $per OFFSET $offset", $params);
$products = $db->query('SELECT id, name FROM products ORDER BY id DESC');
$allInstances = $db->query('SELECT id, domain FROM instances ORDER BY id DESC LIMIT 300');

$typeMap = [1 => ['公告', 'blue'], 2 => ['通知', 'purple'], 3 => ['警示', 'orange']];

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div class="filters">
        <select onchange="location.href='<?php echo DCAI_Admin::adminUrl('popups.php'); ?>' + (this.value ? '?product_id=' + this.value : '')">
            <option value="">全部产品</option>
            <?php foreach ($products as $prod): ?>
                <option value="<?php echo (int)$prod['id']; ?>" <?php echo $productFilter === (int)$prod['id'] ? 'selected' : ''; ?>><?php echo DCAI_Util::e($prod['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="createModal">＋ 新建弹窗</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>产品</th><th>标题</th><th>类型</th><th>目标</th><th>时间窗</th><th>展示上限</th><th>总展示</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($popups as $p):
                [$tLabel, $tCls] = $typeMap[(int)$p['popup_type']] ?? ['未知', 'gray'];
                $target = (int)$p['target_type'] === 1 ? '指定实例' : '全部实例';
                ?>
                <tr>
                    <td><?php echo (int)$p['id']; ?></td>
                    <td><?php echo DCAI_Util::e($p['product_name'] ?: $p['product_id']); ?></td>
                    <td><?php echo DCAI_Util::e($p['title']); ?></td>
                    <td><span class="badge <?php echo $tCls; ?>"><?php echo $tLabel; ?></span></td>
                    <td><?php echo $target; ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($p['start_at'] ?: '不限'); ?> ~ <?php echo DCAI_Util::e($p['end_at'] ?: '不限'); ?></td>
                    <td><?php echo (int)$p['max_show_per_instance'] ?: '不限'; ?></td>
                    <td><?php echo (int)$p['total_shown']; ?></td>
                    <td><?php echo (int)$p['status'] ? '<span class="badge green">启用</span>' : '<span class="badge gray">停用</span>'; ?></td>
                    <td>
                        <div class="actions">
                            <button class="btn btn-outline btn-xs"
                                data-popup-preview
                                data-title="<?php echo DCAI_Util::e($p['title']); ?>"
                                data-content="<?php echo DCAI_Util::e($p['content']); ?>"
                                data-type="<?php echo (int)$p['popup_type']; ?>">预览</button>
                            <button class="btn btn-outline btn-xs" data-modal-open="edit-<?php echo (int)$p['id']; ?>">编辑</button>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <input type="hidden" name="value" value="<?php echo (int)$p['status'] ? 0 : 1; ?>">
                                <button class="btn btn-outline btn-xs"><?php echo (int)$p['status'] ? '停用' : '启用'; ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button class="btn btn-danger-outline btn-xs" data-confirm-danger="删除该弹窗及展示统计？">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$popups): ?><tr><td colspan="10" class="empty">暂无弹窗</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>

<!-- 新建弹窗 -->
<div class="modal-mask" id="createModal">
    <div class="modal" style="max-width:680px;">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-head"><h3>新建弹窗</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <?php include __DIR__ . '/includes/popup_form.php'; ?>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">创建</button></div>
        </form>
    </div>
</div>

<?php foreach ($popups as $p): ?>
<div class="modal-mask" id="edit-<?php echo (int)$p['id']; ?>">
    <div class="modal" style="max-width:680px;">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
            <div class="modal-head"><h3>编辑弹窗 #<?php echo (int)$p['id']; ?></h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <?php
                $f = $p;
                $selectedInstanceIds = json_decode($p['target_instance_ids'] ?? '[]', true) ?: [];
                include __DIR__ . '/includes/popup_form.php';
                ?>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- 弹窗预览 -->
<div class="modal-mask" id="popup-preview-mask">
    <div class="modal" style="max-width:520px;">
        <div class="modal-head"><h3>弹窗预览</h3><button type="button" class="close" data-modal-close>×</button></div>
        <div class="modal-body" id="popup-preview">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                <span class="pp-icon" style="font-size:26px;">📢</span>
                <h3 class="pp-title" style="font-size:17px;"></h3>
            </div>
            <div class="pp-content" style="background:#f8fafc;padding:14px;border-radius:8px;line-height:1.7;"></div>
        </div>
        <div class="modal-foot"><button class="btn btn-outline" data-modal-close>关闭</button></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
$pageTitle = '技能管理';
$activeMenu = 'skill';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('skill');

$db = dcai_db();
$now = dcai_now();

/* ---------------- 技能包 增/改/删/启停 ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['resource'] ?? '') === 'skill') {
    DCAI_Csrf::check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $code = trim($_POST['skill_code'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $data = [
            'product_id'   => (int)($_POST['product_id'] ?? 0),
            'name'         => trim($_POST['name'] ?? ''),
            'description'  => trim($_POST['description'] ?? ''),
            'icon'         => trim($_POST['icon'] ?? '🧩'),
            'access_model' => (int)($_POST['access_model'] ?? 0) === 1 ? 1 : 0,
            'sort_order'   => (int)($_POST['sort_order'] ?? 0),
            'status'       => (int)($_POST['status'] ?? 1),
        ];
        if ($data['product_id'] <= 0 || $data['name'] === '' || $code === '') {
            dcai_flash('danger', '请填写技能编码、名称并选择产品');
        } elseif ($db->queryValue('SELECT COUNT(*) FROM skills WHERE skill_code = ? AND (? = 0 OR id <> ?)', [$code, $id, $id])) {
            dcai_flash('danger', '技能编码已存在');
        } else {
            $data['skill_code'] = $code;
            if ($action === 'create') {
                $data['created_by'] = DCAI_Admin::id();
                $data['created_at'] = $now;
                $data['updated_at'] = $now;
                $db->insert('skills', $data);
                DCAI_Admin::opLog('创建技能', ['code' => $code]);
                dcai_flash('success', '技能创建成功');
            } else {
                $data['updated_at'] = $now;
                $db->update('skills', $data, 'id = ?', [$id]);
                DCAI_Admin::opLog('编辑技能', ['id' => $id, 'code' => $code]);
                dcai_flash('success', '技能已保存');
            }
        }
        header('Location: ' . DCAI_Admin::adminUrl('skills.php'));
        exit;
    }

    if ($action === 'toggle') {
        $val = (int)($_POST['value'] ?? 0);
        $db->update('skills', ['status' => $val, 'updated_at' => $now], 'id = ?', [$id]);
        DCAI_Admin::opLog('切换技能状态', ['id' => $id, 'status' => $val]);
        dcai_flash('success', '操作成功');
        header('Location: ' . DCAI_Admin::adminUrl('skills.php'));
        exit;
    }

    if ($action === 'delete') {
        $fnCount = (int)$db->queryValue('SELECT COUNT(*) FROM skill_functions WHERE skill_id = ?', [$id]);
        $logCount = (int)$db->queryValue('SELECT COUNT(*) FROM skill_invoke_logs WHERE skill_id = ?', [$id]);
        if ($fnCount > 0 || $logCount > 0) {
            $db->update('skills', ['status' => 0, 'updated_at' => $now], 'id = ?', [$id]);
            DCAI_Admin::opLog('软禁用技能（存在函数/调用日志）', ['id' => $id]);
            dcai_flash('warning', '技能存在函数或调用日志，已执行停用而非删除');
        } else {
            $db->beginTransaction();
            try {
                $db->delete('skill_grants', 'skill_id = ?', [$id]);
                $db->delete('skills', 'id = ?', [$id]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                dcai_flash('danger', '删除失败: ' . $e->getMessage());
            }
            DCAI_Admin::opLog('删除技能', ['id' => $id]);
            dcai_flash('success', '技能已删除');
        }
        header('Location: ' . DCAI_Admin::adminUrl('skills.php'));
        exit;
    }
}

/* ---------------- 技能函数 增/改/删/启停 ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['resource'] ?? '') === 'func') {
    DCAI_Csrf::check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $skillId = (int)($_POST['skill_id'] ?? 0);
    $fc = trim($_POST['function_code'] ?? '');

    if (!($db->queryValue('SELECT COUNT(*) FROM skills WHERE id = ?', [$skillId]))) {
        dcai_flash('danger', '技能不存在');
        header('Location: ' . DCAI_Admin::adminUrl('skills.php'));
        exit;
    }

    if ($action === 'create' || $action === 'update') {
        $funcType = (int)($_POST['func_type'] ?? 1);
        $data = [
            'skill_id'      => $skillId,
            'name'          => trim($_POST['name'] ?? ''),
            'description'   => trim($_POST['description'] ?? ''),
            'func_type'     => $funcType,
            'code'          => $funcType === 1 ? (string)($_POST['code'] ?? '') : '',
            'upstream_url'  => $funcType === 2 ? trim($_POST['upstream_url'] ?? '') : '',
            'sql_template'  => $funcType === 3 ? trim($_POST['sql_template'] ?? '') : '',
            'params_schema' => trim($_POST['params_schema'] ?? ''),
            'status'        => (int)($_POST['status'] ?? 1),
        ];
        if ($data['name'] === '' || $fc === '') {
            dcai_flash('danger', '请填写函数编码与名称');
        } elseif ($db->queryValue('SELECT COUNT(*) FROM skill_functions WHERE skill_id = ? AND function_code = ? AND (? = 0 OR id <> ?)', [$skillId, $fc, $id, $id])) {
            dcai_flash('danger', '该技能下函数编码已存在');
        } else {
            $data['function_code'] = $fc;
            if ($action === 'create') {
                $data['created_at'] = $now;
                $data['updated_at'] = $now;
                $db->insert('skill_functions', $data);
                DCAI_Admin::opLog('创建技能函数', ['skill_id' => $skillId, 'fc' => $fc]);
                dcai_flash('success', '技能函数创建成功');
            } else {
                $data['updated_at'] = $now;
                $db->update('skill_functions', $data, 'id = ?', [$id]);
                DCAI_Admin::opLog('编辑技能函数', ['id' => $id, 'fc' => $fc]);
                dcai_flash('success', '技能函数已保存');
            }
        }
        header('Location: ' . DCAI_Admin::adminUrl('skills.php'));
        exit;
    }

    if ($action === 'toggle') {
        $val = (int)($_POST['value'] ?? 0);
        $db->update('skill_functions', ['status' => $val, 'updated_at' => $now], 'id = ?', [$id]);
        DCAI_Admin::opLog('切换技能函数状态', ['id' => $id, 'status' => $val]);
        dcai_flash('success', '操作成功');
        header('Location: ' . DCAI_Admin::adminUrl('skills.php'));
        exit;
    }

    if ($action === 'delete') {
        $logCount = (int)$db->queryValue('SELECT COUNT(*) FROM skill_invoke_logs WHERE function_id = ?', [$id]);
        if ($logCount > 0) {
            $db->update('skill_functions', ['status' => 0, 'updated_at' => $now], 'id = ?', [$id]);
            DCAI_Admin::opLog('软禁用技能函数（存在调用日志）', ['id' => $id]);
            dcai_flash('warning', '函数存在调用日志，已执行停用而非删除');
        } else {
            $db->delete('skill_functions', 'id = ?', [$id]);
            DCAI_Admin::opLog('删除技能函数', ['id' => $id]);
            dcai_flash('success', '技能函数已删除');
        }
        header('Location: ' . DCAI_Admin::adminUrl('skills.php'));
        exit;
    }
}

/* ---------------- 技能授权（按授权码维度） ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['resource'] ?? '') === 'grant') {
    DCAI_Csrf::check();
    $action = $_POST['action'] ?? '';
    $skillId = (int)($_POST['skill_id'] ?? 0);

    if ($action === 'add') {
        $licenseId = (int)($_POST['license_id'] ?? 0);
        if ($skillId <= 0 || $licenseId <= 0) {
            dcai_flash('danger', '请选择授权码');
        } else {
            $skill = $db->queryOne('SELECT s.*, p.name AS product_name FROM skills s LEFT JOIN products p ON p.id = s.product_id WHERE s.id = ?', [$skillId]);
            $lic = $db->queryOne('SELECT id FROM licenses WHERE id = ? AND product_id = ?', [$licenseId, (int)($skill['product_id'] ?? 0)]);
            if (!$lic) {
                dcai_flash('danger', '授权码不存在或不属于该技能所属产品');
            } else {
                DCAI_SkillService::setGrant($skillId, $licenseId, 1, DCAI_Admin::id());
                DCAI_Admin::opLog('授予技能访问权', ['skill_id' => $skillId, 'license_id' => $licenseId]);
                dcai_flash('success', '已授予该授权码访问权');
            }
        }
        header('Location: ' . DCAI_Admin::adminUrl('skills.php'));
        exit;
    }

    if ($action === 'revoke') {
        $licenseId = (int)($_POST['license_id'] ?? 0);
        $db->update('skill_grants', ['status' => 0], 'skill_id = ? AND license_id = ?', [$skillId, $licenseId]);
        DCAI_Admin::opLog('撤销技能访问权', ['skill_id' => $skillId, 'license_id' => $licenseId]);
        dcai_flash('success', '已撤销该授权码访问权');
        header('Location: ' . DCAI_Admin::adminUrl('skills.php'));
        exit;
    }
}

/* ---------------- 渲染 ---------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$offset = ($page - 1) * $per;

$skills = $db->query(
    "SELECT s.*, p.name AS product_name,
        (SELECT COUNT(*) FROM skill_functions f WHERE f.skill_id = s.id) AS func_count,
        (SELECT COUNT(*) FROM skill_invoke_logs l WHERE l.skill_id = s.id) AS call_count
     FROM skills s LEFT JOIN products p ON p.id = s.product_id
     ORDER BY s.sort_order ASC, s.id DESC LIMIT $per OFFSET $offset"
);
$total = (int)$db->queryValue('SELECT COUNT(*) FROM skills');
$products = $db->query('SELECT id, name FROM products ORDER BY id DESC');

// 每个技能的函数 + 授权 + 可选授权码（用于授权添加下拉）
$skillExtras = [];
foreach ($skills as $s) {
    $id = (int)$s['id'];
    $skillExtras[$id]['funcs'] = $db->query('SELECT * FROM skill_functions WHERE skill_id = ? ORDER BY id ASC', [$id]);
    $skillExtras[$id]['grants'] = $db->query(
        'SELECT g.*, l.license_key, l.customer_name FROM skill_grants g
         LEFT JOIN licenses l ON l.id = g.license_id WHERE g.skill_id = ? AND g.status = 1 ORDER BY g.id DESC', [$id]);
    $skillExtras[$id]['licenses'] = $db->query(
        'SELECT id, license_key, customer_name FROM licenses WHERE product_id = ? AND status = 1 ORDER BY id DESC LIMIT 200',
        [(int)$s['product_id']]);
}

$funcTypeMap = [1 => 'PHP 代码', 2 => 'HTTP 转发', 3 => '数据查询'];
require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="createModal">＋ 新建技能</button>
</div>

<?php if (empty($skills)): ?>
<div class="card">
    <div class="empty-box">暂无技能。技能 = 一组可被被授权站点（壳）远程调用的函数，核心逻辑托管在授权系统。</div>
</div>
<?php else: ?>
<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>技能</th><th>产品</th><th>授权模型</th><th>函数</th><th>调用数</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($skills as $s): $sid = (int)$s['id']; ?>
                <tr>
                    <td><?php echo $sid; ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:20px;"><?php echo DCAI_Util::e($s['icon'] ?: '🧩'); ?></span>
                            <div>
                                <div><strong><?php echo DCAI_Util::e($s['name']); ?></strong></div>
                                <div class="mono muted" style="font-size:12px;"><?php echo DCAI_Util::e($s['skill_code']); ?></div>
                                <?php if ($s['description']): ?><div class="muted"><?php echo DCAI_Util::e($s['description']); ?></div><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><?php echo DCAI_Util::e($s['product_name'] ?: $s['product_id']); ?></td>
                    <td><?php echo (int)$s['access_model'] === 1 ? '<span class="badge orange">按授权码</span>' : '<span class="badge blue">产品级</span>'; ?></td>
                    <td><?php echo (int)$s['func_count']; ?></td>
                    <td><?php echo (int)$s['call_count']; ?></td>
                    <td><?php echo (int)$s['sort_order']; ?></td>
                    <td><?php echo (int)$s['status'] ? '<span class="badge green">启用</span>' : '<span class="badge gray">停用</span>'; ?></td>
                    <td>
                        <div class="actions">
                            <button class="btn btn-outline btn-xs" data-toggle-func="func-<?php echo $sid; ?>">函数</button>
                            <?php if ((int)$s['access_model'] === 1): ?>
                                <button class="btn btn-outline btn-xs" data-modal-open="grant-<?php echo $sid; ?>">授权</button>
                            <?php endif; ?>
                            <a class="btn btn-outline btn-xs" href="<?php echo DCAI_Admin::adminUrl('logs.php'); ?>?type=skill&skill_id=<?php echo $sid; ?>">日志</a>
                            <button class="btn btn-outline btn-xs" data-modal-open="edit-<?php echo $sid; ?>">编辑</button>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="resource" value="skill">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $sid; ?>">
                                <input type="hidden" name="value" value="<?php echo (int)$s['status'] ? 0 : 1; ?>">
                                <button class="btn btn-outline btn-xs"><?php echo (int)$s['status'] ? '停用' : '启用'; ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="resource" value="skill">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $sid; ?>">
                                <button class="btn btn-danger-outline btn-xs" data-confirm-danger="删除该技能（含其函数与授权）？">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>
<?php endif; ?>

<!-- 新建技能 -->
<div class="modal-mask" id="createModal">
    <div class="modal" style="max-width:640px;">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="resource" value="skill">
            <input type="hidden" name="action" value="create">
            <div class="modal-head"><h3>新建技能</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <?php $m = []; include __DIR__ . '/includes/skill_form.php'; ?>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">创建</button></div>
        </form>
    </div>
</div>

<?php foreach ($skills as $s): $sid = (int)$s['id']; $funcs = $skillExtras[$sid]['funcs'] ?? []; ?>
<!-- 编辑技能 -->
<div class="modal-mask" id="edit-<?php echo $sid; ?>">
    <div class="modal" style="max-width:640px;">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="resource" value="skill">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo $sid; ?>">
            <div class="modal-head"><h3>编辑技能 #<?php echo $sid; ?></h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body"><?php $m = $s; include __DIR__ . '/includes/skill_form.php'; ?></div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
        </form>
    </div>
</div>

<!-- 授权管理 -->
<?php if ((int)$s['access_model'] === 1): $grants = $skillExtras[$sid]['grants'] ?? []; $lics = $skillExtras[$sid]['licenses'] ?? []; ?>
<div class="modal-mask" id="grant-<?php echo $sid; ?>">
    <div class="modal" style="max-width:600px;">
        <div class="modal-head"><h3>技能授权 — <?php echo DCAI_Util::e($s['name']); ?></h3><button type="button" class="close" data-modal-close>×</button></div>
        <div class="modal-body">
            <p class="muted" style="margin-top:0;">该技能为「按授权码授予」模型，需为授权码逐条授权后方可被调用。</p>
            <form method="post" style="display:flex;gap:8px;margin-bottom:14px;">
                <?php echo DCAI_Csrf::field(); ?>
                <input type="hidden" name="resource" value="grant">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="skill_id" value="<?php echo $sid; ?>">
                <select name="license_id" required style="flex:1;">
                    <option value="">选择授权码</option>
                    <?php foreach ($lics as $lic): ?>
                        <option value="<?php echo (int)$lic['id']; ?>">#<?php echo (int)$lic['id']; ?> · <?php echo DCAI_Util::e(DCAI_LicenseService::mask($lic['license_key'])); ?><?php echo $lic['customer_name'] ? ' · ' . DCAI_Util::e($lic['customer_name']) : ''; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">授予</button>
            </form>
            <?php if (!$grants): ?>
                <div class="empty">尚未授予任何授权码</div>
            <?php else: ?>
                <table class="data">
                    <thead><tr><th>授权码</th><th>客户</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($grants as $g): ?>
                        <tr>
                            <td class="mono"><?php echo DCAI_Util::e(DCAI_LicenseService::mask($g['license_key'])); ?></td>
                            <td><?php echo DCAI_Util::e($g['customer_name'] ?: '-'); ?></td>
                            <td><span class="badge green">已授权</span></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php echo DCAI_Csrf::field(); ?>
                                    <input type="hidden" name="resource" value="grant">
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="skill_id" value="<?php echo $sid; ?>">
                                    <input type="hidden" name="license_id" value="<?php echo (int)$g['license_id']; ?>">
                                    <button class="btn btn-danger-outline btn-xs" data-confirm="撤销该授权码的访问权？">撤销</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>关闭</button></div>
    </div>
</div>
<?php endif; ?>

<!-- 函数管理面板（页面内展开） -->
<div class="func-panel" id="func-<?php echo $sid; ?>" style="display:none;">
    <div class="card">
        <div class="card-head">
            <strong><?php echo DCAI_Util::e($s['icon'] ?: '🧩'); ?> <?php echo DCAI_Util::e($s['name']); ?> — 函数列表</strong>
            <span class="muted">（共 <?php echo count($funcs); ?> 个函数）</span>
            <button class="btn btn-outline btn-xs" data-modal-open="funcNew-<?php echo $sid; ?>">＋ 新建函数</button>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>ID</th><th>编码</th><th>名称</th><th>类型</th><th>状态</th><th>调用数</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($funcs as $f): $fid = (int)$f['id']; ?>
                    <tr>
                        <td><?php echo $fid; ?></td>
                        <td class="mono"><?php echo DCAI_Util::e($f['function_code']); ?></td>
                        <td><?php echo DCAI_Util::e($f['name']); ?><?php if ($f['description']): ?><div class="muted" style="font-size:12px;"><?php echo DCAI_Util::e($f['description']); ?></div><?php endif; ?></td>
                        <td><span class="badge blue"><?php echo $funcTypeMap[(int)$f['func_type']] ?? '-'; ?></span></td>
                        <td><?php echo (int)$f['status'] ? '<span class="badge green">启用</span>' : '<span class="badge gray">停用</span>'; ?></td>
                        <td><?php echo (int)$db->queryValue('SELECT COUNT(*) FROM skill_invoke_logs WHERE function_id = ?', [$fid]); ?></td>
                        <td>
                            <div class="actions">
                                <button class="btn btn-outline btn-xs" data-modal-open="funcEdit-<?php echo $fid; ?>">编辑</button>
                                <form method="post" style="display:inline;">
                                    <?php echo DCAI_Csrf::field(); ?>
                                    <input type="hidden" name="resource" value="func">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $fid; ?>">
                                    <input type="hidden" name="value" value="<?php echo (int)$f['status'] ? 0 : 1; ?>">
                                    <button class="btn btn-outline btn-xs"><?php echo (int)$f['status'] ? '停用' : '启用'; ?></button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <?php echo DCAI_Csrf::field(); ?>
                                    <input type="hidden" name="resource" value="func">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $fid; ?>">
                                    <button class="btn btn-danger-outline btn-xs" data-confirm-danger="删除该函数？">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$funcs): ?><tr><td colspan="7" class="empty">该技能暂无函数</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 新建函数 -->
<div class="modal-mask" id="funcNew-<?php echo $sid; ?>">
    <div class="modal" style="max-width:780px;">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="resource" value="func">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="skill_id" value="<?php echo $sid; ?>">
            <div class="modal-head"><h3>新建函数 — <?php echo DCAI_Util::e($s['name']); ?></h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body"><?php $f = []; include __DIR__ . '/includes/skill_func_form.php'; ?></div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">创建</button></div>
        </form>
    </div>
</div>

<?php foreach ($funcs as $f): $fid = (int)$f['id']; ?>
<!-- 编辑函数 -->
<div class="modal-mask" id="funcEdit-<?php echo $fid; ?>">
    <div class="modal" style="max-width:780px;">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="resource" value="func">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo $fid; ?>">
            <input type="hidden" name="skill_id" value="<?php echo $sid; ?>">
            <div class="modal-head"><h3>编辑函数 #<?php echo $fid; ?></h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body"><?php include __DIR__ . '/includes/skill_func_form.php'; ?></div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
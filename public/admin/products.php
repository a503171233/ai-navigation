<?php
$pageTitle = '产品管理';
$activeMenu = 'product';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('product');

$db = dcai_db();

// 创建
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    DCAI_Csrf::check();
    $code = trim($_POST['product_code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $code) || $name === '') {
        dcai_flash('danger', '产品编码仅允许字母数字下划线，且产品名称不能为空');
    } elseif ($db->queryValue('SELECT COUNT(*) FROM products WHERE product_code = ?', [$code])) {
        dcai_flash('danger', '产品编码已存在');
    } else {
        $now = dcai_now();
        $db->insert('products', [
            'product_code'    => $code,
            'name'            => $name,
            'description'     => trim($_POST['description'] ?? ''),
            'current_version' => '1.0.0',
            'enforce_auth'    => (int)($_POST['enforce_auth'] ?? 0),
            'fail_open'       => (int)($_POST['fail_open'] ?? 1),
            'verify_ttl'      => max(60, (int)($_POST['verify_ttl'] ?? 3600)),
            'status'          => 1,
            'trial_enabled'   => (int)($_POST['trial_enabled'] ?? 0),
            'trial_days'      => max(0, (int)($_POST['trial_days'] ?? 0)),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        DCAI_Admin::opLog('创建产品', ['code' => $code, 'name' => $name]);
        dcai_flash('success', '产品创建成功');
    }
    header('Location: ' . DCAI_Admin::adminUrl('products.php'));
    exit;
}

// 更新配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $data = [
        'name'          => trim($_POST['name'] ?? ''),
        'description'   => trim($_POST['description'] ?? ''),
        'enforce_auth'  => (int)($_POST['enforce_auth'] ?? 0),
        'fail_open'     => (int)($_POST['fail_open'] ?? 1),
        'verify_ttl'    => max(60, (int)($_POST['verify_ttl'] ?? 3600)),
        'status'        => (int)($_POST['status'] ?? 1),
        'for_sale'      => (int)($_POST['for_sale'] ?? 0),
        'sale_price'    => max(0, (float)($_POST['sale_price'] ?? 0)),
        'price_unit'    => in_array(($_POST['price_unit'] ?? 'year'), ['month','quarter','half_year','year','perpetual'], true) ? ($_POST['price_unit'] ?? 'year') : 'year',
        'sale_icon'     => trim((string)($_POST['sale_icon'] ?? '')),
        'sale_intro'    => trim((string)($_POST['sale_intro'] ?? '')),
        'trial_enabled' => (int)($_POST['trial_enabled'] ?? 0),
        'trial_days'    => max(0, (int)($_POST['trial_days'] ?? 0)),
        'updated_at'    => dcai_now(),
    ];
    $db->update('products', $data, 'id = ?', [$id]);
    DCAI_Admin::opLog('更新产品', ['id' => $id] + $data);
    dcai_flash('success', '产品已更新');
    header('Location: ' . DCAI_Admin::adminUrl('products.php'));
    exit;
}

// 切换开关
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_auth') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['value'] ?? 0);
    $db->update('products', ['enforce_auth' => $val, 'updated_at' => dcai_now()], 'id = ?', [$id]);
    DCAI_Admin::opLog($val ? '开启授权管控' : '关闭授权管控', ['product_id' => $id]);
    dcai_flash('success', $val ? '已开启授权管控（三合一验证生效）' : '已关闭授权管控（默认放行）');
    header('Location: ' . DCAI_Admin::adminUrl('products.php'));
    exit;
}

// 删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $ref = (int)$db->queryValue('SELECT COUNT(*) FROM licenses WHERE product_id = ?', [$id])
        + (int)$db->queryValue('SELECT COUNT(*) FROM instances WHERE product_id = ?', [$id]);
    if ($ref > 0) {
        $db->update('products', ['status' => 0, 'updated_at' => dcai_now()], 'id = ?', [$id]);
        DCAI_Admin::opLog('下架产品（存在关联数据）', ['id' => $id]);
        dcai_flash('warning', '产品存在关联授权码/实例，已执行下架');
    } else {
        $db->delete('products', 'id = ?', [$id]);
        DCAI_Admin::opLog('删除产品', ['id' => $id]);
        dcai_flash('success', '产品已删除');
    }
    header('Location: ' . DCAI_Admin::adminUrl('products.php'));
    exit;
}

$products = $db->query('SELECT p.*,
    (SELECT COUNT(*) FROM licenses l WHERE l.product_id = p.id) AS license_count,
    (SELECT COUNT(*) FROM instances i WHERE i.product_id = p.id) AS instance_count,
    (SELECT COUNT(*) FROM instances i WHERE i.product_id = p.id AND i.status = 1) AS online_count
    FROM products p ORDER BY p.id DESC');

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div class="filters">
        <input type="text" placeholder="搜索产品…" id="search-input" onkeyup="filterTable(this.value)">
    </div>
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="createModal">＋ 新建产品</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data" id="data-table">
            <thead>
            <tr><th>ID</th><th>编码</th><th>名称</th><th>当前版本</th><th>授权管控</th><th>fail_open</th><th>试用</th><th>授权码</th><th>实例(在线)</th><th>状态</th><th>操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?php echo $p['id']; ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($p['product_code']); ?></td>
                    <td><?php echo DCAI_Util::e($p['name']); ?></td>
                    <td><?php echo DCAI_Util::e($p['current_version']); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php echo DCAI_Csrf::field(); ?>
                            <input type="hidden" name="action" value="toggle_auth">
                            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <input type="hidden" name="value" value="<?php echo (int)$p['enforce_auth'] ? 0 : 1; ?>">
                            <button type="submit" class="badge <?php echo (int)$p['enforce_auth'] ? 'green' : 'gray'; ?>" style="border:none;cursor:pointer;">
                                <?php echo (int)$p['enforce_auth'] ? '已开启' : '未开启'; ?>
                            </button>
                        </form>
                    </td>
                    <td><?php echo (int)$p['fail_open'] ? '<span class="badge blue">放行</span>' : '<span class="badge red">拒绝</span>'; ?></td>
                    <td><?php echo (int)($p['trial_enabled'] ?? 0) ? '<span class="badge purple">试用 ' . (int)($p['trial_days'] ?? 0) . '天</span>' : '<span class="badge gray">未开放</span>'; ?></td>
                    <td><?php echo (int)$p['license_count']; ?></td>
                    <td><?php echo (int)$p['instance_count']; ?><span class="muted">(<?php echo (int)$p['online_count']; ?>)</span></td>
                    <td><?php echo (int)$p['status'] ? '<span class="badge green">上架</span>' : '<span class="badge gray">下架</span>'; ?></td>
                    <td>
                        <div class="actions">
                            <button class="btn btn-outline btn-xs" data-modal-open="editModal-<?php echo (int)$p['id']; ?>">编辑</button>
                            <a class="btn btn-outline btn-xs" href="<?php echo DCAI_Admin::adminUrl('licenses.php'); ?>?product_id=<?php echo (int)$p['id']; ?>">授权码</a>
                            <a class="btn btn-outline btn-xs" href="<?php echo DCAI_Admin::adminUrl('packages.php'); ?>?product_id=<?php echo (int)$p['id']; ?>">安装包</a>
                            <a class="btn btn-outline btn-xs" href="<?php echo DCAI_Admin::adminUrl('updates.php'); ?>?product_id=<?php echo (int)$p['id']; ?>">更新</a>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button class="btn btn-danger-outline btn-xs" data-confirm-danger="删除/下架该产品？关联数据将保留。">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$products): ?><tr><td colspan="11" class="empty">暂无产品，点击右上角「新建产品」或到「安装包」页面上传安装包自动创建</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 新建 -->
<div class="modal-mask" id="createModal">
    <div class="modal">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-head"><h3>新建产品</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-row"><label>产品编码 <span class="req">*</span></label><input type="text" name="product_code" required placeholder="如 shop_v2"><div class="help-text">全局唯一，仅字母数字下划线</div></div>
                    <div class="form-row"><label>产品名称 <span class="req">*</span></label><input type="text" name="name" required placeholder="商城系统 V2"></div>
                    <div class="form-row"><label>授权管控</label>
                        <select name="enforce_auth"><option value="0">未开启（默认放行）</option><option value="1">开启三合一验证</option></select>
                    </div>
                    <div class="form-row"><label>fail_open（系统不可达）</label>
                        <select name="fail_open"><option value="1">默认放行</option><option value="0">拒绝运行</option></select>
                    </div>
                    <div class="form-row full"><label>验证令牌缓存秒数 verify_ttl</label><input type="number" name="verify_ttl" value="3600" min="60"></div>
                    <div class="form-row"><label>开放试用（创建后可再编辑）</label>
                        <select name="trial_enabled"><option value="0">不开放</option><option value="1">开放自助申请</option></select>
                    </div>
                    <div class="form-row"><label>试用天数</label><input type="number" name="trial_days" value="7" min="0" placeholder="如 7"></div>
                    <div class="form-row full"><label>产品描述</label><textarea name="description" rows="3"></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">创建</button></div>
        </form>
    </div>
</div>

<?php foreach ($products as $p): ?>
<div class="modal-mask" id="editModal-<?php echo (int)$p['id']; ?>">
    <div class="modal">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
            <div class="modal-head"><h3>编辑产品 #<?php echo (int)$p['id']; ?></h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-row"><label>产品编码</label><input type="text" value="<?php echo DCAI_Util::e($p['product_code']); ?>" disabled></div>
                    <div class="form-row"><label>产品名称 <span class="req">*</span></label><input type="text" name="name" value="<?php echo DCAI_Util::e($p['name']); ?>" required></div>
                    <div class="form-row"><label>授权管控</label>
                        <select name="enforce_auth"><option value="0" <?php echo !(int)$p['enforce_auth'] ? 'selected' : ''; ?>>未开启（默认放行）</option><option value="1" <?php echo (int)$p['enforce_auth'] ? 'selected' : ''; ?>>开启三合一验证</option></select>
                    </div>
                    <div class="form-row"><label>fail_open</label>
                        <select name="fail_open"><option value="1" <?php echo (int)$p['fail_open'] ? 'selected' : ''; ?>>默认放行</option><option value="0" <?php echo !(int)$p['fail_open'] ? 'selected' : ''; ?>>拒绝运行</option></select>
                    </div>
                    <div class="form-row"><label>verify_ttl（秒）</label><input type="number" name="verify_ttl" value="<?php echo (int)$p['verify_ttl']; ?>" min="60"></div>
                    <div class="form-row"><label>状态</label>
                        <select name="status"><option value="1" <?php echo (int)$p['status'] ? 'selected' : ''; ?>>上架</option><option value="0" <?php echo !(int)$p['status'] ? 'selected' : ''; ?>>下架</option></select>
                    </div>
                    <div class="form-row full" style="border-top:1px solid #e5e7eb;padding-top:12px;margin-top:4px;"><label>商城销售</label>
                        <select name="for_sale"><option value="0" <?php echo !(int)($p['for_sale'] ?? 0) ? 'selected' : ''; ?>>仅内部授权</option><option value="1" <?php echo (int)($p['for_sale'] ?? 0) ? 'selected' : ''; ?>>商城对外销售</option></select>
                    </div>
                    <div class="form-row"><label>售价（元）</label><input type="number" name="sale_price" value="<?php echo (float)($p['sale_price'] ?? 0); ?>" min="0" step="0.01"></div>
                    <div class="form-row"><label>计费单位</label>
                        <select name="price_unit">
                            <?php $u = ($p['price_unit'] ?? 'year'); ?>
                            <option value="month" <?php echo $u === 'month' ? 'selected' : ''; ?>>月付</option>
                            <option value="quarter" <?php echo $u === 'quarter' ? 'selected' : ''; ?>>季付</option>
                            <option value="half_year" <?php echo $u === 'half_year' ? 'selected' : ''; ?>>半年付</option>
                            <option value="year" <?php echo $u === 'year' ? 'selected' : ''; ?>>年付</option>
                            <option value="perpetual" <?php echo $u === 'perpetual' ? 'selected' : ''; ?>>永久</option>
                        </select>
                    </div>
                    <div class="form-row"><label>图标（emoji/URL）</label><input type="text" name="sale_icon" value="<?php echo DCAI_Util::e($p['sale_icon'] ?? ''); ?>"></div>
                    <div class="form-row"><label>开放试用</label>
                        <select name="trial_enabled"><option value="0" <?php echo !(int)($p['trial_enabled'] ?? 0) ? 'selected' : ''; ?>>不开放</option><option value="1" <?php echo (int)($p['trial_enabled'] ?? 0) ? 'selected' : ''; ?>>开放自助申请</option></select>
                    </div>
                    <div class="form-row"><label>试用天数</label><input type="number" name="trial_days" value="<?php echo (int)($p['trial_days'] ?? 0); ?>" min="0" placeholder="如 7"></div>
                    <div class="form-row full"><label>商城详情</label><textarea name="sale_intro" rows="2"><?php echo DCAI_Util::e($p['sale_intro'] ?? ''); ?></textarea></div>
                    <div class="form-row full"><label>描述</label><textarea name="description" rows="3"><?php echo DCAI_Util::e($p['description']); ?></textarea></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>
<script>
function filterTable(v) {
    document.querySelectorAll('#data-table tbody tr').forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(v.toLowerCase()) >= 0 ? '' : 'none';
    });
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

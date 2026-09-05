<?php
$pageTitle = '安装包管理';
$activeMenu = 'package';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('package');

$db = dcai_db();

// 上传安装包
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    DCAI_Csrf::check();
    $file = $_FILES['package'] ?? null;
    if (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        dcai_flash('danger', '请选择 zip 安装包文件');
    } else {
        [$ok, $res, $err] = DCAI_PackageService::upload($file);
        if ($ok) {
            DCAI_Admin::opLog('上传安装包', $res);
            dcai_flash('success', '安装包上传成功，已自动创建/更新产品 #' . $res['product_id']);
        } else {
            dcai_flash('danger', '上传失败：' . $err);
        }
    }
    header('Location: ' . DCAI_Admin::adminUrl('packages.php'));
    exit;
}

// 停用/启用
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['value'] ?? 0);
    $db->update('install_packages', ['status' => $val], 'id = ?', [$id]);
    DCAI_Admin::opLog('切换安装包状态', ['id' => $id, 'status' => $val]);
    dcai_flash('success', '操作成功');
    header('Location: ' . DCAI_Admin::adminUrl('packages.php'));
    exit;
}

$productFilter = (int)($_GET['product_id'] ?? 0);
$sql = 'SELECT pk.*, p.name AS product_name, p.product_code FROM install_packages pk LEFT JOIN products p ON p.id = pk.product_id';
$params = [];
if ($productFilter) {
    $sql .= ' WHERE pk.product_id = ?';
    $params[] = $productFilter;
}
$sql .= ' ORDER BY pk.id DESC';
$packages = $db->query($sql, $params);
$products = $db->query('SELECT id, name, product_code FROM products ORDER BY id DESC');

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div class="filters">
        <select onchange="location.href='<?php echo DCAI_Admin::adminUrl('packages.php'); ?>' + (this.value ? '?product_id=' + this.value : '')">
            <option value="">全部产品</option>
            <?php foreach ($products as $prod): ?>
                <option value="<?php echo (int)$prod['id']; ?>" <?php echo $productFilter === (int)$prod['id'] ? 'selected' : ''; ?>><?php echo DCAI_Util::e($prod['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="uploadModal">⬆ 上传安装包</button>
</div>

<div class="card">
    <p class="section-sub">上传包含 <code>manifest.json</code> 的 zip 安装包，系统自动解析并创建/更新产品记录。</p>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>产品</th><th>版本</th><th>大小</th><th>MD5</th><th>下载次数</th><th>状态</th><th>上传时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($packages as $pk): ?>
                <tr>
                    <td><?php echo (int)$pk['id']; ?></td>
                    <td><?php echo DCAI_Util::e($pk['product_name'] ?: $pk['product_id']); ?> <span class="muted mono"><?php echo DCAI_Util::e($pk['product_code']); ?></span></td>
                    <td><?php echo DCAI_Util::e($pk['version']); ?></td>
                    <td><?php echo DCAI_Util::humanSize((int)$pk['package_size']); ?></td>
                    <td class="mono" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e($pk['package_md5']); ?></td>
                    <td><?php echo (int)$pk['download_count']; ?></td>
                    <td><?php echo (int)$pk['status'] ? '<span class="badge green">可用</span>' : '<span class="badge gray">停用</span>'; ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($pk['created_at']); ?></td>
                    <td>
                        <div class="actions">
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo (int)$pk['id']; ?>">
                                <input type="hidden" name="value" value="<?php echo (int)$pk['status'] ? 0 : 1; ?>">
                                <button class="btn btn-outline btn-xs"><?php echo (int)$pk['status'] ? '停用' : '启用'; ?></button>
                            </form>
                            <button class="btn btn-outline btn-xs" data-modal-open="manifest-<?php echo (int)$pk['id']; ?>">manifest</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$packages): ?><tr><td colspan="9" class="empty">暂无安装包，点击右上角上传</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-mask" id="uploadModal">
    <div class="modal">
        <form method="post" enctype="multipart/form-data">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="upload">
            <div class="modal-head"><h3>上传安装包</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-row"><label>安装包 zip 文件 <span class="req">*</span></label><input type="file" name="package" accept=".zip" required></div>
                <div class="help-text" style="margin-bottom:12px;">zip 顶层必须包含 manifest.json：</div>
                <pre class="code-area" style="background:#f6f8fa;padding:12px;border-radius:8px;">{
  "product_code": "shop_v2",
  "product_name": "商城系统 V2",
  "version": "1.0.0",
  "description": "多商户商城系统"
}</pre>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">上传并解析</button></div>
        </form>
    </div>
</div>

<?php foreach ($packages as $pk): ?>
<div class="modal-mask" id="manifest-<?php echo (int)$pk['id']; ?>">
    <div class="modal">
        <div class="modal-head"><h3>manifest #<?php echo (int)$pk['id']; ?></h3><button type="button" class="close" data-modal-close>×</button></div>
        <div class="modal-body"><pre class="code-area" style="background:#f6f8fa;padding:12px;border-radius:8px;max-height:60vh;overflow:auto;"><?php echo DCAI_Util::e($pk['manifest']); ?></pre></div>
        <div class="modal-foot"><button class="btn btn-outline" data-modal-close>关闭</button></div>
    </div>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

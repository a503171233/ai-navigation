<?php
/**
 * 我的授权列表 + 自助绑定域名/IP
 */
require __DIR__ . '/_init.php';

if (!$currentBuyer) {
    header('Location: ' . shop_url('login'));
    exit;
}

$db = dcai_db();

// 绑定处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_csrf_check();
    $licenseId = (int)($_POST['license_id'] ?? 0);
    $domains = (array)($_POST['domains'] ?? []);
    $ips = (array)($_POST['ips'] ?? []);
    [$ok, $err] = DCAI_Store::bindLicense($licenseId, (int)$currentBuyer['id'], $domains, $ips);
    shop_flash($ok ? 'success' : 'danger', $ok ? '绑定已更新，稍后生效' : ($err ?: '操作失败'));
    header('Location: ' . shop_url('licenses'));
    exit;
}

$languages = DCAI_Store::buyerLicenses((int)$currentBuyer['id']);

// 获取买家已购产品的最新安装包
$installedProducts = [];
foreach ($languages as $lic) {
    $pkg = $db->queryOne(
        'SELECT id, product_id, version FROM install_packages
         WHERE product_id = ? AND status = 1 ORDER BY id DESC LIMIT 1',
        [(int)$lic['product_id']]
    );
    if ($pkg) {
        $installedProducts[$lic['product_id']] = $pkg;
    }
}

$pageTitle = '我的授权';
shop_layout_start($pageTitle);
?>
<h1 style="font-size:22px;margin-bottom:20px;">我的授权</h1>
<?php if (!$languages): ?>
    <div class="card empty">暂无授权。<a href="<?php echo shop_url('home'); ?>">去购买授权</a></div>
<?php else: ?>
<?php foreach ($languages as $lic):
    $downloadable = $installedProducts[$lic['product_id']] ?? null;
    ?>
<div class="card">
    <div class="shop-flex" style="margin-bottom:12px;">
        <div>
            <span class="badge badge-blue"><?php echo DCAI_Util::e($lic['product_name']); ?></span>
            <?php if ($lic['is_trial']): ?><span class="badge badge-orange">试用授权</span><?php endif; ?>
            <?php if ($lic['is_permanent']): ?><span class="badge badge-purple">永久授权</span>
            <?php else: ?><span class="badge <?php echo $lic['days_left'] > 7 ? 'badge-green' : 'badge-red'; ?>">剩余 <?php echo $lic['days_left'] > 0 ? $lic['days_left'] : 0; ?> 天</span><?php endif; ?>
            <?php echo (int)$lic['status'] === 1 ? '<span class="badge badge-green">有效</span>' : '<span class="badge badge-red">已禁用</span>'; ?>
        </div>
        <?php if ($downloadable): ?>
        <a href="<?php echo shop_url('download?package_id=' . (int)$downloadable['id']); ?>" class="btn btn-outline btn-sm">⬇ 下载安装包 v<?php echo DCAI_Util::e($downloadable['version']); ?></a>
        <?php endif; ?>
    </div>
    <div class="mono" style="font-size:15px;background:#f6f8fa;padding:10px;border-radius:6px;margin-bottom:12px;letter-spacing:1px;">
        <?php echo DCAI_Util::e($lic['license_key']); ?>
        <span style="float:right;font-size:12px;color:#6b7280;"><?php echo $lic['expire_at'] ? DCAI_Util::e($lic['expire_at']) : '永久'; ?></span>
    </div>

    <form method="post">
        <?php echo shop_csrf_field(); ?>
        <input type="hidden" name="license_id" value="<?php echo (int)$lic['id']; ?>">
        <div class="form-group">
            <label>允许域名（每行一个，支持 *.example.com 通配符）</label>
            <textarea name="domains[]" class="form-control" rows="2" style="resize:vertical;"><?php echo DCAI_Util::e(implode("\n", $lic['allowed_domains'])); ?></textarea>
        </div>
        <div class="form-group">
            <label>允许 IP（每行一个，支持 1.2.3.0/24 网段）</label>
            <textarea name="ips[]" class="form-control" rows="2" style="resize:vertical;"><?php echo DCAI_Util::e(implode("\n", $lic['allowed_ips'])); ?></textarea>
            <div class="form-help">被授权程序将以 <code>授权码 + 域名 + IP</code> 三合一验证，三者全部命中才判定授权。</div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">保存绑定</button>
    </form>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php shop_layout_end(); ?>
<?php
$pageTitle = '系统升级';
$activeMenu = 'sysupdate';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('sysupdate');

$db = dcai_db();

// 上传升级包
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    DCAI_Csrf::check();
    $file = $_FILES['sys_pkg'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        dcai_flash('danger', '请选择 zip 升级包');
    } else {
        [$ok, $res, $err] = DCAI_SystemUpdateService::upload($file, DCAI_Admin::id() ?? 0);
        if ($ok) {
            DCAI_Admin::opLog('上传系统升级包', $res);
            dcai_flash('success', "系统升级包 v{$res['version']} 上传成功，当前为待发布状态");
        } else {
            dcai_flash('danger', '上传失败：' . $err);
        }
    }
    header('Location: ' . DCAI_Admin::adminUrl('system_update.php'));
    exit;
}

// 发布/撤回
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publish') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $val = (int)($_POST['value'] ?? 1);
    [$ok, , $err] = DCAI_SystemUpdateService::publish($id, $val);
    if ($ok) {
        DCAI_Admin::opLog($val === 1 ? '发布系统升级包' : '撤回系统升级包', ['id' => $id]);
        dcai_flash('success', $val === 1 ? '系统升级包已发布，可执行一键升级' : '系统升级包已撤回');
    } else {
        dcai_flash('danger', $err);
    }
    header('Location: ' . DCAI_Admin::adminUrl('system_update.php'));
    exit;
}

// 应用系统升级
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    [$ok, $res, $err] = DCAI_SystemUpdateService::apply($id, DCAI_Admin::id() ?? 0);
    if ($ok) {
        dcai_flash('success', "系统已升级至 v{$res['version']}");
    } else {
        dcai_flash('danger', $err);
    }
    header('Location: ' . DCAI_Admin::adminUrl('system_update.php'));
    exit;
}

// 保存远程升级源配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_source') {
    DCAI_Csrf::check();
    DCAI_Settings::set('update_source_enabled', (int)($_POST['update_source_enabled'] ?? 0));
    DCAI_Settings::set('update_source_manifest', trim($_POST['update_source_manifest'] ?? ''));
    $token = trim($_POST['update_source_auth_token'] ?? '');
    // token 留空且原值存在时保留原值（避免保存时清空）
    if ($token === '') {
        $old = DCAI_Settings::get('update_source_auth_token', '');
        if ($old !== '') { $token = $old; }
    }
    DCAI_Settings::set('update_source_auth_token', $token);
    DCAI_Settings::set('update_source_timeout', max(5, (int)($_POST['update_source_timeout'] ?? 15)));
    DCAI_Admin::opLog('保存远程升级源配置');
    dcai_flash('success', '远程升级源配置已保存');
    header('Location: ' . DCAI_Admin::adminUrl('system_update.php'));
    exit;
}

// 检查远程更新（AJAX/普通提交均可）
$remoteInfo = null;
$remoteChecked = false;
if (($_GET['check_remote'] ?? 0) == 1) {
    $remoteInfo = DCAI_SystemUpdateService::checkRemote();
    $remoteChecked = true;
    if ($remoteInfo === null) {
        $src = dcai_config('update_source', []);
        if (empty($src['enabled']) || empty($src['manifest'])) {
            dcai_flash('warning', '未配置远程升级源，请先在下方填写 manifest 地址并启用');
        } else {
            dcai_flash('success', '已是最新版本，无需更新');
        }
    }
}

// 下载远程升级包并登记
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch_remote') {
    DCAI_Csrf::check();
    $remote = [
        'url' => trim($_POST['remote_url'] ?? ''),
        'version' => trim($_POST['remote_version'] ?? ''),
        'md5' => trim($_POST['remote_md5'] ?? ''),
    ];
    [$ok, $res, $err] = DCAI_SystemUpdateService::fetchRemote($remote, DCAI_Admin::id() ?? 0);
    if ($ok) {
        DCAI_Admin::opLog('下载远程升级包', $res);
        dcai_flash('success', "远程升级包 v{$res['version']} 已下载并发布，可直接一键升级");
    } else {
        dcai_flash('danger', '下载失败：' . $err);
    }
    header('Location: ' . DCAI_Admin::adminUrl('system_update.php'));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$total = (int)$db->queryValue('SELECT COUNT(*) FROM system_updates');
$offset = ($page - 1) * $per;
$updates = $db->query('SELECT * FROM system_updates ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . $offset);
$curVersion = DCAI_SYSTEM_VERSION;

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div class="filters">
        <span class="muted">当前系统版本：<span class="badge purple"><?php echo DCAI_Util::e($curVersion); ?></span></span>
        <?php if ($remoteInfo): ?>
            <span class="badge orange" style="margin-left:8px;">发现新版本 v<?php echo DCAI_Util::e($remoteInfo['version']); ?></span>
        <?php endif; ?>
    </div>
    <div class="spacer"></div>
    <a href="<?php echo DCAI_Admin::adminUrl('system_update.php?check_remote=1'); ?>" class="btn btn-outline">🔄 检查远程更新</a>
    <button class="btn btn-primary" data-modal-open="uploadModal">⬆ 上传升级包</button>
</div>

<?php if ($remoteInfo): ?>
<div class="card" style="border:1px solid #f59e0b;">
    <div class="card-title" style="color:#d97706;">发现新版本 <span class="badge purple">v<?php echo DCAI_Util::e($remoteInfo['version']); ?></span></div>
    <p>当前版本 <code><?php echo DCAI_Util::e($curVersion); ?></code> → 目标版本 <code><?php echo DCAI_Util::e($remoteInfo['version']); ?></code></p>
    <?php if ($remoteInfo['changelog']): ?><p class="muted" style="margin-top:8px;">更新说明：<?php echo nl2br(DCAI_Util::e($remoteInfo['changelog'])); ?></p><?php endif; ?>
    <div class="actions" style="margin-top:12px;">
        <form method="post" style="display:inline;">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="fetch_remote">
            <input type="hidden" name="remote_url" value="<?php echo DCAI_Util::e($remoteInfo['url']); ?>">
            <input type="hidden" name="remote_version" value="<?php echo DCAI_Util::e($remoteInfo['version']); ?>">
            <input type="hidden" name="remote_md5" value="<?php echo DCAI_Util::e($remoteInfo['md5']); ?>">
            <button class="btn btn-success" data-confirm="确认从远程下载并发布 v<?php echo DCAI_Util::e($remoteInfo['version']); ?> 升级包？">⬇ 下载并发布此版本</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">远程升级源配置</div>
    <form method="post" style="max-width:640px;">
        <?php echo DCAI_Csrf::field(); ?>
        <input type="hidden" name="action" value="save_source">
        <?php
        $us = dcai_config('update_source', []);
        $us['enabled'] = (int)($us['enabled'] ?? 0);
        $us['manifest'] = (string)($us['manifest'] ?? '');
        $us['auth_token'] = (string)($us['auth_token'] ?? '');
        $us['timeout'] = (int)($us['timeout'] ?? 15);
        ?>
        <div class="form-grid">
            <div class="form-row"><label>启用远程更新</label>
                <select name="update_source_enabled">
                    <option value="0" <?php echo $us['enabled'] ? '' : 'selected'; ?>>关闭</option>
                    <option value="1" <?php echo $us['enabled'] ? 'selected' : ''; ?>>开启</option>
                </select>
            </div>
            <div class="form-row"><label>超时（秒）</label><input type="number" name="update_source_timeout" value="<?php echo $us['timeout']; ?>" min="5" max="60"></div>
            <div class="form-row full"><label>manifest.json 地址</label><input type="text" name="update_source_manifest" value="<?php echo DCAI_Util::e($us['manifest']); ?>" placeholder="https://cdn.example.com/dcai/manifest.json"></div>
            <div class="form-row full"><label>私有仓库 Token（可选）</label><input type="password" name="update_source_auth_token" value="<?php echo DCAI_Util::e($us['auth_token']); ?>" placeholder="留空保留原值"><div class="help-text">填写后以 Bearer Token 方式请求 manifest 与升级包</div></div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:10px;">保存配置</button>
        <a href="<?php echo DCAI_Admin::adminUrl('system_update.php?check_remote=1'); ?>" class="btn btn-outline" style="margin-top:10px;">立即检查</a>
    </form>
</div>

<div class="card">
    <p class="section-sub">升级包 zip 顶层必须包含 <code>system.json</code>：<code>{"version":"1.1.0","min_version":"1.0.0","changelog":"修复若干Bug","files":["core/Bootstrap.php",...],"delete_files":[...],"migrate":"migrations/v1_1_0.php"}</code>。应用升级前会自动备份，失败自动回滚，保留最近 3 份备份。</p>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>版本</th><th>最低版本</th><th>大小</th><th>MD5</th><th>状态</th><th>更新日志</th><th>发布时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($updates as $u): ?>
                <tr>
                    <td><?php echo (int)$u['id']; ?></td>
                    <td><span class="badge purple"><?php echo DCAI_Util::e($u['version']); ?></span></td>
                    <td><?php echo DCAI_Util::e($u['min_version'] ?: '-'); ?></td>
                    <td><?php echo DCAI_Util::humanSize((int)$u['package_size']); ?></td>
                    <td class="mono" style="max-width:110px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e($u['package_md5']); ?></td>
                    <td>
                        <?php
                        $statusMap = [
                            0 => ['待发布', 'gray'],
                            1 => ['已发布', 'green'],
                            2 => ['已应用', 'blue'],
                            3 => ['已撤回', 'orange'],
                            4 => ['应用失败', 'red'],
                        ];
                        [$label, $color] = $statusMap[(int)$u['status']] ?? ['未知', 'gray'];
                        ?>
                        <span class="badge <?php echo $color; ?>"><?php echo $label; ?></span>
                    </td>
                    <td class="muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e(mb_substr($u['changelog'] ?? '', 0, 40)); ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($u['created_at']); ?></td>
                    <td>
                        <div class="actions">
                            <?php if ((int)$u['status'] === 1): ?>
                                <form method="post" style="display:inline;">
                                    <?php echo DCAI_Csrf::field(); ?>
                                    <input type="hidden" name="action" value="publish">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <input type="hidden" name="value" value="3">
                                    <button class="btn btn-outline btn-xs" data-confirm="确认撤回该系统升级包？">撤回</button>
                                </form>
                            <?php else: ?>
                                <form method="post" style="display:inline;">
                                    <?php echo DCAI_Csrf::field(); ?>
                                    <input type="hidden" name="action" value="publish">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <input type="hidden" name="value" value="1">
                                    <button class="btn btn-success btn-xs" <?php echo (int)$u['status'] === 2 ? 'disabled' : ''; ?>>发布</button>
                                </form>
                            <?php endif; ?>
                            <?php if ((int)$u['status'] === 1): ?>
                                <form method="post" style="display:inline;">
                                    <?php echo DCAI_Csrf::field(); ?>
                                    <input type="hidden" name="action" value="apply">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <button class="btn btn-primary btn-xs" data-confirm="确认应用该系统升级？升级过程中失败将自动回滚。">一键升级</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$updates): ?><tr><td colspan="9" class="empty">暂无系统升级包</td></tr><?php endif; ?>
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
            <div class="modal-head"><h3>上传系统升级包</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <div class="form-row"><label>升级包 zip <span class="req">*</span></label><input type="file" name="sys_pkg" accept=".zip" required></div>
                <p class="muted" style="font-size:12px;margin-top:8px;">系统升级将直接替换本服务代码，请确保 system.json 的 files 白名单准确无误。</p>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">上传</button></div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

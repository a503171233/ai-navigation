<?php
$pageTitle = '管理员管理';
$activeMenu = 'admin';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('admin');

$db = dcai_db();

// 创建
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    DCAI_Csrf::check();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $nickname = trim($_POST['nickname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = (int)($_POST['role'] ?? 2);
    $perms = implode("\n", array_map('trim', (array)($_POST['perms'] ?? [])));
    // 越权防护：仅超级管理员可创建/提升超管
    if ($role === 1 && !DCAI_Admin::isSuper()) {
        dcai_flash('danger', '无权创建超级管理员');
    } elseif ($role === 2 && !DCAI_Admin::isSuper()) {
        // 普通管理员只能创建普通管理员，且权限点不能包含敏感项
        $perms = implode("\n", array_intersect(DCAI_Admin::parsePerms($perms), DCAI_Admin::assignablePerms()));
    } elseif ($username === '' || strlen($password) < 6) {
        dcai_flash('danger', '账号不能为空，密码至少 6 位');
    } elseif ($db->queryValue('SELECT COUNT(*) FROM admin_users WHERE username = ?', [$username])) {
        dcai_flash('danger', '账号已存在');
    } else {
        $now = dcai_now();
        $db->insert('admin_users', [
            'username'     => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'nickname'     => $nickname,
            'email'        => $email,
            'role'         => $role,
            'status'       => 1,
            'permissions'  => $perms,
            'twofa_secret' => '',
            'last_login_ip'=> '',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        DCAI_Admin::opLog('创建管理员', ['username' => $username, 'role' => $role]);
        dcai_flash('success', '管理员创建成功');
    }
    header('Location: ' . DCAI_Admin::adminUrl('admins.php'));
    exit;
}

// 编辑
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $target = $db->queryOne('SELECT * FROM admin_users WHERE id = ?', [$id]);
    if (!$target) {
        dcai_flash('danger', '管理员不存在');
        header('Location: ' . DCAI_Admin::adminUrl('admins.php'));
        exit;
    }
    $data = [
        'nickname'    => trim($_POST['nickname'] ?? ''),
        'email'       => trim($_POST['email'] ?? ''),
        'role'        => (int)($_POST['role'] ?? 2),
        'status'      => (int)($_POST['status'] ?? 1),
        'permissions' => implode("\n", array_map('trim', (array)($_POST['perms'] ?? []))),
        'updated_at'  => dcai_now(),
    ];
    // 越权防护
    if (!DCAI_Admin::isSuper()) {
        if ((int)$target['role'] === 1) {
            dcai_flash('danger', '无权修改超级管理员');
            header('Location: ' . DCAI_Admin::adminUrl('admins.php'));
            exit;
        }
        $data['role'] = 2; // 普通管理员不能提权
        $data['permissions'] = implode("\n", array_intersect(DCAI_Admin::parsePerms($data['permissions']), DCAI_Admin::assignablePerms()));
    }
    if ($data['role'] === 1) {
        $data['permissions'] = '';
    }
    // 不能禁用自己的超管身份/自身
    if ((int)$_POST['id'] === DCAI_Admin::id() && (int)$data['status'] === 0) {
        dcai_flash('danger', '不能禁用当前登录账号');
    } else {
        $db->update('admin_users', $data, 'id = ?', [$id]);
        $password = (string)($_POST['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 6) {
                dcai_flash('danger', '密码至少 6 位');
                header('Location: ' . DCAI_Admin::adminUrl('admins.php'));
                exit;
            }
            $db->update('admin_users', ['password_hash' => password_hash($password, PASSWORD_BCRYPT)], 'id = ?', [$id]);
        }
        DCAI_Admin::opLog('编辑管理员', ['id' => $id]);
        dcai_flash('success', '管理员已更新');
    }
    header('Location: ' . DCAI_Admin::adminUrl('admins.php'));
    exit;
}

// 删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    if ($id === DCAI_Admin::id()) {
        dcai_flash('danger', '不能删除当前登录账号');
    } elseif (!DCAI_Admin::isSuper() && (int)$db->queryValue('SELECT role FROM admin_users WHERE id = ?', [$id]) === 1) {
        dcai_flash('danger', '无权删除超级管理员');
    } elseif ((int)$db->queryValue('SELECT role FROM admin_users WHERE id = ?', [$id]) === 1) {
        $superCount = (int)$db->queryValue('SELECT COUNT(*) FROM admin_users WHERE role = 1');
        if ($superCount <= 1) {
            dcai_flash('danger', '至少保留一个超级管理员');
        } else {
            $db->delete('admin_users', 'id = ?', [$id]);
            DCAI_Admin::opLog('删除管理员', ['id' => $id]);
            dcai_flash('success', '管理员已删除');
        }
    } else {
        $db->delete('admin_users', 'id = ?', [$id]);
        DCAI_Admin::opLog('删除管理员', ['id' => $id]);
        dcai_flash('success', '管理员已删除');
    }
    header('Location: ' . DCAI_Admin::adminUrl('admins.php'));
    exit;
}

// 2FA：为管理员绑定/解绑 动态验证器
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === '2fa_bind') {
    DCAI_Csrf::check();
    $id = (int)($_POST['id'] ?? 0);
    $op = (string)($_POST['op'] ?? 'bind');
    $code = trim((string)($_POST['code'] ?? ''));
    $target = $db->queryOne('SELECT * FROM admin_users WHERE id = ?', [$id]);
    if (!$target) {
        dcai_flash('danger', '管理员不存在');
        header('Location: ' . DCAI_Admin::adminUrl('admins.php'));
        exit;
    }
    // 仅超管可管理他人 2FA；普通管理员仅可管理自己
    if (!DCAI_Admin::isSuper() && $id !== DCAI_Admin::id()) {
        dcai_flash('danger', '无权管理该管理员的 2FA');
        header('Location: ' . DCAI_Admin::adminUrl('admins.php'));
        exit;
    }
    if ($op === 'bind') {
        // 绑定：密钥由表单页面生成并提交（保证用户扫码的密钥与保存的一致）
        $secret = strtoupper(trim((string)($_POST['secret'] ?? '')));
        if (!preg_match('/^[A-Z2-7]{16,64}$/', $secret)) {
            dcai_flash('danger', '密钥格式错误，请刷新页面重试');
        } elseif (!DCAI_Totp::verify($secret, $code)) {
            dcai_flash('danger', '动态验证码错误，绑定未完成，请确认 App 中已正确添加该密钥');
        } else {
            $db->update('admin_users', ['twofa_secret' => $secret, 'updated_at' => dcai_now()], 'id = ?', [$id]);
            DCAI_Admin::opLog('启用 2FA', ['admin_id' => $id]);
            dcai_flash('success', '两步验证已启用');
        }
    } elseif ($op === 'disable') {
        if (!DCAI_Totp::verify((string)$target['twofa_secret'], $code)) {
            dcai_flash('danger', '动态验证码错误，解绑未完成');
        } else {
            $db->update('admin_users', ['twofa_secret' => '', 'updated_at' => dcai_now()], 'id = ?', [$id]);
            DCAI_Admin::opLog('解绑 2FA', ['admin_id' => $id]);
            dcai_flash('success', '已解绑两步验证');
        }
    }
    header('Location: ' . DCAI_Admin::adminUrl('admins.php'));
    exit;
}

$admins = $db->query('SELECT * FROM admin_users ORDER BY id ASC');

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal-open="createModal">＋ 新建管理员</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>ID</th><th>账号</th><th>昵称</th><th>邮箱</th><th>角色</th><th>状态</th><th>2FA</th><th>最后登录</th><th>最后IP</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($admins as $a):
                $canManage2fa = DCAI_Admin::isSuper() || (int)$a['id'] === DCAI_Admin::id();
                $has2fa = !empty($a['twofa_secret']);
                ?>
                <tr>
                    <td><?php echo (int)$a['id']; ?></td>
                    <td><?php echo DCAI_Util::e($a['username']); ?><?php echo (int)$a['id'] === DCAI_Admin::id() ? ' <span class="badge blue">当前</span>' : ''; ?></td>
                    <td><?php echo DCAI_Util::e($a['nickname'] ?: '-'); ?></td>
                    <td><?php echo DCAI_Util::e($a['email'] ?: '-'); ?></td>
                    <td><?php echo (int)$a['role'] === 1 ? '<span class="badge purple">超级管理员</span>' : '<span class="badge gray">普通管理员</span>'; ?></td>
                    <td><?php echo (int)$a['status'] ? '<span class="badge green">启用</span>' : '<span class="badge red">禁用</span>'; ?></td>
                    <td><?php echo $has2fa ? '<span class="badge green">已启用</span>' : '<span class="badge gray">未启用</span>'; ?></td>
                    <td class="muted"><?php echo DCAI_Util::e($a['last_login_at'] ?: '-'); ?></td>
                    <td class="mono"><?php echo DCAI_Util::e($a['last_login_ip'] ?: '-'); ?></td>
                    <td>
                        <div class="actions">
                            <button class="btn btn-outline btn-xs" data-modal-open="edit-<?php echo (int)$a['id']; ?>">编辑</button>
                            <?php if ($canManage2fa): ?>
                            <button class="btn btn-outline btn-xs" data-modal-open="2fa-<?php echo (int)$a['id']; ?>"><?php echo $has2fa ? '2FA' : '绑定2FA'; ?></button>
                            <?php endif; ?>
                            <form method="post" style="display:inline;">
                                <?php echo DCAI_Csrf::field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                                <button class="btn btn-danger-outline btn-xs" data-confirm-danger="确认删除该管理员？">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-mask" id="createModal">
    <div class="modal">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-head"><h3>新建管理员</h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <?php $a = []; include __DIR__ . '/includes/admin_form.php'; ?>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">创建</button></div>
        </form>
    </div>
</div>

<?php foreach ($admins as $a): ?>
<div class="modal-mask" id="edit-<?php echo (int)$a['id']; ?>">
    <div class="modal">
        <form method="post">
            <?php echo DCAI_Csrf::field(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
            <div class="modal-head"><h3>编辑管理员 #<?php echo (int)$a['id']; ?></h3><button type="button" class="close" data-modal-close>×</button></div>
            <div class="modal-body">
                <?php include __DIR__ . '/includes/admin_form.php'; ?>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" data-modal-close>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
        </form>
    </div>
</div>
<?php
    $canManage2fa = DCAI_Admin::isSuper() || (int)$a['id'] === DCAI_Admin::id();
    if (!$canManage2fa) { continue; }
    $has2fa = !empty($a['twofa_secret']);
?>
<div class="modal-mask" id="2fa-<?php echo (int)$a['id']; ?>">
    <div class="modal">
        <div class="modal-head"><h3>两步验证（<?php echo DCAI_Util::e($a['username']); ?>）</h3><button type="button" class="close" data-modal-close>×</button></div>
        <div class="modal-body">
            <?php if (!$has2fa): ?>
            <p class="muted">开启后，登录该账号除密码外还需输入 Google 验证器 / 微软 Authenticator 中的 6 位动态码。</p>
            <?php
                $tmpSecret = DCAI_Totp::generateSecret();
                $tmpUri = DCAI_Totp::otpauthUri($tmpSecret, dcai_config('app.name', 'DCAI'), $a['username']);
            ?>
            <div class="form-row">
                <label>1. 在验证器 App 中添加：</label>
                <a class="btn btn-outline btn-sm" href="<?php echo DCAI_Util::e($tmpUri); ?>" target="_blank">打开 otpauth 链接</a>
                <div class="help-text">或手动输入下方密钥：</div>
                <input type="text" class="mono" value="<?php echo DCAI_Util::e($tmpSecret); ?>" readonly onclick="this.select()" style="width:100%;">
            </div>
            <form method="post">
                <?php echo DCAI_Csrf::field(); ?>
                <input type="hidden" name="action" value="2fa_bind">
                <input type="hidden" name="op" value="bind">
                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                <input type="hidden" name="secret" value="<?php echo DCAI_Util::e($tmpSecret); ?>">
                <div class="form-row">
                    <label>2. 确认绑定</label>
                    <input type="text" name="code" required placeholder="输入 App 中的 6 位动态码" pattern="\d{6}" maxlength="6" style="width:100%;">
                </div>
                <div class="modal-foot" style="padding:0;margin-top:10px;">
                    <button type="button" class="btn btn-outline" data-modal-close>取消</button>
                    <button type="submit" class="btn btn-primary">确认启用</button>
                </div>
            </form>
            <?php else: ?>
            <p class="muted">该账号已启用两步验证。可通过以下方式解绑（解绑后仅密码即可登录）：</p>
            <form method="post">
                <?php echo DCAI_Csrf::field(); ?>
                <input type="hidden" name="action" value="2fa_bind">
                <input type="hidden" name="op" value="disable">
                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                <div class="form-row">
                    <label>验证器动态码</label>
                    <input type="text" name="code" required placeholder="输入 6 位动态码确认解绑" pattern="\d{6}" maxlength="6" style="width:100%;">
                </div>
                <div class="modal-foot" style="padding:0;margin-top:10px;">
                    <button type="button" class="btn btn-outline" data-modal-close>取消</button>
                    <button type="submit" class="btn btn-danger-outline">解绑 2FA</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

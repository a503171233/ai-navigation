<?php
/** 管理员表单公共组件（新建/编辑共用），依赖外部变量 $a、$isEdit */
$isEdit = !empty($a['id']);
$currentId = DCAI_Admin::id();
$selectedPerms = DCAI_Util::parseLines($a['permissions'] ?? '');
?>
<div class="form-grid">
    <?php if ($isEdit): ?>
        <div class="form-row"><label>账号</label><input type="text" value="<?php echo DCAI_Util::e($a['username']); ?>" disabled></div>
    <?php else: ?>
        <div class="form-row"><label>登录账号 <span class="req">*</span></label><input type="text" name="username" required></div>
    <?php endif; ?>
    <div class="form-row"><label><?php echo $isEdit ? '重置密码（留空不修改）' : '密码（至少6位）'; ?> <span class="req"><?php echo $isEdit ? '' : '*'; ?></span></label><input type="password" name="password" <?php echo $isEdit ? '' : 'required'; ?> autocomplete="new-password"></div>
    <div class="form-row"><label>昵称</label><input type="text" name="nickname" value="<?php echo DCAI_Util::e($a['nickname'] ?? ''); ?>"></div>
    <div class="form-row"><label>邮箱</label><input type="email" name="email" value="<?php echo DCAI_Util::e($a['email'] ?? ''); ?>"></div>
    <div class="form-row"><label>角色</label>
        <select name="role" class="role-select">
            <option value="2" <?php echo (int)($a['role'] ?? 2) === 2 ? 'selected' : ''; ?>>普通管理员</option>
            <?php if (DCAI_Admin::isSuper()): ?>
            <option value="1" <?php echo (int)($a['role'] ?? 2) === 1 ? 'selected' : ''; ?>>超级管理员</option>
            <?php endif; ?>
        </select>
    </div>
    <div class="form-row"><label>状态</label>
        <select name="status">
            <option value="1" <?php echo (int)($a['status'] ?? 1) === 1 ? 'selected' : ''; ?>>启用</option>
            <option value="0" <?php echo (int)($a['status'] ?? 1) === 0 ? 'selected' : ''; ?>>禁用</option>
        </select>
    </div>
    <div class="form-row full perm-box" <?php echo (int)($a['role'] ?? 2) === 1 ? 'style="display:none;"' : ''; ?>>
        <label>权限点（多选）</label>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach (DCAI_Admin::permOptions() as $k => $label): ?>
                <label style="display:flex;align-items:center;gap:4px;font-size:13px;background:#f6f8fa;padding:5px 10px;border-radius:6px;">
                    <input type="checkbox" name="perms[]" value="<?php echo $k; ?>" <?php echo in_array($k, $selectedPerms, true) ? 'checked' : ''; ?>>
                    <?php echo $label; ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.role-select').forEach(function (sel) {
    var box = sel.closest('form').querySelector('.perm-box');
    if (box) {
        sel.addEventListener('change', function () { box.style.display = sel.value === '1' ? 'none' : ''; });
    }
});
</script>

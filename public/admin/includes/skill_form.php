<?php
/** 技能包(Skill) 基础表单公共组件（新建/编辑共用），依赖外部变量 $m、$products */
$m = $m ?? [];
?>
<div class="form-grid">
    <div class="form-row"><label>技能编码 <span class="req">*</span></label><input type="text" name="skill_code" value="<?php echo DCAI_Util::e($m['skill_code'] ?? ''); ?>" required placeholder="如 ocr_recognize"><div class="help-text">全局唯一，供 SDK callSkill() 指定</div></div>
    <div class="form-row"><label>技能名称 <span class="req">*</span></label><input type="text" name="name" value="<?php echo DCAI_Util::e($m['name'] ?? ''); ?>" required></div>
    <div class="form-row"><label>图标（emoji）</label><input type="text" name="icon" value="<?php echo DCAI_Util::e($m['icon'] ?? '🧩'); ?>" maxlength="8"></div>
    <div class="form-row"><label>归属产品 <span class="req">*</span></label>
        <select name="product_id" required>
            <option value="">请选择</option>
            <?php foreach ($products as $prod): ?>
                <option value="<?php echo (int)$prod['id']; ?>" <?php echo (int)($m['product_id'] ?? 0) === (int)$prod['id'] ? 'selected' : ''; ?>><?php echo DCAI_Util::e($prod['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>授权模型</label>
        <select name="access_model">
            <option value="0" <?php echo (int)($m['access_model'] ?? 0) === 0 ? 'selected' : ''; ?>>产品下全部授权实例可用</option>
            <option value="1" <?php echo (int)($m['access_model'] ?? 0) === 1 ? 'selected' : ''; ?>>按授权码逐一授予</option>
        </select>
        <div class="help-text">授权模型=按授权码授予时，需在技能详情中配置 skill_grants</div>
    </div>
    <div class="form-row"><label>排序</label><input type="number" name="sort_order" value="<?php echo (int)($m['sort_order'] ?? 0); ?>"></div>
    <div class="form-row"><label>状态</label>
        <select name="status">
            <option value="1" <?php echo (int)($m['status'] ?? 1) === 1 ? 'selected' : ''; ?>>启用</option>
            <option value="0" <?php echo (int)($m['status'] ?? 1) === 0 ? 'selected' : ''; ?>>停用</option>
        </select>
    </div>
    <div class="form-row full"><label>技能描述</label><input type="text" name="description" value="<?php echo DCAI_Util::e($m['description'] ?? ''); ?>"></div>
</div>
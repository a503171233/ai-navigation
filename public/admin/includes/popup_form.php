<?php
/** 弹窗表单公共组件（新建/编辑共用），依赖外部变量 $f、$selectedInstanceIds、$products、$allInstances */
$f = $f ?? [];
$selectedInstanceIds = $selectedInstanceIds ?? [];
$startVal = ($f['start_at'] ?? '') ? str_replace(' ', 'T', $f['start_at']) : '';
$endVal = ($f['end_at'] ?? '') ? str_replace(' ', 'T', $f['end_at']) : '';
?>
<div class="form-grid">
    <div class="form-row"><label>产品 <span class="req">*</span></label>
        <select name="product_id" required>
            <option value="">请选择</option>
            <?php foreach ($products as $prod): ?>
                <option value="<?php echo (int)$prod['id']; ?>" <?php echo (int)($f['product_id'] ?? 0) === (int)$prod['id'] ? 'selected' : ''; ?>><?php echo DCAI_Util::e($prod['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>弹窗类型</label>
        <select name="popup_type">
            <option value="1" <?php echo (int)($f['popup_type'] ?? 1) === 1 ? 'selected' : ''; ?>>📢 公告</option>
            <option value="2" <?php echo (int)($f['popup_type'] ?? 1) === 2 ? 'selected' : ''; ?>>🔔 通知</option>
            <option value="3" <?php echo (int)($f['popup_type'] ?? 1) === 3 ? 'selected' : ''; ?>>⚠️ 警示</option>
        </select>
    </div>
    <div class="form-row full"><label>标题 <span class="req">*</span></label><input type="text" name="title" value="<?php echo DCAI_Util::e($f['title'] ?? ''); ?>" required></div>
    <div class="form-row full"><label>内容（支持 HTML，渲染前自动过滤）</label><textarea name="content" rows="5" class="code-area"><?php echo DCAI_Util::e($f['content'] ?? ''); ?></textarea></div>
    <div class="form-row"><label>目标范围</label>
        <select name="target_type" onchange="this.form.querySelectorAll('.inst-pick').forEach(function(e){e.style.display=this.value==='1'?'':'none'}.bind(this))">
            <option value="0" <?php echo (int)($f['target_type'] ?? 0) === 0 ? 'selected' : ''; ?>>全部实例</option>
            <option value="1" <?php echo (int)($f['target_type'] ?? 0) === 1 ? 'selected' : ''; ?>>指定实例</option>
        </select>
    </div>
    <div class="form-row"><label>每实例最大展示次数（0=不限）</label><input type="number" name="max_show_per_instance" value="<?php echo (int)($f['max_show_per_instance'] ?? 0); ?>" min="0"></div>
    <div class="form-row full inst-pick" <?php echo (int)($f['target_type'] ?? 0) === 1 ? '' : 'style="display:none;"'; ?>>
        <label>指定实例（多选）</label>
        <select name="target_instance_ids[]" multiple size="5">
            <?php foreach ($allInstances as $inst): ?>
                <option value="<?php echo (int)$inst['id']; ?>" <?php echo in_array((int)$inst['id'], $selectedInstanceIds, true) ? 'selected' : ''; ?>><?php echo DCAI_Util::e($inst['domain']); ?> #<?php echo (int)$inst['id']; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>开始时间（留空不限）</label><input type="datetime-local" name="start_at" value="<?php echo DCAI_Util::e($startVal); ?>"></div>
    <div class="form-row"><label>结束时间（留空不限）</label><input type="datetime-local" name="end_at" value="<?php echo DCAI_Util::e($endVal); ?>"></div>
    <div class="form-row"><label>状态</label>
        <select name="status">
            <option value="1" <?php echo (int)($f['status'] ?? 1) === 1 ? 'selected' : ''; ?>>启用</option>
            <option value="0" <?php echo (int)($f['status'] ?? 1) === 0 ? 'selected' : ''; ?>>停用</option>
        </select>
    </div>
</div>

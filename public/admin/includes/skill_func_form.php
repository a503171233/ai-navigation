<?php
/** 技能函数(Skill Function) 表单公共组件（新建/编辑共用），依赖外部变量 $f */
$f = $f ?? [];
$fType = (int)$f['func_type'] ?? 1;
?>
<div class="form-grid">
    <div class="form-row"><label>函数编码 <span class="req">*</span></label><input type="text" name="function_code" value="<?php echo DCAI_Util::e($f['function_code'] ?? ''); ?>" required placeholder="如 recognize"><div class="help-text">技能内唯一，供 SDK callSkill(skill_code, function_code) 指定</div></div>
    <div class="form-row"><label>函数名称 <span class="req">*</span></label><input type="text" name="name" value="<?php echo DCAI_Util::e($f['name'] ?? ''); ?>" required></div>
    <div class="form-row"><label>函数类型</label>
        <select name="func_type" class="func-type">
            <option value="1" <?php echo $fType === 1 ? 'selected' : ''; ?>>PHP 代码型</option>
            <option value="2" <?php echo $fType === 2 ? 'selected' : ''; ?>>HTTP 转发型</option>
            <option value="3" <?php echo $fType === 3 ? 'selected' : ''; ?>>数据查询型</option>
        </select>
    </div>
    <div class="form-row"><label>状态</label>
        <select name="status">
            <option value="1" <?php echo (int)($f['status'] ?? 1) === 1 ? 'selected' : ''; ?>>启用</option>
            <option value="0" <?php echo (int)($f['status'] ?? 1) === 0 ? 'selected' : ''; ?>>停用</option>
        </select>
    </div>
    <div class="form-row full"><label>函数描述</label><input type="text" name="description" value="<?php echo DCAI_Util::e($f['description'] ?? ''); ?>"></div>

    <div class="form-row full func-type-1" <?php echo $fType === 1 ? '' : 'style="display:none;"'; ?>>
        <label>PHP 代码（不含 &lt;?php，可引用 $db/$params/$logger，return 结果）</label>
        <textarea name="code" class="code-area" rows="10" spellcheck="false"><?php echo DCAI_Util::e($f['code'] ?? '$rows = $db->queryAll("SELECT 1 AS ok");
return ["ok" => true, "rows" => $rows];'); ?></textarea>
        <div class="help-text">核心逻辑托管在授权系统，被授权站点只拿到壳。</div>
    </div>

    <div class="form-row full func-type-2" <?php echo $fType === 2 ? '' : 'style="display:none;"'; ?>>
        <label>转发目标 URL（POST JSON 透传）</label>
        <input type="text" name="upstream_url" value="<?php echo DCAI_Util::e($f['upstream_url'] ?? ''); ?>" placeholder="https://internal-service/api/xxx">
    </div>

    <div class="form-row full func-type-3" <?php echo $fType === 3 ? '' : 'style="display:none;"'; ?>>
        <label>SELECT 查询模板（占位符 {name} 对应参数）</label>
        <textarea name="sql_template" class="code-area" rows="5" spellcheck="false"><?php echo DCAI_Util::e($f['sql_template'] ?? 'SELECT DATE(created_at) d, COUNT(*) c FROM orders WHERE created_at BETWEEN {start_date} AND {end_date} GROUP BY d'); ?></textarea>
    </div>

    <div class="form-row full">
        <label>参数校验规则（JSON Schema 子集）</label>
        <textarea name="params_schema" class="code-area" rows="6" spellcheck="false"><?php echo DCAI_Util::e($f['params_schema'] ?? '{"type":"object","required":["start_date","end_date"],"properties":{"start_date":{"type":"string","minLength":8},"end_date":{"type":"string","minLength":8}}}'); ?></textarea>
        <div class="help-text">支持 required / properties.{type,min,max,minLength,maxLength,enum,pattern}</div>
    </div>
</div>
<script>
document.querySelectorAll('.func-type').forEach(function (sel) {
    function apply() {
        var form = sel.closest('form');
        var v = sel.value;
        form.querySelectorAll('.func-type-1').forEach(function (e) { e.style.display = v === '1' ? '' : 'none'; });
        form.querySelectorAll('.func-type-2').forEach(function (e) { e.style.display = v === '2' ? '' : 'none'; });
        form.querySelectorAll('.func-type-3').forEach(function (e) { e.style.display = v === '3' ? '' : 'none'; });
    }
    sel.addEventListener('change', apply);
    apply();
});
</script>
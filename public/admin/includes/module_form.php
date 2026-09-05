<?php
/** 远程模块表单公共组件（新建/编辑共用），依赖外部变量 $m、$products */
$m = $m ?? [];
?>
<div class="form-grid">
    <div class="form-row"><label>模块编码 <span class="req">*</span></label><input type="text" name="module_code" value="<?php echo DCAI_Util::e($m['module_code'] ?? ''); ?>" required placeholder="如 order_stat"><div class="help-text">全局唯一，供 SDK callModule() 调用</div></div>
    <div class="form-row"><label>模块名称 <span class="req">*</span></label><input type="text" name="name" value="<?php echo DCAI_Util::e($m['name'] ?? ''); ?>" required></div>
    <div class="form-row"><label>归属产品 <span class="req">*</span></label>
        <select name="product_id" required>
            <option value="">请选择</option>
            <?php foreach ($products as $prod): ?>
                <option value="<?php echo (int)$prod['id']; ?>" <?php echo (int)($m['product_id'] ?? 0) === (int)$prod['id'] ? 'selected' : ''; ?>><?php echo DCAI_Util::e($prod['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label>模块类型</label>
        <select name="module_type" class="module-type">
            <option value="1" <?php echo (int)($m['module_type'] ?? 1) === 1 ? 'selected' : ''; ?>>PHP 代码型</option>
            <option value="2" <?php echo (int)($m['module_type'] ?? 1) === 2 ? 'selected' : ''; ?>>HTTP 转发型</option>
            <option value="3" <?php echo (int)($m['module_type'] ?? 1) === 3 ? 'selected' : ''; ?>>数据查询型</option>
        </select>
    </div>
    <div class="form-row"><label>状态</label>
        <select name="status">
            <option value="1" <?php echo (int)($m['status'] ?? 1) === 1 ? 'selected' : ''; ?>>启用</option>
            <option value="0" <?php echo (int)($m['status'] ?? 1) === 0 ? 'selected' : ''; ?>>停用</option>
        </select>
    </div>
    <div class="form-row full"><label>描述</label><input type="text" name="description" value="<?php echo DCAI_Util::e($m['description'] ?? ''); ?>"></div>

    <div class="form-row full mod-type-1" <?php echo (int)($m['module_type'] ?? 1) === 1 ? '' : 'style="display:none;"'; ?>>
        <label>模块 PHP 代码（不含 &lt;?php，可引用 $db/$params/$logger，return 结果）</label>
        <textarea name="code" class="code-area" rows="10" spellcheck="false"><?php echo DCAI_Util::e($m['code'] ?? '$rows = $db->queryAll("SELECT 1 AS ok");
return ["ok" => true, "rows" => $rows];'); ?></textarea>
        <div class="help-text">沙箱限制：危险函数禁用、执行 ≤5s、内存 ≤128M、仅可做 SELECT 查询</div>
    </div>

    <div class="form-row full mod-type-2" <?php echo (int)($m['module_type'] ?? 1) === 2 ? '' : 'style="display:none;"'; ?>>
        <label>转发目标 URL（POST JSON 透传）</label>
        <input type="text" name="upstream_url" value="<?php echo DCAI_Util::e($m['upstream_url'] ?? ''); ?>" placeholder="https://internal-service/api/xxx">
    </div>

    <div class="form-row full mod-type-3" <?php echo (int)($m['module_type'] ?? 1) === 3 ? '' : 'style="display:none;"'; ?>>
        <label>SELECT 查询模板（占位符 {name} 对应参数）</label>
        <textarea name="sql_template" class="code-area" rows="5" spellcheck="false"><?php echo DCAI_Util::e($m['sql_template'] ?? 'SELECT DATE(created_at) d, COUNT(*) c FROM orders WHERE created_at BETWEEN {start_date} AND {end_date} GROUP BY d'); ?></textarea>
    </div>

    <div class="form-row full">
        <label>参数校验规则（JSON Schema 子集）</label>
        <textarea name="params_schema" class="code-area" rows="6" spellcheck="false"><?php echo DCAI_Util::e($m['params_schema'] ?? '{"type":"object","required":["start_date","end_date"],"properties":{"start_date":{"type":"string","minLength":8},"end_date":{"type":"string","minLength":8}}}'); ?></textarea>
        <div class="help-text">支持 required / properties.{type,min,max,minLength,maxLength,enum,pattern}</div>
    </div>
</div>
<script>
document.querySelectorAll('.module-type').forEach(function (sel) {
    var form = sel.closest('form');
    function apply() {
        var v = sel.value;
        form.querySelectorAll('.mod-type-1').forEach(function (e) { e.style.display = v === '1' ? '' : 'none'; });
        form.querySelectorAll('.mod-type-2').forEach(function (e) { e.style.display = v === '2' ? '' : 'none'; });
        form.querySelectorAll('.mod-type-3').forEach(function (e) { e.style.display = v === '3' ? '' : 'none'; });
    }
    sel.addEventListener('change', apply);
    apply();
});
</script>

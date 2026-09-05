<?php
$pageTitle = '日志管理';
$activeMenu = 'log';
require __DIR__ . '/includes/init.php';
DCAI_Admin::guard('log');

$db = dcai_db();
$type = $_GET['type'] ?? 'verify';
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$offset = ($page - 1) * $per;

$tabs = ['verify' => '授权验证', 'operation' => '后台操作', 'module' => '模块调用', 'skill' => '技能调用', 'sdk' => 'SDK 告警'];

if ($type === 'verify') {
    $where = []; $params = [];
    if (!empty($_GET['result']) && $_GET['result'] !== '') { $where[] = 'result = ?'; $params[] = (int)$_GET['result']; }
    if (!empty($_GET['kw'])) { $where[] = '(domain LIKE ? OR license_key_masked LIKE ? OR ip LIKE ?)'; $l = '%' . $_GET['kw'] . '%'; $params[] = $l; $params[] = $l; $params[] = $l; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)$db->queryValue("SELECT COUNT(*) FROM verify_logs $whereSql", $params);
    $rows = $db->query("SELECT * FROM verify_logs $whereSql ORDER BY id DESC LIMIT $per OFFSET $offset", $params);
} elseif ($type === 'operation') {
    $where = []; $params = [];
    if (!empty($_GET['kw'])) { $where[] = '(action LIKE ? OR detail LIKE ?)'; $l = '%' . $_GET['kw'] . '%'; $params[] = $l; $params[] = $l; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)$db->queryValue("SELECT COUNT(*) FROM operation_logs $whereSql", $params);
    $rows = $db->query("SELECT o.*, a.username FROM operation_logs o LEFT JOIN admin_users a ON a.id = o.admin_id $whereSql ORDER BY o.id DESC LIMIT $per OFFSET $offset", $params);
} elseif ($type === 'module') {
    $where = []; $params = [];
    if (!empty($_GET['module_id'])) { $where[] = 'l.module_id = ?'; $params[] = (int)$_GET['module_id']; }
    if (!empty($_GET['status']) && $_GET['status'] !== '') { $where[] = 'l.status = ?'; $params[] = (int)$_GET['status']; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)$db->queryValue("SELECT COUNT(*) FROM module_invoke_logs l $whereSql", $params);
    $rows = $db->query("SELECT l.*, m.module_code, m.name AS module_name, i.domain FROM module_invoke_logs l LEFT JOIN remote_modules m ON m.id = l.module_id LEFT JOIN instances i ON i.id = l.instance_id $whereSql ORDER BY l.id DESC LIMIT $per OFFSET $offset", $params);
} elseif ($type === 'skill') {
    $where = []; $params = [];
    if (!empty($_GET['skill_id'])) { $where[] = 'l.skill_id = ?'; $params[] = (int)$_GET['skill_id']; }
    if (!empty($_GET['status']) && $_GET['status'] !== '') { $where[] = 'l.status = ?'; $params[] = (int)$_GET['status']; }
    if (!empty($_GET['kw'])) { $where[] = '(s.skill_code LIKE ? OR f.function_code LIKE ?)'; $l = '%' . $_GET['kw'] . '%'; $params[] = $l; $params[] = $l; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)$db->queryValue("SELECT COUNT(*) FROM skill_invoke_logs l LEFT JOIN skills s ON s.id = l.skill_id LEFT JOIN skill_functions f ON f.id = l.function_id $whereSql", $params);
    $rows = $db->query("SELECT l.*, s.skill_code, s.name AS skill_name, f.function_code, i.domain FROM skill_invoke_logs l LEFT JOIN skills s ON s.id = l.skill_id LEFT JOIN skill_functions f ON f.id = l.function_id LEFT JOIN instances i ON i.id = l.instance_id $whereSql ORDER BY l.id DESC LIMIT $per OFFSET $offset", $params);
} else {
    $logFile = (string)dcai_config('log.file', '');
    $lines = [];
    if ($logFile && is_file($logFile)) {
        $all = file($logFile);
        foreach (array_reverse($all) as $line) {
            if (preg_match('/\[(WARNING|ERROR)\]|告警|error|fail/i', $line)) {
                $lines[] = rtrim($line);
            }
            if (count($lines) >= 200) break;
        }
    }
    $total = count($lines);
    $rows = array_slice($lines, $offset, $per);
    $kw = $_GET['kw'] ?? '';
    if ($kw !== '') {
        $filtered = array_values(array_filter($lines, function ($l) use ($kw) { return stripos($l, $kw) !== false; }));
        $total = count($filtered);
        $rows = array_slice($filtered, $offset, $per);
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="tabs">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="<?php echo DCAI_Admin::adminUrl('logs.php'); ?>?type=<?php echo $k; ?>" class="<?php echo $type === $k ? 'active' : ''; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>
</div>

<div class="toolbar">
    <form class="filters" method="get" style="display:flex;gap:10px;">
        <input type="hidden" name="type" value="<?php echo DCAI_Util::e($type); ?>">
        <?php if ($type === 'verify'): ?>
            <select name="result"><option value="">全部结果</option><option value="1" <?php echo ($_GET['result'] ?? '') === '1' ? 'selected' : ''; ?>>通过</option><option value="0" <?php echo ($_GET['result'] ?? '') === '0' ? 'selected' : ''; ?>>失败</option></select>
        <?php endif; ?>
        <?php if ($type === 'module'): ?>
            <select name="status"><option value="">全部状态</option><option value="1" <?php echo ($_GET['status'] ?? '') === '1' ? 'selected' : ''; ?>>成功</option><option value="0" <?php echo ($_GET['status'] ?? '') === '0' ? 'selected' : ''; ?>>失败</option></select>
        <?php endif; ?>
        <?php if ($type === 'skill'): ?>
            <select name="status"><option value="">全部状态</option><option value="1" <?php echo ($_GET['status'] ?? '') === '1' ? 'selected' : ''; ?>>成功</option><option value="0" <?php echo ($_GET['status'] ?? '') === '0' ? 'selected' : ''; ?>>失败</option></select>
        <?php endif; ?>
        <input type="text" name="kw" placeholder="搜索关键词" value="<?php echo DCAI_Util::e($_GET['kw'] ?? ''); ?>">
        <button class="btn btn-outline">查询</button>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <?php if ($type === 'verify'): ?>
            <table class="data">
                <thead><tr><th>ID</th><th>时间</th><th>产品</th><th>授权码</th><th>域名</th><th>IP</th><th>结果</th><th>原因</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td class="muted"><?php echo DCAI_Util::e($r['created_at']); ?></td>
                        <td><?php echo (int)$r['product_id']; ?></td>
                        <td class="mono"><?php echo DCAI_Util::e($r['license_key_masked']); ?></td>
                        <td><?php echo DCAI_Util::e($r['domain']); ?></td>
                        <td class="mono"><?php echo DCAI_Util::e($r['ip']); ?></td>
                        <td><?php echo (int)$r['result'] === 1 ? '<span class="badge green">通过</span>' : '<span class="badge red">失败</span>'; ?></td>
                        <td class="muted"><?php echo DCAI_Util::e($r['reason']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($type === 'operation'): ?>
            <table class="data">
                <thead><tr><th>ID</th><th>时间</th><th>操作人</th><th>动作</th><th>详情</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td class="muted"><?php echo DCAI_Util::e($r['created_at']); ?></td>
                        <td><?php echo DCAI_Util::e($r['username'] ?: $r['admin_id']); ?></td>
                        <td><?php echo DCAI_Util::e($r['action']); ?></td>
                        <td class="muted" style="max-width:400px;"><?php echo DCAI_Util::e(mb_substr($r['detail'] ?? '', 0, 120)); ?></td>
                        <td class="mono"><?php echo DCAI_Util::e($r['ip']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($type === 'module'): ?>
            <table class="data">
                <thead><tr><th>ID</th><th>时间</th><th>模块</th><th>实例</th><th>参数</th><th>耗时</th><th>状态</th><th>错误</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td class="muted"><?php echo DCAI_Util::e($r['created_at']); ?></td>
                        <td class="mono"><?php echo DCAI_Util::e($r['module_code'] ?: '#' . $r['module_id']); ?></td>
                        <td class="muted"><?php echo DCAI_Util::e($r['domain'] ?: '#' . (int)$r['instance_id']); ?></td>
                        <td class="mono" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e(mb_substr($r['params'] ?? '', 0, 80)); ?></td>
                        <td><?php echo (int)$r['cost_ms']; ?>ms</td>
                        <td><?php echo (int)$r['status'] === 1 ? '<span class="badge green">成功</span>' : '<span class="badge red">失败</span>'; ?></td>
                        <td class="muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e($r['error']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($type === 'skill'): ?>
            <table class="data">
                <thead><tr><th>ID</th><th>时间</th><th>技能</th><th>函数</th><th>实例</th><th>调用方授权码</th><th>耗时</th><th>状态</th><th>错误</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td class="muted"><?php echo DCAI_Util::e($r['created_at']); ?></td>
                        <td class="mono"><?php echo DCAI_Util::e($r['skill_code'] ?: '#' . (int)$r['skill_id']); ?><div class="muted" style="font-size:12px;"><?php echo DCAI_Util::e($r['skill_name'] ?? ''); ?></div></td>
                        <td class="mono"><?php echo DCAI_Util::e($r['function_code'] ?: '#' . (int)$r['function_id']); ?></td>
                        <td class="muted"><?php echo DCAI_Util::e($r['domain'] ?: '#' . (int)$r['instance_id']); ?></td>
                        <td class="mono"><?php echo $r['license_id'] ? '#' . (int)$r['license_id'] : '-'; ?></td>
                        <td><?php echo (int)$r['cost_ms']; ?>ms</td>
                        <td><?php echo (int)$r['status'] === 1 ? '<span class="badge green">成功</span>' : '<span class="badge red">失败</span>'; ?></td>
                        <td class="muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;"><?php echo DCAI_Util::e($r['error']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table class="data">
                <thead><tr><th>日志行</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $line): ?>
                    <tr><td class="mono" style="white-space:pre-wrap;font-size:12px;"><?php echo DCAI_Util::e($line); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!$rows): ?><div class="empty"><div class="ico">📭</div>暂无日志</div><?php endif; ?>
    </div>
    <?php echo DCAI_Util::paginationHtml($total, $page, $per); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

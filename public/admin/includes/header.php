<?php
/** 后台公共头部（包含侧边栏） */
// 菜单按主题归组：组 => [perm, 名称, 图标, URL]
$menuGroups = [
    '概览' => [
        ['dashboard', '仪表盘', '📊', DCAI_Admin::adminUrl('dashboard.php')],
    ],
    '产品与授权' => [
        ['product', '产品管理', '📦', DCAI_Admin::adminUrl('products.php')],
        ['package', '安装包', '🎁', DCAI_Admin::adminUrl('packages.php')],
        ['license', '授权码', '🔑', DCAI_Admin::adminUrl('licenses.php')],
        ['machine', '机器绑定', '🖥️', DCAI_Admin::adminUrl('machines.php')],
        ['offline', '离线激活', '📴', DCAI_Admin::adminUrl('offline_activate.php')],
    ],
    '实例运维' => [
        ['instance', '实例管理', '📡', DCAI_Admin::adminUrl('instances.php')],
        ['command', '命令中心', '⚡', DCAI_Admin::adminUrl('commands.php')],
        ['popup', '弹窗管理', '💬', DCAI_Admin::adminUrl('popups.php')],
    ],
    '分发升级' => [
        ['update', '更新管理', '🔄', DCAI_Admin::adminUrl('updates.php')],
        ['sysupdate', '系统升级', '🆙', DCAI_Admin::adminUrl('system_update.php')],
        ['module', '远程模块', '🧩', DCAI_Admin::adminUrl('modules.php')],
        ['skill', '技能管理', '🧬', DCAI_Admin::adminUrl('skills.php')],
    ],
    '商城运营' => [
        ['order', '订单管理', '🛒', DCAI_Admin::adminUrl('orders.php')],
    ],
    '系统' => [
        ['log', '日志管理', '📜', DCAI_Admin::adminUrl('logs.php')],
        ['setting', '系统设置', '⚙️', DCAI_Admin::adminUrl('settings.php')],
        ['admin', '管理员', '👤', DCAI_Admin::adminUrl('admins.php')],
    ],
];
// 计算当前菜单归属的组，用于展开
$activeGroup = '';
foreach ($menuGroups as $group => $items) {
    foreach ($items as [$perm, $label, $icon, $url]) {
        if ($perm === $activeMenu) { $activeGroup = $group; break 2; }
    }
}
// 权限过滤：组内可见项
$visibleGroups = [];
foreach ($menuGroups as $group => $items) {
    $filtered = array_filter($items, function ($item) {
        [$perm] = $item;
        return DCAI_Admin::isSuper() || $perm === 'dashboard' || DCAI_Admin::hasPerm($perm);
    });
    if ($filtered) {
        $visibleGroups[$group] = array_values($filtered);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo DCAI_Util::e($pageTitle); ?> - <?php echo DCAI_Util::e(dcai_config('app.name', 'DCAI 授权系统')); ?></title>
<link rel="stylesheet" href="../assets/css/admin.css?v=20260905">
</head>
<body>
<div class="layout">
    <div class="sidebar-mask" id="sidebarMask"></div>
    <aside class="sidebar" id="appSidebar">
        <div class="brand">
            <span class="logo">🔐</span>
            <span><?php echo DCAI_Util::e(dcai_config('app.name', 'DCAI 授权系统')); ?></span>
        </div>
        <nav>
            <?php foreach ($visibleGroups as $group => $items):
                $groupOpen = ($group === $activeGroup); ?>
                <div class="nav-group">
                    <button type="button" class="nav-group-head<?php echo $groupOpen ? ' open' : ''; ?>" data-nav-toggle>
                        <span class="ico"><?php echo $group === '概览' ? '🏠' : '▸'; ?></span>
                        <span class="nav-group-title"><?php echo DCAI_Util::e($group); ?></span>
                        <span class="nav-arrow"><?php echo $groupOpen ? '−' : '+'; ?></span>
                    </button>
                    <div class="nav-group-body<?php echo $groupOpen ? '' : ' hidden'; ?>">
                        <?php foreach ($items as [$perm, $label, $icon, $url]): ?>
                        <a href="<?php echo $url; ?>" class="<?php echo $activeMenu === $perm ? 'active' : ''; ?>">
                            <span class="ico"><?php echo $icon; ?></span><?php echo $label; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">DCAI 授权系统 V<?php echo DCAI_SYSTEM_VERSION; ?></div>
    </aside>
    <main class="main">
        <div class="topbar">
            <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="切换菜单">☰</button>
            <h1><?php echo DCAI_Util::e($pageTitle); ?></h1>
            <div class="user">
                <span><?php echo DCAI_Util::e($adminUser['nickname'] ?: $adminUser['username']); ?></span>
                <span class="avatar"><?php echo DCAI_Util::e(mb_substr($adminUser['nickname'] ?: $adminUser['username'], 0, 1)); ?></span>
                <a href="<?php echo DCAI_Admin::adminUrl('logout.php'); ?>" class="btn btn-outline btn-sm">退出</a>
            </div>
        </div>
        <?php if (!empty($flash)): ?>
            <div class="alert alert-<?php echo DCAI_Util::e($flash['type']); ?>"><?php echo DCAI_Util::e($flash['msg']); ?></div>
        <?php endif; ?>

<?php
/**
 * 后台页面公共初始化
 * 用法: require __DIR__ . '/includes/init.php';
 */
require_once dirname(__DIR__, 3) . '/core/Bootstrap.php';
DCAI_Admin::startSession();

// 未登录跳转登录页
if (DCAI_Admin::id() === null) {
    header('Location: ' . DCAI_Admin::adminUrl('login.php'));
    exit;
}

$adminUser = DCAI_Admin::user();
$pageTitle = $pageTitle ?? '控制台';
$activeMenu = $activeMenu ?? '';
$flash = $_SESSION['dcai_flash'] ?? null;
unset($_SESSION['dcai_flash']);

function dcai_flash(string $type, string $msg): void
{
    $_SESSION['dcai_flash'] = ['type' => $type, 'msg' => $msg];
}

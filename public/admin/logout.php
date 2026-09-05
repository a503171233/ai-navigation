<?php
require_once dirname(__DIR__, 2) . '/core/Bootstrap.php';
DCAI_Admin::startSession();
if (DCAI_Admin::id() !== null) {
    DCAI_Admin::opLog('退出登录');
}
DCAI_Admin::logout();
header('Location: ' . DCAI_Admin::adminUrl('login.php'));
exit;

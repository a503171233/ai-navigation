<?php
/**
 * 买家退出登录
 */
require __DIR__ . '/_init.php';

unset($_SESSION['dcai_buyer_id']);
session_regenerate_id(true);
shop_flash('success', '已退出登录');
header('Location: ' . shop_url('home'));
exit;
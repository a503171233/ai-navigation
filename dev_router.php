<?php
/**
 * 开发服务器路由器（php -S 127.0.0.1:8080 dev_router.php）
 * 静态文件与后台 PHP 页面直接从 public/ 目录伺服，其余交给前端控制器
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($uri !== '/' && !preg_match('#^/api/#', $uri)) {
    $file = __DIR__ . '/public' . $uri;
    if (is_file($file)) {
        require $file;
        return true;
    }
}
require __DIR__ . '/public/index.php';

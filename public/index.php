<?php
/**
 * 前端控制器（Web 根目录唯一入口）
 * 路由:
 *   /api/v1/*        -> 对外 API
 *   /api/healthz     -> 健康检查
 *   /admin/*.php     -> 后台管理页（静态存在，直接访问）
 *   /shop/*          -> 商城门户（前台）
 *   /                -> 商城首页（若开启商城）或后台
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim((string)$uri, '/') ?: '/';

if (strpos($uri, '/api/v1/') === 0 || strpos($uri, '/api/') === 0) {
    // 健康检查端点：无需鉴权
    if ($uri === '/api/healthz' || $uri === '/api/health' || strpos($uri, '/api/healthz/') === 0) {
        require dirname(__DIR__) . '/api/healthz.php';
        exit;
    }
    require dirname(__DIR__) . '/api/index.php';
    exit;
}

// 商城门户：/shop/* 映射到 public/shop/{page}.php
if (strpos($uri, '/shop') === 0) {
    $page = trim(str_replace('/shop', '', $uri), '/');
    $pageFile = dirname(__DIR__) . '/public/shop/' . ($page === '' ? 'home.php' : $page . '.php');
    if (is_file($pageFile)) {
        require $pageFile;
    } else {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Not Found';
    }
    exit;
}

// 根路径：统一进入前台商城首页（无论是否开启商城、无论登录态）。
// 历史逻辑：store.enabled=0 时跳后台登录页，导致"访问域名被强行拉去后台登录"。
// 修复：根路径恒定跳 /shop/home（首页未上架产品时显示空态提示），后台入口改为显式访问 /admin/。
if ($uri === '/' || $uri === '/index.php') {
    header('Location: /shop/home');
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 Not Found';
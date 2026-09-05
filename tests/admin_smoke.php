<?php
/**
 * 后台页面冒烟测试（使用 curl.exe，兼容本机 PHP curl cookie jar 异常）
 * 用法: php tests/admin_smoke.php
 */
$BASE = 'http://127.0.0.1:8080';
$jar = sys_get_temp_dir() . '/dcai_smoke_jar.txt';
@unlink($jar);

$pass = 0; $fail = 0;
function ok(string $name, bool $res, string $detail = ''): void
{
    global $pass, $fail;
    $res ? $pass++ : $fail++;
    echo ($res ? "  ✓ " : "  ✗ ") . $name . ($res ? '' : "  $detail") . "\n";
}

function curlGet(string $url, string $jar): array
{
    exec('curl.exe -s -c "' . $jar . '" -b "' . $jar . '" -o NUL -w "%{http_code}" "' . $url . '" 2>NUL', $out);
    return [(int)($out[0] ?? 0)];
}

function curlPost(string $url, array $fields, string $jar): array
{
    $data = http_build_query($fields);
    exec('curl.exe -s -c "' . $jar . '" -b "' . $jar . '" -d "' . $data . '" -o NUL -w "%{http_code}" "' . $url . '" 2>NUL', $out);
    return [(int)($out[0] ?? 0)];
}

// 1. 登录页
[$code] = curlGet("$BASE/admin/login.php", $jar);
ok('登录页 HTTP 200', $code === 200, "code=$code");

// 2. 获取 CSRF（带 Cookie 重新请求）
exec('curl.exe -s -c "' . $jar . '" -b "' . $jar . '" "' . $BASE . '/admin/login.php" 2>NUL', $htmlOut);
$html = implode("\n", $htmlOut);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
$csrf = $m[1] ?? '';
ok('提取 CSRF Token', $csrf !== '');

// 3. 未登录访问后台重定向
exec('curl.exe -s -c "' . $jar . '" -b "' . $jar . '" -o NUL -w "%{redirect_url}" "' . $BASE . '/admin/dashboard.php" 2>NUL', $redir);
ok('未登录访问跳转登录页', ($redir[0] ?? '') === "$BASE/admin/login.php", implode('', $redir));

// 4. 登录
[$code] = curlPost("$BASE/admin/login.php", ['username' => 'admin', 'password' => 'admin123', 'csrf_token' => $csrf], $jar);
ok('登录 POST 返回 302', $code === 302, "code=$code");

// 5. 各后台页面
$pages = [
    'dashboard.php', 'products.php', 'packages.php', 'licenses.php',
    'instances.php', 'commands.php', 'popups.php', 'updates.php',
    'modules.php', 'logs.php', 'settings.php', 'admins.php',
];
foreach ($pages as $page) {
    [$code] = curlGet("$BASE/admin/$page", $jar);
    ok("后台页 $page 返回 200", $code === 200, "code=$code");
}

// 6. 仪表盘内容
exec('curl.exe -s -c "' . $jar . '" -b "' . $jar . '" "' . $BASE . '/admin/dashboard.php" 2>NUL', $dash);
$dashHtml = implode("\n", $dash);
ok('仪表盘渲染统计卡片', strpos($dashHtml, '产品数') !== false && strpos($dashHtml, '在线实例') !== false);

// 7. 根路径重定向
exec('curl.exe -s -o NUL -w "%{http_code}" "' . $BASE . '/" 2>NUL', $root);
ok('根路径重定向', (int)($root[0] ?? 0) === 302, "code=" . ($root[0] ?? ''));

@unlink($jar);
echo "\n----------------------------------------\n";
echo "后台冒烟测试通过: $pass  |  失败: $fail\n";
exit($fail > 0 ? 1 : 0);

<?php
/**
 * DCAI 授权系统 部署包打包脚本 (Windows PowerShell 调用)
 * 用法: php tests/build_release.php
 * 输出: release/dcai_auth_vX.Y.Z_bt.zip
 * 剔除: config.php(本地密钥), storage 内容, tests, release, 本地临时文件
 */
$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
$version = (string)($config['app']['version'] ?? '1.3.1');
$outDir = $root . '/release';
if (!is_dir($outDir)) { @mkdir($outDir, 0755, true); }
$outFile = $outDir . '/dcai_auth_v' . $version . '_bt.zip';

// 剔除规则（相对路径前缀）
$excludeDirs = ['/tests', '/release', '/storage/backups', '/storage/logs', '/storage/cache', '/storage/packages', '/storage/updates', '/storage/system_updates', '/sdk/cache', '/docs', '/.workbuddy', '/.ai-memory', '/.git'];
$excludeFiles = ['/config/config.php', '/config/config.php.bak', '/.user.ini', '/dev_router.php', '/tests.zip', '/404.html', '/index.html', '/sdk/dcai_config.php', '/public/shop/index.php'];

$zip = new ZipArchive();
if ($zip->open($outFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "无法创建 $outFile\n");
    exit(1);
}

$count = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $p = str_replace('\\', '/', $f->getPathname());
    $rel = substr($p, strlen($root));
    // 剔除规则
    foreach ($excludeDirs as $d) { if (strpos($rel, $d) === 0) continue 2; }
    foreach ($excludeFiles as $ef) { if ($rel === $ef) continue 2; }
    // 临时文件
    if (strpos(basename($rel), 'tmp_') === 0) continue;
    // 隐藏/垃圾文件（点开头、macOS 残留、工具临时文件）不应进包
    $bn = basename($rel);
    if ($bn !== '' && $bn[0] === '.') continue;
    if (preg_match('#__MACOSX|\.DS_Store|\.hermes-tmp|\.tmp$|__pycache__|\.pyc$#i', $rel)) continue;
    $zip->addFile($p, 'dcai_auth' . $rel);
    $count++;
}

// 加入 storage 目录骨架（空目录占位）
foreach (['storage/backups', 'storage/cache/nonces', 'storage/cache/ratelimit', 'storage/cache/sandbox', 'storage/cache/sessions', 'storage/logs', 'storage/packages', 'storage/updates', 'storage/system_updates'] as $d) {
    $zip->addEmptyDir('dcai_auth/' . $d);
}

$zip->close();
echo "打包完成: $outFile\n";
echo "文件数: $count\n";
echo "大小: " . round(filesize($outFile) / 1024, 1) . " KB\n";
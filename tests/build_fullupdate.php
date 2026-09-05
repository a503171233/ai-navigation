<?php
/**
 * DCAI 授权系统 全量升级包打包脚本（在线更新/上传升级用）
 * 用法: php tests/build_fullupdate.php
 * 输出: release/dcai_sysupdate_v1.1.1_full.zip
 * 特点: 包含当前完整代码 + system.json，服务器从任意旧版（>=min_version）应用后即为最新完整版
 * 剔除: config.php(本地密钥), storage 内容, tests, release, 本地临时文件
 */
$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
$version = (string)($config['app']['version'] ?? '1.3.1');
$minVersion = '1.0.0';
$outFile = $root . '/release/dcai_sysupdate_v' . $version . '_full.zip';

// 剔除规则（相对路径前缀）
$excludeDirs = ['/tests', '/release', '/storage/backups', '/storage/logs', '/storage/cache', '/storage/packages', '/storage/updates', '/storage/system_updates', '/sdk/cache', '/docs', '/.workbuddy', '/.ai-memory', '/.git'];
$excludeFiles = ['/config/config.php', '/config/config.php.bak', '/.user.ini', '/dev_router.php', '/tests.zip', '/404.html', '/index.html', '/sdk/dcai_config.php', '/public/shop/index.php'];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $p = str_replace('\\', '/', $f->getPathname());
    $rel = substr($p, strlen($root));
    foreach ($excludeDirs as $d) { if (strpos($rel, $d) === 0) continue 2; }
    foreach ($excludeFiles as $ef) { if ($rel === $ef) continue 2; }
    if (strpos(basename($rel), 'tmp_') === 0) continue;
    if (preg_match('#__pycache__|\.pyc$|__MACOSX|\.DS_Store|\.hermes-tmp|(^|/)\.#i', $rel)) continue;
    // V1.3 关键：Python SDK 不直接入包（旧版系统升级白名单不含 py 会拒绝上传），
    // 由 install/migrate_v1.3.php 内嵌 base64 在升级落盘时自动生成
    if (preg_match('#sdk/dcai_client\.py$|sdk/dcai_config\.sample\.py$#i', $rel)) continue;
    $files[] = ltrim($rel, '/');
}
sort($files);

$zip = new ZipArchive();
if ($zip->open($outFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "无法创建 $outFile\n");
    exit(1);
}
foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, $rel);
}
$changelog = "DCAI 授权系统 V{$version} 全量升级包\n"
    . "- 全站 UI 视觉统一（Apple 流体动效 + 统一主题色 + prefers-reduced-motion 降级）\n"
    . "- 统一背景图（4 张 SVG 软渐变，移动端自动简化）\n"
    . "- 导航重构（后台 6 主题分组 + 二级折叠目录 + 移动端 off-canvas 侧栏；商城分组导航 + 抽屉）\n"
    . "- 更新包发布后自动向低版本在线实例下发 update 命令\n"
    . "- 从任意旧版本升级为完整 V{$version}（含机器码绑定、试用、离线激活、Python SDK、沙箱降级）";
$systemJson = json_encode([
    'version'      => $version,
    'min_version'  => $minVersion,
    'changelog'    => $changelog,
    'files'        => $files,
    'delete_files' => [],
    'migrate'      => 'install/migrate_v1.3.php',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$zip->addFromString('system.json', $systemJson);
$zip->close();

echo "全量升级包: $outFile\n";
echo "文件数: " . count($files) . "\n";
echo "大小: " . round(filesize($outFile) / 1024, 1) . " KB\n";
echo "MD5: " . md5_file($outFile) . "\n";
echo "version=$version min_version=$minVersion\n";

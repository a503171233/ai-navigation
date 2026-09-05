<?php
/**
 * 生成 上一版 → 当前版 系统增量升级包（在线更新用）
 * 用法: php tests/build_sysupdate.php
 * 基线: release/dcai_auth_v1.1.0_bt.zip（上一版完整包）
 * 输出: release/dcai_sysupdate_{当前版本}.zip（含 system.json，zip 内路径为相对项目根，无目录前缀）
 */
$root = dirname(__DIR__);
$baseZip = $root . '/release/dcai_auth_v1.3.0_bt.zip';
$config = require $root . '/config/config.php';
$version = (string)($config['app']['version'] ?? '1.3.1');
$outFile = $root . '/release/dcai_sysupdate_v' . $version . '.zip';
$minVersion = '1.3.0';

if (!is_file($baseZip)) {
    fwrite(STDERR, "基线包不存在: $baseZip\n");
    exit(1);
}

// ---------- 1. 读取基线包快照: relPath => md5 ----------
$base = new ZipArchive();
if ($base->open($baseZip) !== true) {
    fwrite(STDERR, "无法打开基线包\n");
    exit(1);
}
$baseMap = [];
for ($i = 0; $i < $base->numFiles; $i++) {
    $name = $base->getNameIndex($i);
    if (substr($name, -1) === '/') continue; // 目录
    $rel = preg_replace('#^dcai_auth/#', '', $name);
    $content = $base->getFromIndex($i);
    if ($content !== false) {
        $baseMap[$rel] = md5($content);
    }
}
$base->close();
echo "基线文件数: " . count($baseMap) . "\n";

// ---------- 2. 遍历工作区，找新增/修改 ----------
$excludeDirs = ['/tests', '/release', '/storage/backups', '/storage/logs', '/storage/cache', '/storage/packages', '/storage/updates', '/storage/system_updates', '/sdk/cache', '/docs', '/modules', '/.workbuddy', '/.ai-memory', '/.git'];
$excludeFiles = ['/config/config.php', '/config/config.php.bak', '/.user.ini', '/dev_router.php', '/tests.zip', '/404.html', '/index.html', '/sdk/dcai_config.php', '/public/shop/index.php'];
// 额外排除：所有文档、IDE 文件、临时文件
$excludeNamePatterns = [
    '#\.md$#i',
    '#\.git#',
    '#\.zip$#i',
    '#~$#',
    '#\.log$#i',
    '#e2e_www/#',
    '#tmp_#',
    // 隐藏/垃圾临时文件，避免混入升级包（否则解压白名单会拒绝而失败）
    '#\.hermes-tmp#i',
    '#__MACOSX#',
    '#\.DS_Store#',
    '#__pycache__#',
    '#\.pyc$#i',
    '#(^|/)\.#', // 任何以点开头的隐藏文件/目录
    // V1.3 关键：Python SDK 不直接入包（旧版系统升级白名单不含 py 会拒绝上传），
    // 由 install/migrate_v1.3.php 内嵌 base64 在升级落盘时自动生成 sdk/dcai_client.py
    '#sdk/dcai_client\.py$#i',
    '#sdk/dcai_config\.sample\.py$#i',
];
// 文档一律不参与线上差异（服务器上旧文档名可能不同，避免被误判为"已删除"而进 delete_files）
$excludeDocs = ['/授权系统开发说明书.md', '/开发说明书.md'];

$files = [];       // 升级包 files 白名单（新增+修改）
$deletes = [];     // 删除清单（基线有、工作区已删）
$addCount = 0;
$modCount = 0;
$sameCount = 0;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$workFiles = [];
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $p = str_replace('\\', '/', $f->getPathname());
    $rel = substr($p, strlen($root));
    foreach ($excludeDirs as $d) { if (strpos($rel, $d) === 0) continue 2; }
    foreach ($excludeFiles as $ef) { if ($rel === $ef) continue 2; }
    foreach ($excludeNamePatterns as $pat) { if (preg_match($pat, $rel)) continue 2; }
    if (strpos(basename($rel), 'tmp_') === 0) continue;
    $workFiles[$rel] = $p;
}
// 删除清单：基线有而工作区无（文档类不列入删除，避免误删线上文件）
foreach (array_keys($baseMap) as $rel) {
    $full = '/' . $rel;
    if (in_array($full, $excludeDocs, true)) continue;
    if (!isset($workFiles[$full]) && !is_file($root . '/' . $rel)) {
        $deletes[] = $rel;
    }
}
// 差异
foreach ($workFiles as $rel => $p) {
    $md5 = md5_file($p);
    $key = ltrim($rel, '/');
    if (!isset($baseMap[$key])) {
        $files[] = $key;
        $addCount++;
    } elseif ($baseMap[$key] !== $md5) {
        $files[] = $key;
        $modCount++;
    } else {
        $sameCount++;
    }
}
sort($files);
sort($deletes);
echo "新增: $addCount, 修改: $modCount, 相同(跳过): $sameCount, 删除: " . count($deletes) . "\n";

// ---------- 3. 打包 ----------
$changelog = "DCAI 授权系统 V{$version} 更新\n"
    . "- 全站 UI 视觉统一：后台/商城主题色统一、Apple 流体动效（弹簧曲线、按钮反馈、弹窗入场）、prefers-reduced-motion 无障碍降级\n"
    . "- 新增统一背景图：4 张 SVG 软渐变背景（后台/商城 × 桌面/移动端），移动端自动切换简化背景，不干扰文字可读性\n"
    . "- 导航重构：后台 17 项平铺 → 6 主题分组二级折叠目录；商城分组导航；二级目录默认折叠、点击展开/收起、当前页所在组自动展开；移动端 off-canvas 侧栏 + 抽屉\n"
    . "- 修复 导航折叠状态服务端/客户端不同步（HTML hidden 属性 vs class）、折叠链接可被 Tab 聚焦无障碍缺陷、商城脚本 head 同步执行致事件未绑定\n"
    . "- 更新包发布后自动向低版本在线实例下发 update 命令（无需实例手动检测）";

$zip = new ZipArchive();
if ($zip->open($outFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "无法创建 $outFile\n");
    exit(1);
}
foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, $rel);
}
$systemJson = json_encode([
    'version'      => $version,
    'min_version'  => $minVersion,
    'changelog'    => $changelog,
    'files'        => $files,
    'delete_files' => $deletes,
    'migrate'      => '',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$zip->addFromString('system.json', $systemJson);
$zip->close();

echo "升级包: $outFile\n";
echo "大小: " . round(filesize($outFile) / 1024, 1) . " KB\n";
echo "MD5: " . md5_file($outFile) . "\n";
echo "files 数: " . count($files) . ", delete 数: " . count($deletes) . "\n";
if (count($deletes) > 0) {
    echo "删除清单:\n  - " . implode("\n  - ", $deletes) . "\n";
}
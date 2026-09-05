<?php
/**
 * DCAI 授权系统 —— 远程更新 manifest 生成脚本（GitHub 在线更新用）
 * 用法: php tests/build_manifest.php
 * 输出:
 *   release/manifest.json        —— 指向「全量升级包」dcai_sysupdate_v{version}_full.zip（推荐，任意旧版本可升级）
 *   release/manifest_incr.json   —— 指向「增量升级包」dcai_sysupdate_v{version}.zip（min_version=上一版）
 * 说明:
 *   1. 先运行 build_sysupdate.php / build_fullupdate.php 生成升级包，再运行本脚本；
 *   2. manifest 的 url 默认走 jsDelivr 国内可访问的直链（gcore.jsdelivr.net，cdn.jsdelivr.net 在国内常被重置）。
 *      jsDelivr 规则: https://gcore.jsdelivr.net/gh/{owner}/{repo}@{branch}/{path}
 *      —— 要求仓库的 release/ 目录已推送到 GitHub，且 jsDelivr 缓存已预热（首次访问约 1~5 分钟生效）。
 *      如需走 raw.githubusercontent.com 直链或自有 CDN，改 $baseUrl 即可；
 *   3. 生成的 JSON 与 SystemUpdateService::checkRemote() 期望的字段完全一致
 *      （version/min_version/changelog/url/md5/size/release_at）。
 */
$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
$version = (string)($config['app']['version'] ?? '');

// GitHub 仓库信息（每次发布前修改）
$owner = 'OWNER';        // GitHub 用户名/组织
$repo  = 'REPO';         // 仓库名
$branch = 'main';        // 分支（默认 main）

// 下载直链前缀。默认 jsDelivr(gcore) 国内可访问；可选 raw.githubusercontent.com / gh-proxy.com 等
$baseUrl = "https://gcore.jsdelivr.net/gh/{$owner}/{$repo}@{$branch}";

if ($version === '') {
    fwrite(STDERR, "无法从 config.php 读取版本号\n");
    exit(1);
}

$incrFile = $root . '/release/dcai_sysupdate_v' . $version . '.zip';
$fullFile = $root . '/release/dcai_sysupdate_v' . $version . '_full.zip';

if (!is_file($fullFile) && !is_file($incrFile)) {
    fwrite(STDERR, "release 目录下找不到 dcai_sysupdate_v{$version}(_full).zip，请先运行打包脚本\n");
    exit(1);
}

function readMetaFromZip(string $zipPath): ?array
{
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) {
        return null;
    }
    $raw = $za->getFromName('system.json');
    $za->close();
    if ($raw === false) {
        return null;
    }
    $m = json_decode($raw, true);
    return is_array($m) ? $m : null;
}

function makeManifest(array $pkg, string $filename): array
{
    global $baseUrl;
    $meta = readMetaFromZip($pkg['file']);
    if ($meta === null) {
        fwrite(STDERR, "无法读取 {$pkg['file']} 中的 system.json\n");
        exit(1);
    }
    return [
        'version'     => (string)$meta['version'],
        'min_version' => (string)($meta['min_version'] ?? ''),
        'changelog'   => (string)($meta['changelog'] ?? ''),
        'url'         => $baseUrl . '/release/' . $filename,
        'md5'         => md5_file($pkg['file']),
        'size'        => filesize($pkg['file']),
        'release_at'  => date('Y-m-d'),
    ];
}

$outFull = null;
if (is_file($fullFile)) {
    $outFull = $root . '/release/manifest.json';
    $manifest = makeManifest(['file' => $fullFile], basename($fullFile));
    file_put_contents($outFull, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "已生成全量 manifest: release/manifest.json\n";
    echo "  version={$manifest['version']} min_version={$manifest['min_version']} size={$manifest['size']} md5={$manifest['md5']}\n";
    echo "  url={$manifest['url']}\n";
} else {
    echo "跳过全量 manifest（未找到 dcai_sysupdate_v{$version}_full.zip）\n";
}

if (is_file($incrFile)) {
    $outIncr = $root . '/release/manifest_incr.json';
    $manifestIncr = makeManifest(['file' => $incrFile], basename($incrFile));
    file_put_contents($outIncr, json_encode($manifestIncr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "已生成增量 manifest: release/manifest_incr.json\n";
    echo "  version={$manifestIncr['version']} min_version={$manifestIncr['min_version']} size={$manifestIncr['size']} md5={$manifestIncr['md5']}\n";
    echo "  url={$manifestIncr['url']}\n";
} else {
    echo "跳过增量 manifest（未找到 dcai_sysupdate_v{$version}.zip）\n";
}

echo "\n下一步：\n";
echo "1. 修改本脚本顶部 \$owner/\$repo 为实际 GitHub 仓库，如用其他 CDN 改 \$baseUrl；\n";
echo "2. 将 release/ 下的升级包与 manifest*.json 推送至仓库（保持 release/ 目录结构）；\n";
echo "3. 首次访问 jsDelivr 直链会触发缓存预热（1~5 分钟），可先 curl 验证 200 再配置后台；\n";
echo "4. 后台「系统升级」页填写 manifest URL 并启用、保存、检查。\n";
<?php
/**
 * ZIP 安全解压工具
 *  - 防目录穿越（../、绝对路径）
 *  - 可选扩展名白名单
 *  - 防止解压炸弹（文件数量 / 总大小上限）
 */
class DCAI_ZipHelper
{
    /**
     * 安全解压 zip 到目标目录
     * @param string $zipFile zip 文件路径
     * @param string $destDir 目标目录
     * @param int $maxFiles 最大文件数
     * @param int $maxBytes 解压总大小上限
     * @param array|null $allowedExt 扩展名白名单（null 表示不限制；带点小写，如 ['php','json']）
     * @return array{0:bool,1:string} [是否成功, 错误信息或文件列表]
     */
    public static function extract(string $zipFile, string $destDir, int $maxFiles = 2000, int $maxBytes = 536870912, ?array $allowedExt = null): array
    {
        if (!is_file($zipFile)) {
            return [false, 'zip 文件不存在'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return [false, '无法打开 zip 文件'];
        }

        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        $baseReal = realpath($destDir);
        if ($baseReal === false) {
            $zip->close();
            return [false, '目标目录无效'];
        }

        $count = $zip->numFiles;
        if ($count > $maxFiles) {
            $zip->close();
            return [false, "文件数量超过上限({$maxFiles})"];
        }

        $totalSize = 0;
        $written = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }
            // 规范化路径并防穿越
            $clean = str_replace('\\', '/', $entry);
            if ($clean === '' || $clean === '/') {
                continue;
            }
            if (strpos($clean, '../') !== false || strpos($clean, '/..') === 0 || preg_match('#^[a-zA-Z]:#', $clean)) {
                $zip->close();
                return [false, "安装包包含非法路径: {$entry}"];
            }
            $clean = ltrim($clean, '/');
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $totalSize += (int)($stat['size'] ?? 0);
            if ($totalSize > $maxBytes) {
                $zip->close();
                return [false, '压缩包解压后体积超过上限'];
            }

            $target = $baseReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
            if (substr($clean, -1) === '/') {
                @mkdir($target, 0755, true);
                continue;
            }

            // 先创建目标目录，再进行真实路径越界校验
            @mkdir(dirname($target), 0755, true);
            $targetRealDir = realpath(dirname($target));
            if ($targetRealDir === false || strpos($targetRealDir . DIRECTORY_SEPARATOR, $baseReal . DIRECTORY_SEPARATOR) !== 0) {
                $zip->close();
                return [false, "安装包包含非法路径: {$entry}"];
            }

            // 忽略常见垃圾条目（macOS/隐藏/编辑器临时文件），不因单个垃圾文件而中止整包
            if (self::isJunkEntry($clean)) {
                continue;
            }

            if ($allowedExt !== null) {
                $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    $zip->close();
                    return [false, "文件类型不允许: {$entry}"];
                }
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $zip->close();
                return [false, "读取压缩包内文件失败: {$entry}"];
            }
            if (@file_put_contents($target, $content) === false) {
                $zip->close();
                return [false, "写入文件失败: {$entry}"];
            }
            $written[] = $clean;
        }
        $zip->close();
        return [true, $written];
    }

    /**
     * 判断条目是否为常见的"垃圾"文件（应静默忽略而非中止解压）
     *  - macOS Finder 打包残留：__MACOSX/、.DS_Store
     *  - Windows 缩略图缓存：Thumbs.db
     *  - 隐藏文件（点开头）与编辑器/工具临时文件（.hermes-tmp、*.tmp）
     * @param string $entry 已规范化（正斜杠、去掉头 /）的条目路径
     */
    private static function isJunkEntry(string $entry): bool
    {
        if ($entry === '' || strpos($entry, '/') === 0) {
            return false;
        }
        if (strpos($entry, '__MACOSX') !== false || strpos($entry, '.DS_Store') !== false) {
            return true;
        }
        $bn = basename($entry);
        if ($bn === '' || $bn === '.') {
            return false;
        }
        if ($bn[0] === '.' || $bn === 'Thumbs.db') {
            return true;
        }
        return preg_match('/\.hermes-tmp|\.tmp$/i', $bn) === 1;
    }

    /**
     * 读取 zip 内的指定文件内容
     */
    public static function readEntry(string $zipFile, string $entry): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return null;
        }
        $content = $zip->getFromName($entry);
        $zip->close();
        return $content === false ? null : $content;
    }

    /**
     * 列出 zip 内所有文件路径
     */
    public static function listEntries(string $zipFile): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[] = $zip->getNameIndex($i);
        }
        $zip->close();
        return $out;
    }
}

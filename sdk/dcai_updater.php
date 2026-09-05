<?php
/**
 * DCAI SDK 更新执行器
 * 流程：下载 → 校验 MD5 → 解析 update.json → 备份 → 解压替换 → 执行迁移脚本 → 失败回滚
 */
class DCAI_Updater
{
    private array $config;
    private string $error = '';

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->ensureDir($config['update_dir']);
    }

    public function lastError(): string
    {
        return $this->error;
    }

    public function apply(array $update): bool
    {
        $this->error = '';
        $appRoot = rtrim((string)$this->config['app_root'], '/\\');
        $downloadUrl = (string)($update['download_url'] ?? '');
        $expectedMd5 = (string)($update['md5'] ?? '');
        $version = (string)($update['version'] ?? '');
        $updateDir = rtrim((string)$this->config['update_dir'], '/\\');
        $this->ensureDir($updateDir);

        try {
            // 1. 下载
            $zipFile = $updateDir . '/update_' . $version . '.zip';
            if (!is_file($zipFile) || ($expectedMd5 !== '' && md5_file($zipFile) !== $expectedMd5)) {
                if (!$this->download($downloadUrl, $zipFile)) {
                    $this->error = '下载更新包失败';
                    return false;
                }
            }

            // 2. MD5 校验
            if ($expectedMd5 !== '' && md5_file($zipFile) !== $expectedMd5) {
                @unlink($zipFile);
                $this->error = '更新包 MD5 校验失败';
                return false;
            }

            // 3. 解析 update.json
            $meta = $this->readUpdateMeta($zipFile);
            if ($meta === null) {
                $this->error = '更新包缺少 update.json';
                return false;
            }

            $files = $meta['files'] ?? [];
            $deleteFiles = $meta['delete_files'] ?? [];
            $migrate = $meta['migrate'] ?? '';

            // 4. 解压到暂存目录
            $staging = $updateDir . '/staging_' . $version . '_' . bin2hex(random_bytes(4));
            $this->ensureDir($staging);
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                $this->error = '无法打开更新包';
                return false;
            }
            $this->extractSafe($zip, $staging, $appRoot, $files);
            $zip->close();

            // 5. 备份并替换
            $backupDir = $updateDir . '/backup_' . $version . '_' . date('YmdHis');
            $this->ensureDir($backupDir);
            $applied = [];
            foreach ($files as $rel) {
                $src = $staging . '/' . $rel;
                $dst = $this->safePath($appRoot, $rel);
                if ($dst === null) {
                    $this->error = '更新包包含非法路径: ' . $rel;
                    $this->rollback($backupDir, $applied, []);
                    return false;
                }
                if (is_file($src)) {
                    $this->ensureDir(dirname($dst));
                    if (is_file($dst)) {
                        $this->copyFile($dst, $backupDir . '/' . $rel);
                    }
                    if (!@copy($src, $dst)) {
                        $this->error = '替换文件失败: ' . $rel;
                        $this->rollback($backupDir, $applied, []);
                        return false;
                    }
                    $applied[] = $rel;
                }
            }

            // 6. 删除旧文件
            $deleted = [];
            foreach ($deleteFiles as $rel) {
                $target = $this->safePath($appRoot, $rel);
                if ($target !== null && is_file($target)) {
                    $this->copyFile($target, $backupDir . '/' . $rel);
                    @unlink($target);
                    $deleted[] = $rel;
                }
            }

            // 7. 执行迁移脚本
            if ($migrate !== '') {
                $migrateFile = $this->safePath($appRoot, $migrate);
                if ($migrateFile !== null && is_file($migrateFile)) {
                    try {
                        require $migrateFile;
                    } catch (Throwable $e) {
                        $this->error = '迁移脚本执行失败: ' . $e->getMessage();
                        $this->rollback($backupDir, $applied, $deleted);
                        return false;
                    }
                    // 成功后自删除
                    @unlink($migrateFile);
                }
            }

            // 8. 清理暂存
            $this->rmRecursive($staging);
            // 保留最近 3 份备份
            $this->pruneBackups($updateDir, 3);

            return true;
        } catch (Throwable $e) {
            $this->error = '更新异常: ' . $e->getMessage();
            return false;
        }
    }

    private function download(string $url, string $target): bool
    {
        if ($url === '') {
            return false;
        }
        $ch = curl_init($url);
        $fp = fopen($target, 'wb');
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ];
        // TLS 证书校验：默认开启，仅显式配置 ssl_verify=false 时关闭（自签证书场景）
        if (!empty($this->config['ssl_verify'] ?? null)) {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 2;
            if (!empty($this->config['ca_bundle'] ?? null)) {
                $curlOpts[CURLOPT_CAINFO] = $this->config['ca_bundle'];
            }
        } else {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = false;
        }
        curl_setopt_array($ch, $curlOpts);
        $ok = curl_exec($ch) !== false;
        $errno = curl_errno($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $errno !== 0) {
            @unlink($target);
            return false;
        }
        return true;
    }

    private function readUpdateMeta(string $zipFile): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return null;
        }
        $raw = $zip->getFromName('update.json');
        $zip->close();
        if ($raw === null) {
            return null;
        }
        $meta = json_decode($raw, true);
        return is_array($meta) ? $meta : null;
    }

    private function extractSafe(ZipArchive $zip, string $destDir, string $appRoot, array $allowedFiles = []): void
    {
        $destReal = realpath($destDir);
        if ($destReal === false) {
            @mkdir($destDir, 0755, true);
            $destReal = realpath($destDir);
        }
        $allowed = [];
        foreach ($allowedFiles as $rel) {
            $allowed[str_replace('\\', '/', (string)$rel)] = true;
        }
        // 未声明 files 白名单时，不执行全量解压（防止恶意 zip 塞满磁盘）
        if (!$allowed) {
            throw new RuntimeException('update.json 未声明 files 白名单');
        }
        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $clean = str_replace('\\', '/', (string)$entry);
            if ($clean === '' || substr($clean, -1) === '/') {
                continue;
            }
            if (strpos($clean, '../') !== false || preg_match('#^[a-zA-Z]:#', $clean)) {
                throw new RuntimeException('更新包包含非法路径');
            }
            // 仅解压 manifest files 白名单内文件，防止解压炸弹造成磁盘耗尽
            if (!isset($allowed[$clean])) {
                continue;
            }
            $target = $destReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
            @mkdir(dirname($target), 0755, true);
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                throw new RuntimeException('读取更新包文件失败: ' . $clean);
            }
            $totalBytes += strlen($content);
            if ($totalBytes > 536870912) { // 512MB 上限
                throw new RuntimeException('更新包解压后体积超过上限');
            }
            file_put_contents($target, $content);
        }
    }

    private function safePath(string $appRoot, string $rel): ?string
    {
        $clean = str_replace('\\', '/', $rel);
        if ($clean === '' || strpos($clean, '../') !== false || preg_match('#^[a-zA-Z]:#', $clean)) {
            return null;
        }
        $full = $appRoot . '/' . ltrim($clean, '/');
        $real = realpath($appRoot);
        $parent = realpath(dirname($full));
        if ($real === false || $parent === false || strpos($parent, $real) !== 0) {
            return null;
        }
        return $full;
    }

    private function copyFile(string $src, string $dst): void
    {
        $this->ensureDir(dirname($dst));
        @copy($src, $dst);
    }

    private function rollback(string $backupDir, array $applied, array $deleted): void
    {
        // 恢复被替换的文件
        foreach ($applied as $rel) {
            $src = $backupDir . '/' . $rel;
            $dst = $this->safePath($this->config['app_root'], $rel);
            if ($dst !== null && is_file($src)) {
                @copy($src, $dst);
            }
        }
        // 恢复被删除的文件
        foreach ($deleted as $rel) {
            $src = $backupDir . '/' . $rel;
            $dst = $this->safePath($this->config['app_root'], $rel);
            if ($dst !== null && is_file($src)) {
                $this->ensureDir(dirname($dst));
                @copy($src, $dst);
            }
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private function rmRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . '/*');
        if (is_array($items)) {
            foreach ($items as $item) {
                is_dir($item) ? $this->rmRecursive($item) : @unlink($item);
            }
        }
        @rmdir($dir);
    }

    private function pruneBackups(string $updateDir, int $keep): void
    {
        $dirs = glob($updateDir . '/backup_*');
        if (!is_array($dirs) || count($dirs) <= $keep) {
            return;
        }
        sort($dirs);
        $excess = array_slice($dirs, 0, count($dirs) - $keep);
        foreach ($excess as $d) {
            $this->rmRecursive($d);
        }
    }
}

<?php
/**
 * 系统级 OTA 升级服务（授权系统自身升级）
 * 流程：上传校验 → 发布/撤回 → 应用（备份-替换-迁移-回滚）
 */
class DCAI_SystemUpdateService
{
    const STATUS_DRAFT     = 0; // 待发布
    const STATUS_PUBLISHED = 1; // 已发布
    const STATUS_APPLIED   = 2; // 已应用
    const STATUS_RECALLED  = 3; // 已撤回
    const STATUS_FAILED    = 4; // 应用失败

    /**
     * 上传系统升级包（zip 顶层含 system.json）
     * @return array{0:bool,1:mixed,2:string}
     */
    public static function upload(array $file, int $adminId = 0): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [false, null, '文件上传失败'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return [false, null, '仅允许 zip 升级包'];
        }
        if ($file['size'] > (int)dcai_config('storage.max_package_size', 209715200)) {
            return [false, null, '升级包超过大小上限'];
        }

        $jsonRaw = DCAI_ZipHelper::readEntry($file['tmp_name'], 'system.json');
        if ($jsonRaw === null) {
            return [false, null, '升级包缺少 system.json'];
        }
        $meta = json_decode($jsonRaw, true);
        if (!is_array($meta) || empty($meta['version'])) {
            return [false, null, 'system.json 格式错误'];
        }
        if (!DCAI_Version::valid($meta['version'])) {
            return [false, null, '版本号格式错误'];
        }
        if (!DCAI_Version::gt($meta['version'], DCAI_SYSTEM_VERSION)) {
            return [false, null, '新版本必须高于当前系统版本（' . DCAI_SYSTEM_VERSION . '）'];
        }
        $minVersion = (string)($meta['min_version'] ?? '');
        if ($minVersion !== '' && !DCAI_Version::valid($minVersion)) {
            return [false, null, 'min_version 格式错误'];
        }
        if ($minVersion !== '' && !DCAI_Version::gte(DCAI_SYSTEM_VERSION, $minVersion)) {
            return [false, null, '当前系统版本低于升级包要求的最低版本（' . $minVersion . '）'];
        }
        $files = $meta['files'] ?? [];
        if (!is_array($files) || !$files) {
            return [false, null, 'system.json 必须声明 files 文件白名单'];
        }
        foreach ($files as $rel) {
            if (self::safeRel((string)$rel) === null) {
                return [false, null, 'files 包含非法路径: ' . $rel];
            }
        }
        $deleteFiles = $meta['delete_files'] ?? [];
        if (!is_array($deleteFiles)) {
            return [false, null, 'delete_files 格式错误'];
        }
        foreach ($deleteFiles as $rel) {
            if (self::safeRel((string)$rel) === null) {
                return [false, null, 'delete_files 包含非法路径: ' . $rel];
            }
        }

        // 解压安全校验
        $tmpDir = (dcai_config('storage.path', DCAI_ROOT . '/storage') . '/cache/extract_sys_' . bin2hex(random_bytes(6)));
        [$ok, $res] = DCAI_ZipHelper::extract($file['tmp_name'], $tmpDir, 3000, 536870912, ['php', 'js', 'css', 'html', 'htm', 'md', 'json', 'txt', 'ini', 'sql', 'png', 'jpg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'py']);
        if (!$ok) {
            return [false, null, $res];
        }
        DCAI_Util::rmRecursive($tmpDir);

        $db = dcai_db();
        $now = dcai_now();
        $db->beginTransaction();
        try {
            $storagePath = (string)dcai_config('storage.path', DCAI_ROOT . '/storage');
            $relPath = 'system_updates/sys_' . $meta['version'] . '_' . time() . '.zip';
            $target = $storagePath . '/' . $relPath;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                if (!@copy($file['tmp_name'], $target)) {
                    $db->rollback();
                    return [false, null, '保存升级包失败'];
                }
            }
            $updateId = $db->insert('system_updates', [
                'version'      => $meta['version'],
                'package_path' => $relPath,
                'package_md5'  => md5_file($target),
                'package_size' => filesize($target),
                'min_version'  => $minVersion,
                'changelog'    => $meta['changelog'] ?? ($meta['description'] ?? ''),
                'status'       => self::STATUS_DRAFT,
                'created_by'   => $adminId ?: null,
                'created_at'   => $now,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            dcai_log('error', '系统升级包上传失败', ['err' => $e->getMessage()]);
            return [false, null, '保存升级包失败: ' . $e->getMessage()];
        }
        return [true, ['update_id' => $updateId, 'version' => $meta['version']], ''];
    }

    /**
     * 查询远程升级源 manifest，返回可用更新信息（无更新/未配置返回 null）
     * @return array|null ['version','min_version','changelog','url','md5','size','release_at'] 或 null
     */
    public static function checkRemote(): ?array
    {
        $src = dcai_config('update_source', []);
        if (empty($src['enabled']) || empty($src['manifest'])) {
            return null;
        }
        $timeout = (int)($src['timeout'] ?? 15);
        $ch = curl_init($src['manifest']);
        $headers = [];
        if (!empty($src['auth_token'])) {
            $headers[] = 'Authorization: Bearer ' . $src['auth_token'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'DCAI-SystemUpdater/' . DCAI_SYSTEM_VERSION,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || $raw === false) {
            return null;
        }
        $m = json_decode($raw, true);
        if (!is_array($m) || empty($m['version'])) {
            return null;
        }
        if (!DCAI_Version::valid((string)$m['version']) || !DCAI_Version::gt((string)$m['version'], DCAI_SYSTEM_VERSION)) {
            return null;
        }
        $minVersion = (string)($m['min_version'] ?? '');
        if ($minVersion !== '' && !DCAI_Version::gte(DCAI_SYSTEM_VERSION, $minVersion)) {
            return null;
        }
        return [
            'version'     => (string)$m['version'],
            'min_version' => $minVersion,
            'changelog'   => (string)($m['changelog'] ?? ''),
            'url'         => (string)($m['url'] ?? ''),
            'md5'         => strtolower((string)($m['md5'] ?? '')),
            'size'        => (int)($m['size'] ?? 0),
            'release_at'  => (string)($m['release_at'] ?? ''),
        ];
    }

    /**
     * 从远程下载升级包并登记为「待发布」记录
     * @return array{0:bool,1:mixed,2:string}
     */
    public static function fetchRemote(array $remote, int $adminId = 0): array
    {
        if (empty($remote['url'])) {
            return [false, null, 'manifest 缺少下载地址 url'];
        }
        $src = dcai_config('update_source', []);
        $timeout = max(30, (int)($src['timeout'] ?? 15) * 4);
        $tmpFile = tempnam(sys_get_temp_dir(), 'dcai_upd_');
        $fp = fopen($tmpFile, 'wb');
        if (!$fp) {
            return [false, null, '创建临时文件失败'];
        }
        $headers = [];
        if (!empty($src['auth_token'])) {
            $headers[] = 'Authorization: Bearer ' . $src['auth_token'];
        }
        $ch = curl_init($remote['url']);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'DCAI-SystemUpdater/' . DCAI_SYSTEM_VERSION,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($code !== 200) {
            @unlink($tmpFile);
            return [false, null, '下载失败，HTTP ' . $code];
        }
        if ($remote['md5'] !== '' && md5_file($tmpFile) !== $remote['md5']) {
            @unlink($tmpFile);
            return [false, null, '下载文件 MD5 校验失败'];
        }
        // 复用 upload 逻辑登记（构造 $_FILES 结构）
        $fakeFile = [
            'name'     => 'remote_' . $remote['version'] . '.zip',
            'type'     => 'application/zip',
            'tmp_name' => $tmpFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpFile),
        ];
        // upload() 校验版本必须高于当前版本，且会 move_uploaded_file —— 远程文件非上传文件，
        // 因此直接抄写 upload 的登记逻辑（存储到 system_updates 并置为待发布）
        $meta = self::readSystemMeta($tmpFile);
        if ($meta === null) {
            @unlink($tmpFile);
            return [false, null, '下载包缺少 system.json'];
        }
        if (!DCAI_Version::valid((string)$meta['version']) || !DCAI_Version::gt((string)$meta['version'], DCAI_SYSTEM_VERSION)) {
            @unlink($tmpFile);
            return [false, null, '升级包版本不高于当前系统版本'];
        }
        $db = dcai_db();
        $now = dcai_now();
        $storagePath = (string)dcai_config('storage.path', DCAI_ROOT . '/storage');
        $relPath = 'system_updates/sys_' . $meta['version'] . '_' . time() . '.zip';
        $target = $storagePath . '/' . $relPath;
        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }
        if (!@copy($tmpFile, $target)) {
            @unlink($tmpFile);
            return [false, null, '保存升级包失败'];
        }
        @unlink($tmpFile);
        $updateId = $db->insert('system_updates', [
            'version'      => (string)$meta['version'],
            'package_path' => $relPath,
            'package_md5'  => md5_file($target),
            'package_size' => filesize($target),
            'min_version'  => (string)($meta['min_version'] ?? ''),
            'changelog'    => (string)($meta['changelog'] ?? ''),
            'status'       => self::STATUS_PUBLISHED,
            'created_by'   => $adminId ?: null,
            'created_at'   => $now,
        ]);
        return [true, ['update_id' => $updateId, 'version' => (string)$meta['version']], ''];
    }

    /**
     * 查询最新已发布且满足最低版本的系统升级包
     */
    public static function latestForCheck(): ?array
    {
        $updates = dcai_db()->query('SELECT * FROM system_updates WHERE status = ? ORDER BY id DESC', [self::STATUS_PUBLISHED]);
        foreach ($updates as $update) {
            if (!DCAI_Version::gt($update['version'], DCAI_SYSTEM_VERSION)) {
                continue;
            }
            if ($update['min_version'] !== '' && !DCAI_Version::gte(DCAI_SYSTEM_VERSION, $update['min_version'])) {
                continue;
            }
            return $update;
        }
        return null;
    }

    /**
     * 发布/撤回升级包
     * @return array{0:bool,1:mixed,2:string}
     */
    public static function publish(int $id, int $status): array
    {
        if (!in_array($status, [self::STATUS_PUBLISHED, self::STATUS_RECALLED], true)) {
            return [false, null, '非法的状态值'];
        }
        $row = dcai_db()->queryOne('SELECT * FROM system_updates WHERE id = ?', [$id]);
        if (!$row) {
            return [false, null, '升级包不存在'];
        }
        dcai_db()->update('system_updates', ['status' => $status], 'id = ?', [$id]);
        return [true, ['id' => $id, 'status' => $status], ''];
    }

    /**
     * 应用系统升级：备份 → 替换 → 删除 → 迁移 → 更新版本，失败自动回滚
     * @return array{0:bool,1:mixed,2:string}
     */
    public static function apply(int $id, int $adminId = 0): array
    {
        $row = dcai_db()->queryOne('SELECT * FROM system_updates WHERE id = ?', [$id]);
        if (!$row) {
            return [false, null, '升级包不存在'];
        }
        if ((int)$row['status'] !== self::STATUS_PUBLISHED) {
            return [false, null, '仅已发布的升级包可应用'];
        }
        if (!DCAI_Version::gt($row['version'], DCAI_SYSTEM_VERSION)) {
            return [false, null, '升级包版本不高于当前系统版本，无需应用'];
        }
        $storagePath = (string)dcai_config('storage.path', DCAI_ROOT . '/storage');
        $pkgPath = $storagePath . '/' . $row['package_path'];
        if (!is_file($pkgPath) || md5_file($pkgPath) !== $row['package_md5']) {
            return [false, null, '升级包文件缺失或 MD5 校验失败'];
        }

        $meta = self::readSystemMeta($pkgPath);
        if ($meta === null) {
            return [false, null, '升级包缺少 system.json'];
        }
        $files = $meta['files'] ?? [];
        $deleteFiles = $meta['delete_files'] ?? [];
        $migrate = (string)($meta['migrate'] ?? '');
        if (!is_array($files) || !$files || !is_array($deleteFiles)) {
            return [false, null, 'system.json 内容非法'];
        }

        $backupRoot = $storagePath . '/backups';
        $backupDir = $backupRoot . '/system_' . $row['version'] . '_' . date('YmdHis');
        $staging = $storagePath . '/cache/staging_sys_' . bin2hex(random_bytes(6));

        $applied = [];
        $deleted = [];
        try {
            if (!@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                throw new RuntimeException('创建备份目录失败');
            }
            // 1. 备份将被替换/删除的文件
            foreach (array_merge($files, $deleteFiles) as $rel) {
                $rel = (string)$rel;
                $dst = self::safeRel($rel);
                if ($dst === null) {
                    throw new RuntimeException('升级包包含非法路径: ' . $rel);
                }
                $target = DCAI_ROOT . '/' . $dst;
                if (is_file($target)) {
                    @mkdir(dirname($backupDir . '/' . $dst), 0755, true);
                    if (!@copy($target, $backupDir . '/' . $dst)) {
                        throw new RuntimeException('备份文件失败: ' . $rel);
                    }
                }
            }

            // 2. 解压到暂存目录
            if (!@mkdir($staging, 0755, true) && !is_dir($staging)) {
                throw new RuntimeException('创建暂存目录失败');
            }
            [$ok, $res] = DCAI_ZipHelper::extract($pkgPath, $staging, 3000, 536870912, ['php', 'js', 'css', 'html', 'htm', 'md', 'json', 'txt', 'ini', 'sql', 'png', 'jpg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'py']);
            if (!$ok) {
                throw new RuntimeException('解压升级包失败: ' . $res);
            }

            // 3. 按 files 白名单替换
            foreach ($files as $rel) {
                $rel = (string)$rel;
                $dst = self::safeRel($rel);
                if ($dst === null) {
                    throw new RuntimeException('升级包包含非法路径: ' . $rel);
                }
                $src = $staging . '/' . $dst;
                if (!is_file($src)) {
                    throw new RuntimeException('升级包缺少文件: ' . $rel);
                }
                $target = DCAI_ROOT . '/' . $dst;
                @mkdir(dirname($target), 0755, true);
                if (!@copy($src, $target)) {
                    throw new RuntimeException('替换文件失败: ' . $rel);
                }
                $applied[] = $rel;
            }

            // 4. 删除旧文件
            foreach ($deleteFiles as $rel) {
                $rel = (string)$rel;
                $dst = self::safeRel($rel);
                if ($dst === null) {
                    throw new RuntimeException('升级包包含非法路径: ' . $rel);
                }
                $target = DCAI_ROOT . '/' . $dst;
                if (is_file($target)) {
                    if (!@unlink($target)) {
                        throw new RuntimeException('删除文件失败: ' . $rel);
                    }
                    $deleted[] = $rel;
                }
            }

            // 5. 执行迁移脚本（成功后自删除）
            if ($migrate !== '') {
                $dst = self::safeRel($migrate);
                if ($dst === null || !is_file(DCAI_ROOT . '/' . $dst)) {
                    throw new RuntimeException('迁移脚本不存在: ' . $migrate);
                }
                try {
                    require DCAI_ROOT . '/' . $dst;
                } catch (Throwable $e) {
                    throw new RuntimeException('迁移脚本执行失败: ' . $e->getMessage());
                }
                @unlink(DCAI_ROOT . '/' . $dst);
            }

            // 6. 更新系统版本号
            if (!self::updateVersionInConfig($row['version'])) {
                throw new RuntimeException('更新系统版本号失败');
            }

            // 7. 清理暂存并保留最近 3 份备份
            DCAI_Util::rmRecursive($staging);
            self::pruneBackups($backupRoot, 3);

            dcai_db()->update('system_updates', [
                'status'     => self::STATUS_APPLIED,
                'applied_at' => dcai_now(),
            ], 'id = ?', [$id]);
            DCAI_Admin::opLog('应用系统升级', ['id' => $id, 'version' => $row['version']]);
            return [true, ['id' => $id, 'version' => $row['version']], ''];
        } catch (Throwable $e) {
            // 回滚
            self::rollback($backupDir, $applied, $deleted);
            DCAI_Util::rmRecursive($staging);
            dcai_db()->update('system_updates', ['status' => self::STATUS_FAILED], 'id = ?', [$id]);
            dcai_log('error', '系统升级应用失败', ['id' => $id, 'err' => $e->getMessage()]);
            return [false, null, '升级失败，已回滚: ' . $e->getMessage()];
        }
    }

    private static function readSystemMeta(string $zipFile): ?array
    {
        $raw = DCAI_ZipHelper::readEntry($zipFile, 'system.json');
        if ($raw === null) {
            return null;
        }
        $meta = json_decode($raw, true);
        return is_array($meta) ? $meta : null;
    }

    /**
     * 规范化相对路径并防穿越，非法返回 null
     */
    private static function safeRel(string $rel): ?string
    {
        $clean = str_replace('\\', '/', $rel);
        $clean = ltrim($clean, '/');
        if ($clean === '' || strpos($clean, '../') !== false || strpos($clean, '/..') === 0 || preg_match('#^[a-zA-Z]:#', $clean)) {
            return null;
        }
        return $clean;
    }

    private static function rollback(string $backupDir, array $applied, array $deleted): void
    {
        foreach (array_merge($applied, $deleted) as $rel) {
            $dst = self::safeRel($rel);
            if ($dst === null) {
                continue;
            }
            $target = DCAI_ROOT . '/' . $dst;
            $src = $backupDir . '/' . $dst;
            if (is_file($src)) {
                // 有备份 → 还原
                @mkdir(dirname($target), 0755, true);
                @copy($src, $target);
            } elseif (is_file($target)) {
                // 升级前不存在（新增文件）→ 回滚时删除，避免残留
                @unlink($target);
            }
        }
    }

    /**
     * 更新 config.php 中 app.version
     */
    private static function updateVersionInConfig(string $version): bool
    {
        $file = DCAI_CONFIG_FILE;
        if (!is_file($file)) {
            return false;
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            return false;
        }
        $new = preg_replace("/('version'\s*=>\s*)'[^']*'/", "\${1}'" . $version . "'", $content, 1);
        if ($new === $content) {
            $new = preg_replace("/(\s*)('app'\s*=>\s*\[)/", "\${1}\${2}\n    'version' => '" . $version . "',", $content, 1);
        }
        if ($new === $content) {
            return false;
        }
        return @file_put_contents($file, $new, LOCK_EX) !== false;
    }

    private static function pruneBackups(string $backupRoot, int $keep): void
    {
        $dirs = glob($backupRoot . '/system_*');
        if (!is_array($dirs) || count($dirs) <= $keep) {
            return;
        }
        sort($dirs);
        $excess = array_slice($dirs, 0, count($dirs) - $keep);
        foreach ($excess as $d) {
            DCAI_Util::rmRecursive($d);
        }
    }
}

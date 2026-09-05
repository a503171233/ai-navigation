<?php
/**
 * 远程更新服务：更新包、检测、下载、上报
 */
class DCAI_UpdateService
{
    /**
     * 上传更新包（zip，顶层含 update.json）
     * @return array{0:bool,1:mixed,2:string}
     */
    public static function upload(array $file, int $productId, int $adminId = 0): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [false, null, '文件上传失败'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return [false, null, '仅允许 zip 更新包'];
        }
        if ($file['size'] > (int)dcai_config('storage.max_package_size', 209715200)) {
            return [false, null, '更新包超过大小上限'];
        }
        $product = dcai_db()->queryOne('SELECT * FROM products WHERE id = ?', [$productId]);
        if (!$product) {
            return [false, null, '产品不存在'];
        }

        $jsonRaw = DCAI_ZipHelper::readEntry($file['tmp_name'], 'update.json');
        if ($jsonRaw === null) {
            return [false, null, '更新包缺少 update.json'];
        }
        $meta = json_decode($jsonRaw, true);
        if (!is_array($meta) || empty($meta['version'])) {
            return [false, null, 'update.json 格式错误'];
        }
        if (!DCAI_Version::valid($meta['version'])) {
            return [false, null, '版本号格式错误'];
        }
        if (DCAI_Version::lte($meta['version'], $product['current_version'])) {
            return [false, null, '新版本必须高于当前产品版本（' . $product['current_version'] . '）'];
        }

        // 解压安全校验
        $tmpDir = (dcai_config('storage.path', DCAI_ROOT . '/storage') . '/cache/extract_upd_' . bin2hex(random_bytes(6)));
        [$ok, $res] = DCAI_ZipHelper::extract($file['tmp_name'], $tmpDir, 3000, 536870912, ['php', 'js', 'css', 'html', 'htm', 'md', 'json', 'txt', 'ini', 'png', 'jpg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'sql', 'py']);
        if (!$ok) {
            return [false, null, $res];
        }
        DCAI_Util::rmRecursive($tmpDir);

        $db = dcai_db();
        $now = dcai_now();
        $db->beginTransaction();
        try {
            $storagePath = (string)dcai_config('storage.path', DCAI_ROOT . '/storage');
            $relPath = 'updates/' . $product['product_code'] . '_' . $meta['version'] . '_' . time() . '.zip';
            $target = $storagePath . '/' . $relPath;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                if (!@copy($file['tmp_name'], $target)) {
                    $db->rollback();
                    return [false, null, '保存更新包失败'];
                }
            }
            $updateId = $db->insert('updates', [
                'product_id'   => $productId,
                'version'      => $meta['version'],
                'package_path' => $relPath,
                'package_md5'  => md5_file($target),
                'package_size' => filesize($target),
                'min_version'  => $meta['min_version'] ?? '',
                'changelog'    => $meta['changelog'] ?? ($meta['description'] ?? ''),
                'is_force'     => (int)($meta['is_force'] ?? 0),
                'status'       => 0, // 默认待发布
                'created_by'   => $adminId ?: null,
                'created_at'   => $now,
            ]);
            // 注意：上传阶段不更新 products.current_version（待发布版本尚未生效，
            // 否则撤回后产品版本与实际不符；版本同步在 publish() 发布时进行）
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            dcai_log('error', '更新包上传失败', ['err' => $e->getMessage()]);
            return [false, null, '保存更新包失败: ' . $e->getMessage()];
        }
        return [true, ['update_id' => $updateId, 'version' => $meta['version']], ''];
    }

    /**
     * 发布/撤回更新包，同步产品 current_version
     *  - 发布：将产品当前版本提升为该更新包版本
     *  - 撤回：仅当产品当前版本等于该更新包版本时回退（可能有多版本，回溯取最高已发布版本）
     * @return array{0:bool,1:mixed,2:string}
     */
    public static function publish(int $updateId, int $status): array
    {
        if (!in_array($status, [1, 2], true)) {
            return [false, null, '非法状态值']; // 1=发布 2=撤回
        }
        $db = dcai_db();
        $update = $db->queryOne('SELECT * FROM updates WHERE id = ?', [$updateId]);
        if (!$update) {
            return [false, null, '更新包不存在'];
        }
        $now = dcai_now();
        if ($status === 1) {
            $db->update('updates', ['status' => 1], 'id = ?', [$updateId]);
            // 产品当前版本若低于该更新版本，则提升（发布生效；用 PHP 版本比较，避免 SQL 字符串比较语义错误）
            $product = $db->queryOne('SELECT current_version FROM products WHERE id = ?', [(int)$update['product_id']]);
            if ($product && DCAI_Version::lt((string)$product['current_version'], (string)$update['version'])) {
                $db->update('products', ['current_version' => $update['version'], 'updated_at' => $now], 'id = ?', [(int)$update['product_id']]);
            }
            // 自动推送：为所有该产品的在线实例下发 update 命令（实例轮询到命令后自动执行更新）
            $notified = 0;
            try {
                $instances = $db->query(
                    'SELECT id FROM instances WHERE product_id = ? AND status = 1',
                    [(int)$update['product_id']]
                );
                foreach ($instances as $inst) {
                    // 目标版本仅面向低于新版本的实例；避免向已是最新的实例重复下发
                    $instRow = $db->queryOne('SELECT version FROM instances WHERE id = ?', [(int)$inst['id']]);
                    if ($instRow && DCAI_Version::lt((string)$instRow['version'], (string)$update['version'])) {
                        DCAI_CommandService::issue((int)$inst['id'], 'update', ['target_version' => $update['version']], 0);
                        $notified++;
                    }
                }
                dcai_log('info', '更新包发布，已下发自动更新命令', ['update_id' => $updateId, 'version' => $update['version'], 'notified' => $notified]);
            } catch (Throwable $e) {
                dcai_log('error', '更新包发布后下发命令失败', ['update_id' => $updateId, 'err' => $e->getMessage()]);
            }
            return [true, ['id' => $updateId, 'status' => 1, 'notified_instances' => $notified], ''];
        }
        // 撤回
        $db->update('updates', ['status' => 2], 'id = ?', [$updateId]);
        // 仅当产品当前版本等于被撤回版本时，回退到最高已发布版本
        $product = $db->queryOne('SELECT current_version FROM products WHERE id = ?', [(int)$update['product_id']]);
        if ($product && $product['current_version'] === $update['version']) {
            $latest = $db->queryOne(
                "SELECT version FROM updates WHERE product_id = ? AND status = 1 ORDER BY id DESC LIMIT 1",
                [(int)$update['product_id']]
            );
            $db->update('products', ['current_version' => $latest ? $latest['version'] : '1.0.0', 'updated_at' => $now], 'id = ?', [(int)$update['product_id']]);
        }
        return [true, ['id' => $updateId, 'status' => 2], ''];
    }

    /**
     * 查询产品最新已发布更新包（版本大于当前版本且满足 min_version）
     */
    public static function latestForProduct(int $productId, string $currentVersion): ?array
    {
        $updates = dcai_db()->query(
            'SELECT * FROM updates WHERE product_id = ? AND status = 1 ORDER BY id DESC',
            [$productId]
        );
        foreach ($updates as $update) {
            if (!DCAI_Version::gt($update['version'], $currentVersion)) {
                continue;
            }
            if ($update['min_version'] !== '' && !DCAI_Version::gte($currentVersion, $update['min_version'])) {
                continue;
            }
            return $update;
        }
        return null;
    }

    /**
     * 更新检测
     */
    public static function check(int $productId, string $currentVersion, ?int $instanceRowId = null): array
    {
        $update = self::latestForProduct($productId, $currentVersion);
        if (!$update) {
            return [null, ['has_update' => false]];
        }
        $data = [
            'has_update' => true,
            'update'     => [
                'version'      => $update['version'],
                'changelog'    => $update['changelog'] ?? '',
                'is_force'     => (int)$update['is_force'] === 1,
                'md5'          => $update['package_md5'],
                'size'         => (int)$update['package_size'],
                'download_url' => self::buildDownloadUrl((int)$update['id'], $instanceRowId),
            ],
        ];
        return [null, $data];
    }

    public static function buildDownloadUrl(int $updateId, ?int $instanceRowId = null): string
    {
        $expire = time() + 600;
        $sign = self::signDownload($updateId, $instanceRowId, $expire);
        $base = rtrim((string)dcai_config('app.base_url', ''), '/');
        return $base . '/api/v1/update/download?u=' . $updateId
            . '&i=' . ($instanceRowId ?? '')
            . '&e=' . $expire
            . '&s=' . $sign;
    }

    private static function signDownload(int $updateId, ?int $instanceRowId, int $expire): string
    {
        // 独立下载签名密钥（config.security.download_secret），未配置则回退 aes_key 保证向后兼容
        $secret = (string)dcai_config('security.download_secret', dcai_config('security.aes_key', ''));
        return DCAI_Signature::make($secret, $expire, 'dl:' . ($instanceRowId ?? ''), (string)$updateId);
    }

    /**
     * 校验下载签名并返回更新包信息
     */
    public static function resolveDownload(int $updateId, ?int $instanceRowId, int $expire, string $sign): ?array
    {
        $secret = (string)dcai_config('security.download_secret', dcai_config('security.aes_key', ''));
        $expected = self::signDownload($updateId, $instanceRowId, $expire);
        if (!hash_equals($expected, $sign) || $expire < time()) {
            return null;
        }
        $update = dcai_db()->queryOne('SELECT * FROM updates WHERE id = ?', [$updateId]);
        if (!$update || (int)$update['status'] !== 1) {
            return null;
        }
        return $update;
    }

    /**
     * 记录更新下载/应用日志（同实例同目标版本去重：存在未完成记录则复用，不新增）
     */
    public static function logApply(int $updateId, int $instanceRowId, string $fromVersion, string $status, string $error = ''): int
    {
        $update = dcai_db()->queryOne('SELECT * FROM updates WHERE id = ?', [$updateId]);
        $toVersion = $update['version'] ?? '';
        $now = dcai_now();
        // 去重：同实例同目标版本已有未完结(0下载中/1已下载)记录则复用
        $existing = dcai_db()->queryOne(
            'SELECT id FROM update_apply_logs WHERE instance_id = ? AND to_version = ? AND status IN (0,1) ORDER BY id DESC LIMIT 1',
            [$instanceRowId, $toVersion]
        );
        if ($existing) {
            return (int)$existing['id'];
        }
        return dcai_db()->insert('update_apply_logs', [
            'update_id'    => $updateId,
            'instance_id'  => $instanceRowId,
            'from_version' => $fromVersion,
            'to_version'   => $toVersion,
            'status'       => (int)$status,
            'error'        => substr($error, 0, 500),
            'created_at'   => $now,
            'finished_at'  => ($status == 2 || $status == 3) ? $now : null,
        ]);
    }

    /**
     * 更新结果上报：更新 update_apply_logs，同步实例 version
     */
    public static function report(int $instanceRowId, string $targetVersion, int $status, string $error = ''): array
    {
        $db = dcai_db();
        $log = $db->queryOne(
            'SELECT * FROM update_apply_logs WHERE instance_id = ? AND to_version = ? AND status IN (0,1) ORDER BY id DESC LIMIT 1',
            [$instanceRowId, $targetVersion]
        );
        if ($log) {
            $db->update('update_apply_logs', [
                'status'      => $status,
                'error'       => substr($error, 0, 500),
                'finished_at' => dcai_now(),
            ], 'id = ?', [(int)$log['id']]);
        }
        if ($status === 2) {
            $db->update('instances', ['version' => $targetVersion, 'updated_at' => dcai_now()], 'id = ?', [$instanceRowId]);
        }
        return [true, null];
    }
}

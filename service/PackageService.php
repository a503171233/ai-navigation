<?php
/**
 * 安装包服务：上传、manifest 解析、安全校验、产品创建
 */
class DCAI_PackageService
{
    /**
     * 上传安装包并创建/更新产品
     * @param array $file $_FILES['package']
     * @return array{0:bool,1:mixed,2:string} [成功, 数据, 错误]
     */
    public static function upload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [false, null, '文件上传失败'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return [false, null, '仅允许上传 zip 安装包'];
        }
        if ($file['size'] > (int)dcai_config('storage.max_package_size', 209715200)) {
            return [false, null, '安装包超过大小上限'];
        }

        // 读取 manifest.json
        $manifestRaw = DCAI_ZipHelper::readEntry($file['tmp_name'], 'manifest.json');
        if ($manifestRaw === null) {
            return [false, null, '安装包缺少 manifest.json'];
        }
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest)) {
            return [false, null, 'manifest.json 格式错误'];
        }
        $required = ['product_code', 'product_name', 'version'];
        foreach ($required as $field) {
            if (empty($manifest[$field])) {
                return [false, null, "manifest.json 缺少字段: $field"];
            }
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $manifest['product_code'])) {
            return [false, null, 'product_code 仅允许字母数字下划线'];
        }
        if (!DCAI_Version::valid($manifest['version'])) {
            return [false, null, '版本号格式错误'];
        }

        // 解压安全性校验（防穿越 + 白名单扩展名）
        $tmpDir = (dcai_config('storage.path', DCAI_ROOT . '/storage') . '/cache/extract_' . bin2hex(random_bytes(6)));
        [$ok, $res] = DCAI_ZipHelper::extract($file['tmp_name'], $tmpDir, 2000, 536870912, ['php', 'js', 'css', 'html', 'htm', 'md', 'json', 'txt', 'ini', 'png', 'jpg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot']);
        if (!$ok) {
            return [false, null, $res];
        }
        DCAI_Util::rmRecursive($tmpDir);

        $db = dcai_db();
        $now = dcai_now();

        $db->beginTransaction();
        try {
            // 产品存在性：存在则更新版本，否则创建
            $product = $db->queryOne('SELECT * FROM products WHERE product_code = ?', [$manifest['product_code']]);
            if ($product) {
                if (DCAI_Version::lte($manifest['version'], $product['current_version'])) {
                    $db->rollback();
                    return [false, null, '产品已存在且版本不高于当前版本'];
                }
                $db->update('products', [
                    'name'            => $manifest['product_name'],
                    'description'     => $manifest['description'] ?? $product['description'],
                    'current_version' => $manifest['version'],
                    'status'          => 1,
                    'updated_at'      => $now,
                ], 'id = ?', [(int)$product['id']]);
                $productId = (int)$product['id'];
            } else {
                $productId = $db->insert('products', [
                    'product_code'    => $manifest['product_code'],
                    'name'            => $manifest['product_name'],
                    'description'     => $manifest['description'] ?? '',
                    'current_version' => $manifest['version'],
                    'enforce_auth'    => 0,
                    'fail_open'       => 1,
                    'verify_ttl'      => (int)dcai_config('security.verify_ttl', 3600),
                    'status'          => 1,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }

            // 保存安装包文件
            $storagePath = (string)dcai_config('storage.path', DCAI_ROOT . '/storage');
            $relPath = 'packages/' . $manifest['product_code'] . '_' . $manifest['version'] . '_' . time() . '.zip';
            $target = $storagePath . '/' . $relPath;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                if (!@copy($file['tmp_name'], $target)) {
                    $db->rollback();
                    return [false, null, '保存安装包文件失败'];
                }
            }

            $pkgId = $db->insert('install_packages', [
                'product_id'    => $productId,
                'version'       => $manifest['version'],
                'package_path'  => $relPath,
                'package_md5'   => md5_file($target),
                'package_size'  => filesize($target),
                'manifest'      => $manifestRaw,
                'download_count' => 0,
                'status'        => 1,
                'created_at'    => $now,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            dcai_log('error', '安装包上传失败', ['err' => $e->getMessage()]);
            return [false, null, '保存安装包失败: ' . $e->getMessage()];
        }

        return [true, ['product_id' => $productId, 'package_id' => $pkgId ?? 0], ''];
    }

    /**
     * 下载安装包
     */
    public static function download(int $packageId): ?array
    {
        $pkg = dcai_db()->queryOne('SELECT * FROM install_packages WHERE id = ?', [$packageId]);
        if (!$pkg || (int)$pkg['status'] !== 1) {
            return null;
        }
        dcai_db()->increment('install_packages', 'download_count', 'id = ?', [$packageId]);
        $storagePath = (string)dcai_config('storage.path', DCAI_ROOT . '/storage');
        $full = $storagePath . '/' . $pkg['package_path'];
        if (!is_file($full)) {
            return null;
        }
        return $pkg + ['full_path' => $full];
    }
}

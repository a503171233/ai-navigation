<?php
/**
 * 授权码服务：生成、校验、查询
 */
class DCAI_LicenseService
{
    /**
     * 生成授权码（保证全局唯一）
     */
    public static function generateUniqueKey(): string
    {
        $db = dcai_db();
        do {
            $key = DCAI_Util::generateLicenseKey();
            $exists = $db->queryValue('SELECT COUNT(*) FROM licenses WHERE license_key = ?', [$key]);
        } while ($exists);
        return $key;
    }

    /**
     * 创建授权码
     * expire_at 可空：null 表示永久授权
     */
    public static function create(array $data): array
    {
        $required = ['product_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return [false, "缺少必填字段: $field"];
            }
        }
        $product = dcai_db()->queryOne('SELECT id FROM products WHERE id = ?', [(int)$data['product_id']]);
        if (!$product) {
            return [false, '产品不存在'];
        }
        $licenseKey = !empty($data['license_key']) ? $data['license_key'] : self::generateUniqueKey();
        if (dcai_db()->queryValue('SELECT COUNT(*) FROM licenses WHERE license_key = ?', [$licenseKey])) {
            return [false, '授权码已存在'];
        }

        $now = dcai_now();
        $id = dcai_db()->insert('licenses', [
            'license_key'     => $licenseKey,
            'product_id'      => (int)$data['product_id'],
            'customer_name'   => $data['customer_name'] ?? '',
            'customer_email'  => $data['customer_email'] ?? '',
            'allowed_domains' => $data['allowed_domains'] ?? '',
            'allowed_ips'     => $data['allowed_ips'] ?? '',
            'max_instances'   => max(1, (int)($data['max_instances'] ?? 1)),
            'machine_limit'   => max(0, (int)($data['machine_limit'] ?? 0)),
            'trial_days'      => max(0, (int)($data['trial_days'] ?? 0)),
            'expire_at'       => $data['expire_at'] ?? null,
            'remark'          => $data['remark'] ?? '',
            'status'          => isset($data['status']) ? (int)$data['status'] : 1,
            'source'          => isset($data['source']) ? (int)$data['source'] : 0,
            'buyer_id'        => !empty($data['buyer_id']) ? (int)$data['buyer_id'] : null,
            'order_id'        => !empty($data['order_id']) ? (int)$data['order_id'] : null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        return [true, $id];
    }

    /**
     * 批量创建（整体事务，任一失败则全部回滚）
     */
    public static function batchCreate(array $base, int $count): array
    {
        $db = dcai_db();
        $keys = [];
        $db->beginTransaction();
        try {
            for ($i = 0; $i < $count; $i++) {
                $data = $base;
                unset($data['license_key']);
                [$ok, $res] = self::create($data);
                if (!$ok) {
                    $db->rollback();
                    return [false, '第 ' . ($i + 1) . ' 个失败: ' . $res];
                }
                $keys[] = dcai_db()->queryValue('SELECT license_key FROM licenses WHERE id = ?', [(int)$res]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            dcai_log('error', '批量创建授权码失败', ['err' => $e->getMessage()]);
            return [false, '批量创建失败: ' . $e->getMessage()];
        }
        return [true, $keys];
    }

    /**
     * 校验授权码状态：返回 [错误码|null, 授权码记录|null]
     */
    public static function validate(string $licenseKey, int $productId): array
    {
        $license = dcai_db()->queryOne('SELECT * FROM licenses WHERE license_key = ?', [$licenseKey]);
        if (!$license) {
            return [2001, null];
        }
        if ((int)$license['status'] !== 1) {
            return [2002, null];
        }
        // 永久授权：expire_at 为 NULL 永不过期
        if ($license['expire_at'] !== null && $license['expire_at'] !== '' && strtotime($license['expire_at']) < time()) {
            return [2003, null];
        }
        if ((int)$license['product_id'] !== $productId) {
            return [2004, null];
        }
        return [null, $license];
    }

    /**
     * 检查实例数是否超限
     */
    public static function instanceCountOk(int $licenseId, int $maxInstances): bool
    {
        $count = dcai_db()->queryValue(
            'SELECT COUNT(*) FROM instances WHERE license_id = ? AND status <> 2',
            [$licenseId]
        );
        return (int)$count < $maxInstances;
    }

    /**
     * 授权码掩码
     */
    public static function mask(string $key): string
    {
        return DCAI_Util::maskLicenseKey($key);
    }
}

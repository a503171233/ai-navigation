<?php
/**
 * 机器码/硬件指纹绑定服务
 *
 * 能力：
 *  - machineCode(array $clientData, string $secretKey): string  客户端指纹 → 规范化 HMAC 摘要
 *  - bindOrReleaseByInstance(): 注册时校验/自动绑定
 *  - bind() / unbind(): 后台手动绑定/解绑
 *  - isMachineAllowed(): 机器码是否在授权绑定内（含 machine_limit 判断）
 *
 * 设计要点：
 *  - 机器码是「指纹原文 → SHA256 → HMAC-SHA256(app_secret)」的 64 位 hex 摘要，
 *    服务端不保存指纹原文，只有摘要可比对；即使库泄露也无法反推硬件特征。
 *  - machine_limit=0 表示不限制机器数（默认放开兼容旧行为）；
 *    否则按 machine_bindings 表实际绑定数约束（区别于 max_instances 的实例数）。
 *  - 授权码持有者在授权码额度内自行绑定机器；解绑由后台管理员操作或自助流程。
 */
class DCAI_MachineService
{
    public const ERR_NO_MACHINE = 2010; // 缺少机器码
    public const ERR_NOT_BOUND  = 2011; // 机器未绑定
    public const ERR_LIMIT      = 2012; // 绑定机器数超限

    /**
     * 由客户端上报的指纹数据生成规范机器码（服务端概念，不暴露原始硬件信息）
     * 输入：['platform'=>..., 'hostname'=>..., 'cpu'=>..., 'disk'=>..., 'mac'=>..., 'os'=>...]
     */
    public static function machineCode(array $clientData, ?string $secretKey = null): string
    {
        $secretKey = $secretKey ?? ((string)dcai_config('sdk.app_secret', ''));
        if ($secretKey === '') {
            // 无可信密钥时退化为仅 SHA256（不理想但可降级）
            $secretKey = 'dcai_fallback';
        }
        $parts = [];
        foreach (['platform', 'hostname', 'cpu', 'disk', 'mac', 'os'] as $k) {
            $v = trim((string)($clientData[$k] ?? ''));
            if ($v !== '') {
                $parts[] = $k . '=' . strtolower($v);
            }
        }
        sort($parts);
        $raw = implode("\n", $parts);
        // 双重摘要：先 SHA256 再 HMAC，指纹不可逆
        return hash_hmac('sha256', hash('sha256', $raw), $secretKey);
    }

    /**
     * 【统一入口】机器码授权校验 + 自动绑定（verify / register / 离线签发复用）
     * 修复点：
     *  - 格式强校验：非法 machine_code（非 32-64 位 hex）直接返回 ERR_NOT_BOUND(2011)，
     *    杜绝脏数据写入绑定表、杜绝"非空但无效值"被静默放行；
     *  - 竞态防超配：用 SELECT ... FOR UPDATE 锁授权码行，锁内复查配额再绑定，
     *    解决并发注册不同机器导致 machine_limit 超配的 TOCTOU 问题；
     *  - 绑定幂等：已绑定机器直接放行；未达上限自动绑定；已达上限返回 ERR_LIMIT。
     *
     * @return int|null 错误码(null=通过)
     */
    public static function authorizeMachine(int $licenseId, string $machineCode, string $machineName = ''): ?int
    {
        $machineCode = strtolower(trim($machineCode));
        $licenseId = (int)$licenseId;
        if ($licenseId <= 0) {
            return self::ERR_NO_MACHINE;
        }
        $license = dcai_db()->queryOne('SELECT * FROM licenses WHERE id = ?', [$licenseId]);
        if (!$license) {
            return self::ERR_NOT_BOUND;
        }
        $machineLimit = (int)($license['machine_limit'] ?? 0);

        // 空机器码：不强制（Web/后台等无指纹场景）——仅在限量模式下仍放行，避免影响既有行为
        if ($machineCode === '') {
            return null;
        }
        // 格式强校验：32-64 位小写 hex
        if (!preg_match('/^[a-f0-9]{32,64}$/', $machineCode)) {
            return self::ERR_NOT_BOUND;
        }
        // 不限制机器数：仍记录/刷新绑定（便于后台查看机器列表）
        if ($machineLimit <= 0) {
            self::bind($licenseId, $machineCode, $machineName);
            return null;
        }

        $db = dcai_db();
        try {
            $db->beginTransaction();
            // 锁授权码行，串行化同授权码的机器绑定（防并发超配）
            $db->queryOne('SELECT id FROM licenses WHERE id = ? FOR UPDATE', [$licenseId]);
            // 已绑定则直接放行
            $bound = (int)$db->queryValue(
                'SELECT COUNT(*) FROM machine_bindings WHERE license_id = ? AND machine_code = ? AND status = 1',
                [$licenseId, $machineCode]
            );
            if ($bound > 0) {
                $db->rollback();
                return null; // 已绑定放行（last_seen 由心跳路径更新，无需在此额外写库）
            }
            // 配额复查
            $total = (int)$db->queryValue(
                'SELECT COUNT(*) FROM machine_bindings WHERE license_id = ? AND status = 1',
                [$licenseId]
            );
            if ($total >= $machineLimit) {
                $db->rollback();
                return self::ERR_LIMIT;
            }
            // 锁内绑定
            $ok = self::bind($licenseId, $machineCode, $machineName);
            $db->commit();
            return $ok ? null : self::ERR_NO_MACHINE;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            dcai_log('error', '机器码授权失败', ['license_id' => $licenseId, 'err' => $e->getMessage()]);
            return self::ERR_NO_MACHINE;
        }
    }

    /**
     * 校验授权码是否允许「指定机器码」使用
     * @return bool 允许/拒绝
     */
    public static function isMachineAllowed(int $licenseId, string $machineCode, int $machineLimit): bool
    {
        if ($machineCode === '') {
            return false;
        }
        if ($machineLimit <= 0) {
            // 不限制机器数
            return true;
        }
        $count = (int)dcai_db()->queryValue(
            'SELECT COUNT(*) FROM machine_bindings WHERE license_id = ? AND status = 1',
            [$licenseId]
        );
        if ($count >= $machineLimit) {
            // 已达上限：仅当该机器已在绑定列表内才放行
            $bound = (int)dcai_db()->queryValue(
                'SELECT COUNT(*) FROM machine_bindings WHERE license_id = ? AND machine_code = ? AND status = 1',
                [$licenseId, $machineCode]
            );
            return $bound > 0;
        }
        // 未到上限：绑定后放行（幂等 upsert）
        self::bind($licenseId, $machineCode, '');
        return true;
    }

    /**
     * 绑定机器（幂等 upsert；已存在则更新时间戳）
     */
    public static function bind(int $licenseId, string $machineCode, string $machineName, string $instanceId = ''): bool
    {
        if ($licenseId <= 0 || $machineCode === '') {
            return false;
        }
        $db = dcai_db();
        $now = dcai_now();
        $existing = $db->queryOne(
            'SELECT id, machine_name, first_instance_id FROM machine_bindings WHERE license_id = ? AND machine_code = ?',
            [$licenseId, $machineCode]
        );
        if ($existing) {
            return (bool)$db->update(
                'machine_bindings',
                [
                    'machine_name'   => $machineName !== '' ? $machineName : $existing['machine_name'],
                    'first_instance_id' => $instanceId !== '' ? $instanceId : $existing['first_instance_id'],
                    'last_seen_at'   => $now,
                    'status'         => 1,
                    'updated_at'     => $now,
                ],
                'id = ?',
                [(int)$existing['id']]
            );
        }
        $db->insert('machine_bindings', [
            'license_id'        => $licenseId,
            'machine_code'      => $machineCode,
            'machine_name'      => mb_substr($machineName, 0, 255),
            'first_instance_id' => mb_substr($instanceId, 0, 64),
            'first_seen_at'     => $now,
            'last_seen_at'      => $now,
            'status'            => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        return true;
    }

    /**
     * 解绑机器（软删：status=0）
     */
    public static function unbind(int $licenseId, string $machineCode): bool
    {
        return (bool)dcai_db()->update(
            'machine_bindings',
            ['status' => 0, 'updated_at' => dcai_now()],
            'license_id = ? AND machine_code = ?',
            [$licenseId, $machineCode]
        );
    }

    /**
     * 列出授权码已绑定机器
     */
    public static function listByLicense(int $licenseId): array
    {
        return dcai_db()->query(
            'SELECT * FROM machine_bindings WHERE license_id = ? ORDER BY id DESC',
            [$licenseId]
        );
    }

    /**
     * 注册流程：校验并自动绑定机器（统一走 authorizeMachine，杜绝重复逻辑漂移）
     * 返回 [错误码|null, 机器码|null]
     */
    public static function checkForRegister(array $req, array $license): array
    {
        $machineCode = trim((string)($req['machine_code'] ?? ''));
        if ($machineCode === '') {
            // 允许不传机器码（Web 场景），仅在传了且产品开启机器码校验时限制
            return [null, ''];
        }
        $err = self::authorizeMachine((int)$license['id'], $machineCode, (string)($req['machine_name'] ?? ''));
        if ($err !== null) {
            return [$err, $machineCode];
        }
        return [null, $machineCode];
    }
}
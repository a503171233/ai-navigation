<?php
/**
 * 实例服务：注册、心跳、令牌校验
 */
class DCAI_InstanceService
{
    /**
     * 实例注册（三合一校验后调用）
     * 以 (product_id, domain) 幂等：已存在则复用并返回原凭证
     */
    public static function register(array $req): array
    {
        $productCode = trim((string)($req['product_code'] ?? ''));
        $licenseKey  = trim((string)($req['license_key'] ?? ''));
        $domain      = strtolower(trim((string)($req['domain'] ?? '')));
        $ip          = trim((string)($req['ip'] ?? ''));

        // 复用三合一基础校验（产品存在/授权码有效/域名/IP 白名单），避免逻辑重复漂移
        [$err, $product, $license] = DCAI_VerifyService::validateBasics($productCode, $licenseKey, $domain, $ip);
        if ($err !== null) {
            return [$err, null];
        }

        // 机器码校验与自动绑定（client 上报指纹时）——统一走 authorizeMachine（格式强校验 + 事务防超配）
        $machineCode = trim((string)($req['machine_code'] ?? ''));
        if ($machineCode !== '') {
            $machineErr = DCAI_MachineService::authorizeMachine(
                (int)$license['id'],
                $machineCode,
                (string)($req['machine_name'] ?? '')
            );
            if ($machineErr !== null) {
                return [$machineErr, null];
            }
        }

        $db = dcai_db();
        $now = dcai_now();

        // 幂等：同产品同域名已有实例则复用
        $existing = $db->queryOne(
            'SELECT * FROM instances WHERE product_id = ? AND domain = ? ORDER BY id DESC LIMIT 1',
            [(int)$product['id'], $domain]
        );
        if ($existing) {
            if ((int)$existing['status'] === 2) {
                return [2008, null];
            }
            $token = DCAI_Crypto::decrypt($existing['instance_token_enc']);
            if ($token === '') {
                $token = self::issueToken($existing['id']);
            }
            return [null, self::instanceResponse($existing, $token)];
        }

        // 实例数校验（注册才消耗名额）——快速失败检查，事务内会加锁复查
        if (!DCAI_LicenseService::instanceCountOk((int)$license['id'], (int)$license['max_instances'])) {
            return [2007, null];
        }

        $instanceUuid = DCAI_Util::uuid();
        $instanceToken = bin2hex(random_bytes(32));

        $db->beginTransaction();
        try {
            // 锁定产品行，串行化同产品的并发注册（防止 (product_id, domain) 双写与实例数超卖）
            $db->queryOne('SELECT id FROM products WHERE id = ? FOR UPDATE', [(int)$product['id']]);

            // 锁内幂等复查：同产品同域名可能已被并发注册
            $existing = $db->queryOne(
                'SELECT * FROM instances WHERE product_id = ? AND domain = ? ORDER BY id DESC LIMIT 1',
                [(int)$product['id'], $domain]
            );
            if ($existing) {
                $db->rollback();
                if ((int)$existing['status'] === 2) {
                    return [2008, null];
                }
                $token = DCAI_Crypto::decrypt($existing['instance_token_enc']);
                if ($token === '') {
                    $token = self::issueToken($existing['id']);
                }
                return [null, self::instanceResponse($existing, $token)];
            }

            // 锁内复查实例数上限（并发注册串行化后计数准确）
            if (!DCAI_LicenseService::instanceCountOk((int)$license['id'], (int)$license['max_instances'])) {
                $db->rollback();
                return [2007, null];
            }

            $instanceRowId = $db->insert('instances', [
                'instance_id'       => $instanceUuid,
                'instance_token_enc' => DCAI_Crypto::encrypt($instanceToken),
                'product_id'        => (int)$product['id'],
                'license_id'        => (int)$license['id'],
                'domain'            => $domain,
                'ip'                => $ip,
                'machine_code'      => $machineCode,
                'version'           => (string)($req['version'] ?? ''),
                'server_info'       => is_array($req['server_info'] ?? null) ? json_encode($req['server_info'], JSON_UNESCAPED_UNICODE) : '',
                'db_info'           => is_array($req['db_info'] ?? null) ? json_encode($req['db_info'], JSON_UNESCAPED_UNICODE) : '',
                'status'            => 1,
                'last_heartbeat_at' => $now,
                'first_seen_at'     => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            dcai_log('error', '实例注册失败', ['err' => $e->getMessage()]);
            return [5000, null];
        }

        $instance = $db->queryOne('SELECT * FROM instances WHERE id = ?', [$instanceRowId]);
        return [null, self::instanceResponse($instance, $instanceToken)];
    }

    private static function instanceResponse(array $instance, string $token): array
    {
        return [
            'instance_id'       => $instance['instance_id'],
            'instance_token'    => $token,
            'verify_interval'   => (int)(dcai_config('security.verify_ttl', 3600)),
            'heartbeat_interval' => (int)dcai_config('sdk.heartbeat_interval', 60),
            'machine_code'      => (string)($instance['machine_code'] ?? ''),
        ];
    }

    /**
     * 已注册实例令牌失效时重新签发
     */
    public static function issueToken(int $instanceRowId): string
    {
        $token = bin2hex(random_bytes(32));
        dcai_db()->update('instances', ['instance_token_enc' => DCAI_Crypto::encrypt($token), 'updated_at' => dcai_now()], 'id = ?', [$instanceRowId]);
        return $token;
    }

    /**
     * 通过请求头 X-Instance-Id 解析实例并校验令牌签名
     * @return array{0:?int,1:?array} [错误码, 实例记录]
     */
    public static function authenticate(array $headers, string $body): array
    {
        $instanceUuid = $headers['x-instance-id'] ?? '';
        if ($instanceUuid === '') {
            return [2100, null];
        }
        $instance = dcai_db()->queryOne('SELECT * FROM instances WHERE instance_id = ?', [$instanceUuid]);
        if (!$instance) {
            return [2100, null];
        }
        $token = DCAI_Crypto::decrypt($instance['instance_token_enc']);
        if ($token === '') {
            return [2100, null];
        }
        [$ok, $reason] = DCAI_Signature::verify($headers, $token, $body, (int)dcai_config('security.timestamp_max_diff', 300));
        if (!$ok) {
            return [2100, null];
        }
        if ((int)$instance['status'] === 2) {
            return [2008, null];
        }
        return [null, $instance];
    }

    /**
     * 心跳上报
     * @return array{0:?int,1:?array} [错误码, 心跳响应]
     */
    public static function heartbeat(int $instanceRowId, array $req, array $instance): array
    {
        $now = dcai_now();
        dcai_db()->update('instances', [
            'version'           => (string)($req['version'] ?? $instance['version']),
            'server_info'       => is_array($req['server_info'] ?? null) ? json_encode($req['server_info'], JSON_UNESCAPED_UNICODE) : ($instance['server_info'] ?? ''),
            'db_info'           => is_array($req['db_info'] ?? null) ? json_encode($req['db_info'], JSON_UNESCAPED_UNICODE) : ($instance['db_info'] ?? ''),
            'ip'                => !empty($req['ip'] ?? '') ? (string)$req['ip'] : $instance['ip'],
            'status'            => 1,
            'last_heartbeat_at' => $now,
            'updated_at'        => $now,
        ], 'id = ?', [$instanceRowId]);

        // 待执行命令数
        $pendingCommands = (int)dcai_db()->queryValue(
            'SELECT COUNT(*) FROM instance_commands WHERE instance_id = ? AND status = 0',
            [$instanceRowId]
        );

        // 命中弹窗数
        $popupCount = DCAI_PopupService::hitCount($instanceRowId);

        // 新版本检测：仅在存在新版本时透传 update 信息，无更新时为 null
        // （SDK 端以 empty() 判断，若直接下发 ['has_update'=>false] 数组会被误判为"有新版本"）
        [, $updateInfo] = DCAI_UpdateService::check((int)$instance['product_id'], (string)$instance['version'], $instanceRowId);
        $newVersion = (!empty($updateInfo['has_update']) && !empty($updateInfo['update'])) ? $updateInfo['update'] : null;

        return [null, [
            'next_interval'    => (int)dcai_config('sdk.heartbeat_interval', 60),
            'revoked'          => (int)$instance['status'] === 2,
            'pending_commands' => $pendingCommands,
            'new_version'      => $newVersion,
            'server_time'      => $now,
        ]];
    }

    /**
     * 离线判定：将超过阈值的在线实例置为离线
     */
    public static function markOffline(): int
    {
        $threshold = (int)dcai_config('security.heartbeat_threshold', 180);
        $deadline = date('Y-m-d H:i:s', time() - $threshold);
        $affected = dcai_db()->query(
            'SELECT id FROM instances WHERE status = 1 AND (last_heartbeat_at IS NULL OR last_heartbeat_at < ?)',
            [$deadline]
        );
        $count = 0;
        foreach ($affected as $row) {
            dcai_db()->update('instances', ['status' => 0, 'updated_at' => dcai_now()], 'id = ?', [(int)$row['id']]);
            $count++;
        }
        return $count;
    }

    /**
     * 远程禁用/启用实例（后台命令也可直接操作）
     */
    public static function setStatus(int $instanceRowId, int $status): bool
    {
        return (bool)dcai_db()->update(
            'instances',
            ['status' => $status, 'updated_at' => dcai_now()],
            'id = ?',
            [$instanceRowId]
        );
    }
}

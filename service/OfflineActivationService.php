<?php
/**
 * 离线激活服务（内网/断网场景）
 *
 * 流程：
 *  客户端（被授权程序）→ 生成「激活请求文件」→ 交由管理员后台导入签发
 *  管理员 → 后台选择授权码 + 机器指纹 → 签发「离线激活文件」（RSA 签名 + 有效期）
 *  客户端 → 导入激活文件 → 本地验签通过后完成离线授权（无需连接授权服务器）
 *
 * 安全设计：
 *  - 激活文件由授权系统 RSA 私钥签名（防篡改/防伪造）
 *  - 激活文件绑定：license_key + machine_code + product_code + 签发时间 + 到期时间
 *  - SDK 用内置 RSA 公钥验签，验签失败即拒绝
 *  - 激活文件有效期由签发时 expire_at 控制，与授权码本身到期的较近者生效
 */
class DCAI_OfflineActivationService
{
    /**
     * 解析客户端上传的激活请求 JSON，返回 [ok, data|errorMsg]
     * 请求文件结构（SDK 生成）：
     * {
     *   "type": "dcai_offline_request",
     *   "version": 1,
     *   "product_code": "...",
     *   "license_key": "...",
     *   "machine_code": "...",
     *   "machine_name": "...",
     *   "request_id": "uuid/hex",
     *   "requested_at": "Y-m-d H:i:s"
     * }
     */
    public static function parseRequest(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [false, '激活请求文件格式错误（非 JSON）'];
        }
        if (($data['type'] ?? '') !== 'dcai_offline_request') {
            return [false, '不是有效的激活请求文件'];
        }
        foreach (['product_code', 'license_key', 'machine_code', 'request_id'] as $k) {
            if (empty($data[$k])) {
                return [false, "激活请求缺少字段: $k"];
            }
        }
        // 机器码格式强校验（与机器绑定链路一致：32-64 位 hex，防脏数据/注入）
        $mc = strtolower(trim((string)$data['machine_code']));
        if (!preg_match('/^[a-f0-9]{32,64}$/', $mc)) {
            return [false, '机器码格式不合法（32-64 位十六进制）'];
        }
        $data['machine_code'] = $mc;
        // 请求 ID 长度约束，防止超长写入
        $data['request_id'] = substr(trim((string)$data['request_id']), 0, 64);
        return [true, $data];
    }

    /**
     * 后台签发离线激活文件
     * @param int $licenseId 授权码ID
     * @param string $machineCode 机器指纹
     * @param string $machineName 机器名称（选项）
     * @param string|null $expireAt 离线授权到期时间（空=随授权码有效期）
     * @return array [bool, 数据|错误信息] 成功返回 ['ok'=>true, 'data'=>激活文件数组]
     */
    public static function issue(int $licenseId, string $machineCode, string $machineName = '', ?string $expireAt = null): array
    {
        $db = dcai_db();
        $license = $db->queryOne('SELECT * FROM licenses WHERE id = ?', [$licenseId]);
        if (!$license || (int)$license['status'] !== 1) {
            return [false, '授权码不存在或已禁用'];
        }
        $machineCode = strtolower(trim($machineCode));
        // 机器码格式强校验（与机器绑定链路一致），杜绝脏数据/非法值
        if (!preg_match('/^[a-f0-9]{32,64}$/', $machineCode)) {
            return [false, '机器码格式不合法（32-64 位十六进制）'];
        }
        $product = $db->queryOne('SELECT id, product_code, name FROM products WHERE id = ?', [(int)$license['product_id']]);

        // 解密授权码明文（数据库中授权码以明文存，此处直接用）
        $licenseKey = (string)$license['license_key'];

        // 授权码本身到期优先（离线授权不晚于正式授权到期）
        $licenseExpire = $license['expire_at'] !== null ? strtotime((string)$license['expire_at']) : null;
        if ($expireAt !== null && $expireAt !== '') {
            $expTs = strtotime($expireAt);
            if ($expTs === false) {
                return [false, '到期时间格式错误'];
            }
            if ($licenseExpire !== null && $expTs > $licenseExpire) {
                return [false, '离线授权到期时间不能晚于授权码到期时间'];
            }
            $finalExpire = $expTs;
        } else {
            $finalExpire = $licenseExpire;
        }

        $issuedAt = time();
        $activatePayload = [
            'type'          => 'dcai_offline_activation',
            'version'       => 1,
            'product_code'  => (string)($product['product_code'] ?? ''),
            'product_name'  => (string)($product['name'] ?? ''),
            'license_key'   => $licenseKey,
            'machine_code'  => $machineCode,
            'machine_name'  => mb_substr($machineName, 0, 255),
            'issued_at'     => date('Y-m-d H:i:s', $issuedAt),
            'expire_at'     => $finalExpire !== null ? date('Y-m-d H:i:s', $finalExpire) : null,
        ];

        // RSA 签名（复用验证令牌签名机制，私钥签名公钥验证）
        $signature = DCAI_Signature::signVerificationToken($activatePayload);
        $activateFile = $activatePayload;
        $activateFile['signature'] = $signature;

        // 落库
        $requestId = DCAI_Util::uuid();
        $db->insert('offline_activations', [
            'license_id'     => $licenseId,
            'product_id'     => (int)($product['id'] ?? 0),
            'machine_code'   => $machineCode,
            'machine_name'   => mb_substr($machineName, 0, 255),
            'request_id'     => $requestId,
            'expire_at'      => $finalExpire !== null ? date('Y-m-d H:i:s', $finalExpire) : null,
            'activate_file'  => json_encode($activateFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'         => 1,
            'created_by'     => (int)($_SESSION['dcai_admin_id'] ?? 0),
            'created_at'     => dcai_now(),
            'updated_at'     => dcai_now(),
        ]);

        return [true, [
            'activation' => $activateFile,
            'file_json'  => json_encode($activateFile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]];
    }

    /**
     * 服务端校验激活文件的正确性（后台「校验激活文件」或客户端通过 API 校验用）
     * 校验项：签名（RSA 私钥签发）→ 授权码状态 → 到期时间 → 记录未作废 → 机器绑定关系
     * @return array [bool, string]
     */
    public static function verifyActivationFile(string $rawOrJson): array
    {
        $data = is_array($rawOrJson) ? $rawOrJson : json_decode((string)$rawOrJson, true);
        if (!is_array($data)) {
            return [false, '文件格式错误'];
        }
        if (($data['type'] ?? '') !== 'dcai_offline_activation') {
            return [false, '不是有效的离线激活文件'];
        }
        $signature = (string)($data['signature'] ?? '');
        if ($signature === '') {
            return [false, '缺少签名'];
        }
        // 1. RSA 验签（返回签名覆盖的 payload）
        $decoded = DCAI_Signature::verifyVerificationToken($signature, dcai_config('security.rsa_public_key', ''));
        if ($decoded === null) {
            return [false, '签名验证失败'];
        }

        // 2. 【关键】一致性校验：文件外层字段必须与签名内 payload 完全一致，
        //    防止攻击者保留合法签名、篡改外层字段（expire_at/machine_code/license_key 等）。
        //    序列化方式必须与签发端 signVerificationToken 内部 json_encode(payload, JSON_UNESCAPED_UNICODE) 完全一致。
        $outer = $data;
        unset($outer['signature']);
        $outerJson = json_encode($outer, JSON_UNESCAPED_UNICODE);
        $decodedJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        if ($outerJson !== $decodedJson) {
            return [false, '激活文件内容与签名不符（已被篡改）'];
        }
        // 一致性通过后，以签名内 payload 为准继续校验
        $payload = $decoded;

        // 3. 到期校验（签名内 expire_at；null=随授权码）
        $expireAt = $payload['expire_at'] ?? null;
        if (!empty($expireAt)) {
            $ts = strtotime((string)$expireAt);
            if ($ts !== false && $ts < time()) {
                return [false, '激活文件已到期'];
            }
        }

        // 3. 授权码状态校验（签名内 license_key）
        $licenseKey = (string)($payload['license_key'] ?? '');
        if ($licenseKey === '') {
            return [false, '激活文件缺少授权码'];
        }
        $license = dcai_db()->queryOne('SELECT * FROM licenses WHERE license_key = ?', [$licenseKey]);
        if (!$license || (int)$license['status'] !== 1) {
            return [false, '授权码不存在或已禁用'];
        }

        // 4. 机器绑定校验：仅当授权码开启机器限制（machine_limit>0）时，激活文件必须属于绑定内的机器
        //    （防止跨机器复制激活文件）；machine_limit=0 时不强制绑定关系（兼容不限机器的历史行为）
        $machineCode = strtolower(trim((string)($payload['machine_code'] ?? '')));
        $machineLimit = (int)($license['machine_limit'] ?? 0);
        if ($machineCode !== '' && $machineLimit > 0) {
            $bound = (int)dcai_db()->queryValue(
                'SELECT COUNT(*) FROM machine_bindings WHERE license_id = ? AND machine_code = ? AND status = 1',
                [(int)$license['id'], $machineCode]
            );
            if ($bound === 0) {
                return [false, '激活文件绑定的机器不在该授权码的绑定列表内'];
            }
        }

        // 5. 记录未作废（offline_activations.status=1）
        if (!empty($payload['issued_at'])) {
            $rec = dcai_db()->queryOne(
                'SELECT id, status FROM offline_activations WHERE license_id = ? AND machine_code = ? ORDER BY id DESC LIMIT 1',
                [(int)$license['id'], $machineCode]
            );
            if ($rec && (int)$rec['status'] !== 1) {
                return [false, '该激活文件已被作废'];
            }
        }

        return [true, '激活文件有效，授权至 ' . ($expireAt ?: '随授权码到期')];
    }

    /**
     * 后台列表查询（支持条件过滤）
     */
    public static function list(array $where = [], int $page = 1, int $per = 20): array
    {
        $sql = 'SELECT o.*, l.license_key FROM offline_activations o LEFT JOIN licenses l ON l.id = o.license_id';
        $params = [];
        if (!empty($where['license_id'])) {
            $sql .= ' WHERE o.license_id = ?';
            $params[] = (int)$where['license_id'];
        }
        if (!empty($where['machine_code'])) {
            $sql .= (strpos($sql, 'WHERE') !== false ? ' AND' : ' WHERE') . ' o.machine_code = ?';
            $params[] = (string)$where['machine_code'];
        }
        if (!empty($where['status']) && in_array((int)$where['status'], [0, 1], true)) {
            $sql .= (strpos($sql, 'WHERE') !== false ? ' AND' : ' WHERE') . ' o.status = ?';
            $params[] = (int)$where['status'];
        }
        $sql .= ' ORDER BY o.id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per);
        return dcai_db()->query($sql, $params);
    }

    /**
     * 作废一条激活记录
     */
    public static function revoke(int $id): bool
    {
        return (bool)dcai_db()->update(
            'offline_activations',
            ['status' => 0, 'updated_at' => dcai_now()],
            'id = ?',
            [$id]
        );
    }
}
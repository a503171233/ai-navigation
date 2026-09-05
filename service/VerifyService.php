<?php
/**
 * 三合一授权验证服务
 * 校验：授权码(存在/有效/未过期/产品匹配) + 域名白名单 + IP 白名单 + 实例数上限
 */
class DCAI_VerifyService
{
    /**
     * 三合一基础校验（授权码 + 域名 + IP）
     * 供 verify() 与 InstanceService::register() 复用，避免两处重复逻辑漂移
     * 注意：IP 非空要求由调用方按场景决定（verify 要求四参齐全，register 允许空 IP + 空白名单）
     * @return array{0:int|null,1:?array,2:?array} [错误码(null=通过), 产品, 授权码]
     */
    public static function validateBasics(string $productCode, string $licenseKey, string $domain, string $ip): array
    {
        if ($productCode === '' || $licenseKey === '' || $domain === '') {
            return [1001, null, null];
        }
        // 1. 产品存在且上架
        $product = dcai_db()->queryOne('SELECT * FROM products WHERE product_code = ?', [$productCode]);
        if (!$product || (int)$product['status'] !== 1) {
            return [2009, null, null];
        }
        // 2. 授权码校验（含试用：trial 授权码在试用期视同有效）
        [$err, $license] = DCAI_LicenseService::validate($licenseKey, (int)$product['id']);
        if ($err !== null) {
            return [$err, $product, null];
        }
        // 3. 域名校验
        $allowedDomains = DCAI_Util::parseLines($license['allowed_domains']);
        if (!empty($allowedDomains) && !DCAI_Util::domainAllowed($domain, $allowedDomains)) {
            return [2005, $product, $license];
        }
        // 4. IP 校验
        $allowedIps = DCAI_Util::parseLines($license['allowed_ips']);
        if (!empty($allowedIps) && !DCAI_Util::ipAllowed($ip, $allowedIps)) {
            return [2006, $product, $license];
        }
        return [null, $product, $license];
    }

    /**
     * 机器码校验（客户端上报机器指纹时执行）
     * 规则：授权码 machine_limit>0 时必须绑定；未达上限自动绑定；已绑定放行
     * 统一走 DCAI_MachineService::authorizeMachine()（含格式强校验 + 事务防超配）
     * @return int|null 错误码(null=通过, 2011=未绑定/格式非法, 2012=绑定数超限)
     */
    private static function validateMachine(array $req, array $license): ?int
    {
        $machineCode = trim((string)($req['machine_code'] ?? ''));
        if ($machineCode === '') {
            return null; // 未上报机器码：不强制（Web 场景）
        }
        return DCAI_MachineService::authorizeMachine(
            (int)$license['id'],
            $machineCode,
            (string)($req['machine_name'] ?? '')
        );
    }

    /**
     * 读取授权码试用状态
     * @return array|null ['is_trial'=>bool,'trial_days'=>int,'trial_expire_at'=>?string,'trial_remaining_days'=>int]
     */
    public static function trialStatus(array $license): ?array
    {
        $trialDays = (int)($license['trial_days'] ?? 0);
        if ($trialDays <= 0) {
            return null;
        }
        $createdAt = strtotime((string)($license['created_at'] ?? ''));
        if ($createdAt === false) {
            $createdAt = time();
        }
        $trialExpire = $createdAt + $trialDays * 86400;
        $remaining = (int)ceil(($trialExpire - time()) / 86400);
        return [
            'is_trial'            => true,
            'trial_days'          => $trialDays,
            'trial_expire_at'     => date('Y-m-d H:i:s', $trialExpire),
            'trial_remaining_days'=> max(0, $remaining),
        ];
    }

    /**
     * 执行三合一验证
     * @param array $req 请求体: product_code/license_key/domain/ip/client_version
     * @return array{0:int|null,1:?array} [错误码(null=通过), 结果数组]
     */
    public static function verify(array $req): array
    {
        $productCode = trim((string)($req['product_code'] ?? ''));
        $licenseKey  = trim((string)($req['license_key'] ?? ''));
        $domain      = strtolower(trim((string)($req['domain'] ?? '')));
        $ip          = trim((string)($req['ip'] ?? ''));

        // verify 要求四参齐全（IP 为必填，与 register 的宽松契约不同）
        if ($ip === '') {
            return [1001, null];
        }

        [$err, $product, $license] = self::validateBasics($productCode, $licenseKey, $domain, $ip);
        if ($err !== null) {
            if ($err !== 1001) {
                self::logVerify($product, $license['id'] ?? null, null, $domain, $ip, $licenseKey, false, $err);
            }
            return [$err, null];
        }

        // 4.5 机器码校验（若客户端上报指纹）
        $machineErr = self::validateMachine($req, $license);
        if ($machineErr !== null) {
            self::logVerify($product, $license['id'], null, $domain, $ip, $licenseKey, false, $machineErr);
            return [$machineErr, null];
        }

        // 5. 实例数上限校验
        // 幂等规则：同产品+同域名已有活跃实例时，视为同一部署，不消耗额外名额
        // 与 InstanceService::register() 的幂等复用逻辑保持一致
        $existingInstance = dcai_db()->queryOne(
            'SELECT id FROM instances WHERE product_id = ? AND domain = ? AND status = 1',
            [(int)$product['id'], $domain]
        );
        if (!$existingInstance && !DCAI_LicenseService::instanceCountOk((int)$license['id'], (int)$license['max_instances'])) {
            self::logVerify($product, $license['id'], null, $domain, $ip, $licenseKey, false, 2007);
            return [2007, null];
        }

        // 6. 通过：签发验证令牌
        $ttl = (int)($product['verify_ttl'] > 0 ? $product['verify_ttl'] : dcai_config('security.verify_ttl', 3600));
        $iat = time();
        $expireAt = $iat + $ttl;
        $payload = [
            'product_code' => $productCode,
            'license_hash' => hash('sha256', $licenseKey),
            'domain'       => $domain,
            'ip'           => $ip,
            'iat'          => $iat,
            'expire_at'    => $expireAt,
        ];
        $token = DCAI_Signature::signVerificationToken($payload);

        $verifyResult = [
            'verified'   => true,
            'token'      => $token,
            'expire_at'  => date('Y-m-d H:i:s', $expireAt),
            'server_time' => dcai_now(),
            // 服务端产品级策略：客户端据此覆盖本地 fail_open/enforce_auth 配置
            'fail_open'  => (int)$product['fail_open'] === 1,
            'enforce_auth' => (int)$product['enforce_auth'] === 1,
            // 试用状态（仅试用授权码返回）
            'trial'      => self::trialStatus($license),
            // 机器码校验结果（客户端可据此展示绑定状态）
            'machine'    => [
                'code'   => trim((string)($req['machine_code'] ?? '')),
                'limit'  => (int)($license['machine_limit'] ?? 0),
                'bound'  => trim((string)($req['machine_code'] ?? '')) !== '',
            ],
        ];
        self::logVerify($product, $license['id'], null, $domain, $ip, $licenseKey, true, 0);
        return [null, $verifyResult];
    }

    private static function logVerify(array $product, ?int $licenseId, ?int $instanceId, string $domain, string $ip, string $licenseKey, bool $result, int $reasonCode): void
    {
        try {
            dcai_db()->insert('verify_logs', [
                'product_id'       => $product['id'] ?? null,
                'license_id'       => $licenseId,
                'instance_id'      => $instanceId,
                'domain'           => substr($domain, 0, 191),
                'ip'               => $ip,
                'license_key_masked' => DCAI_Util::maskLicenseKey($licenseKey),
                'result'           => $result ? 1 : 0,
                'reason'           => $result ? '' : self::reasonText($reasonCode),
                'created_at'       => dcai_now(),
            ]);
        } catch (Throwable $e) {
            dcai_log('error', '写入验证日志失败', ['err' => $e->getMessage()]);
        }
    }

    public static function reasonText(int $code): string
    {
        $map = [
            1001 => '参数缺失或格式错误',
            2001 => '授权码不存在',
            2002 => '授权码已禁用',
            2003 => '授权码已过期',
            2004 => '授权码与产品不匹配',
            2005 => '域名未授权',
            2006 => 'IP 未授权',
            2007 => '实例数超出上限',
            2008 => '实例已被远程禁用',
            2009 => '产品未上架或不存在',
            2010 => '缺少机器码',
            2011 => '机器未绑定',
            2012 => '绑定机器数超出上限',
        ];
        return $map[$code] ?? '未知原因';
    }
}

<?php
/**
 * 商城服务：买家注册/登录、下单、支付（易支付/人工）、自动发码、授权自助绑定
 * 对外授权商城 + 内部授权中台双模式共用
 */

class DCAI_Store
{
    // ============================================================
    // 买家（客户）
    // ============================================================

    /**
     * 注册买家（邮箱+密码）
     */
    public static function buyerRegister(string $email, string $password, string $nickname = ''): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, '邮箱格式不正确'];
        }
        if (strlen($password) < 6) {
            return [false, '密码至少 6 位'];
        }
        $db = dcai_db();
        if ($db->queryValue('SELECT COUNT(*) FROM buyers WHERE username = ?', [$email])) {
            return [false, '该邮箱已注册，请直接登录'];
        }
        $now = dcai_now();
        $id = $db->insert('buyers', [
            'username'      => $email,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'nickname'      => $nickname !== '' ? $nickname : $email,
            'contact'       => '',
            'status'        => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        return [true, $db->queryOne('SELECT * FROM buyers WHERE id = ?', [$id])];
    }

    /**
     * 买家登录校验
     */
    public static function buyerLogin(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $buyer = dcai_db()->queryOne('SELECT * FROM buyers WHERE username = ?', [$email]);
        if (!$buyer || (int)$buyer['status'] !== 1 || !password_verify($password, $buyer['password_hash'])) {
            return [false, '账号或密码错误'];
        }
        dcai_db()->update('buyers', ['last_login_at' => dcai_now(), 'updated_at' => dcai_now()], 'id = ?', [(int)$buyer['id']]);
        return [true, $buyer];
    }

    // ---------- 买家登录失败锁定（防撞库/爆破，与后台登录同策略） ----------

    /**
     * 查询登录锁定状态
     * @return array [bool 是否允许继续尝试, string 提示]
     */
    public static function loginLockState(string $email, string $ip): array
    {
        $max = (int)dcai_config('security.login_max_fail', 5);
        $lockMinutes = (int)dcai_config('security.login_lock_minutes', 15);
        $key = 'buyer_fail_' . md5(strtolower($email) . '|' . $ip);
        $until = (int)DCAI_Settings::get($key . '_until', 0);
        if ($until > time()) {
            $left = (int)ceil(($until - time()) / 60);
            return [false, "登录失败次数过多，请 {$left} 分钟后再试"];
        }
        if ($until > 0 && $until <= time()) {
            DCAI_Settings::set($key, 0);
            DCAI_Settings::set($key . '_until', 0);
        }
        if ((int)DCAI_Settings::get($key, 0) >= $max) {
            DCAI_Settings::set($key . '_until', time() + $lockMinutes * 60);
            return [false, "登录失败次数过多，已锁定 {$lockMinutes} 分钟"];
        }
        return [true, ''];
    }

    /**
     * 记录一次登录失败；达到阈值即锁定
     */
    public static function recordLoginFail(string $email, string $ip): void
    {
        $max = (int)dcai_config('security.login_max_fail', 5);
        $key = 'buyer_fail_' . md5(strtolower($email) . '|' . $ip);
        $fail = (int)DCAI_Settings::get($key, 0) + 1;
        DCAI_Settings::set($key, $fail);
        if ($fail >= $max) {
            DCAI_Settings::set($key . '_until', time() + (int)dcai_config('security.login_lock_minutes', 15) * 60);
        }
    }

    /**
     * 登录成功后清零失败计数
     */
    public static function clearLoginFail(string $email, string $ip): void
    {
        $key = 'buyer_fail_' . md5(strtolower($email) . '|' . $ip);
        DCAI_Settings::set($key, 0);
        DCAI_Settings::set($key . '_until', 0);
    }

    // ============================================================
    // 试用模式（Trial）
    // ============================================================

    /**
     * 自助申请试用授权码
     * 规则：产品需开启 trial_enabled=1；同一买家同一产品仅可申请一次；
     *       试用授权码 trial_days=N，到期时间 = 当前时间 + N 天（source=3 标记试用）
     * @return array [bool, 授权码记录|错误信息]
     */
    public static function applyTrial(int $productId, int $buyerId): array
    {
        $db = dcai_db();
        $product = $db->queryOne('SELECT * FROM products WHERE id = ?', [$productId]);
        if (!$product) {
            return [false, '产品不存在'];
        }
        if ((int)$product['trial_enabled'] !== 1) {
            return [false, '该产品未开放试用'];
        }
        $trialDays = (int)($product['trial_days'] ?? 0);
        if ($trialDays <= 0) {
            return [false, '该产品未配置试用天数'];
        }
        // 每人限一次（含已过期的历史试用）
        $exists = (int)$db->queryValue(
            'SELECT COUNT(*) FROM licenses WHERE buyer_id = ? AND product_id = ? AND source = 3',
            [$buyerId, $productId]
        );
        if ($exists > 0) {
            return [false, '您已申请过该产品试用，请购买正式授权'];
        }
        $now = dcai_now();
        $expireAt = date('Y-m-d H:i:s', time() + $trialDays * 86400);
        [$ok, $lid] = DCAI_LicenseService::create([
            'product_id'     => $productId,
            'customer_name'  => '试用-' . (string)$db->queryValue('SELECT nickname FROM buyers WHERE id = ?', [$buyerId]),
            'customer_email' => (string)$db->queryValue('SELECT email FROM buyers WHERE id = ?', [$buyerId]),
            'max_instances'  => 1,
            'machine_limit'  => 1,
            'trial_days'     => $trialDays,
            'expire_at'      => $expireAt,
            'remark'         => '自助试用 ' . $trialDays . ' 天',
            'status'         => 1,
            'source'         => 3, // 3=试用
            'buyer_id'       => $buyerId,
        ]);
        if (!$ok) {
            return [false, $lid];
        }
        return [true, $db->queryOne('SELECT * FROM licenses WHERE id = ?', [(int)$lid])];
    }

    /**
     * 买家会话：当前登录买家
     */
    public static function currentBuyer(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('DCAI_STORE');
            session_set_cookie_params([
                'lifetime' => 86400 * 7,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        $id = $_SESSION['dcai_buyer_id'] ?? null;
        if ($id === null) {
            return null;
        }
        return dcai_db()->queryOne('SELECT * FROM buyers WHERE id = ? AND status = 1', [(int)$id]);
    }

    // ============================================================
    // 下单与支付
    // ============================================================

    /**
     * 创建订单
     * @param int $productId 产品
     * @param int $buyerId 买家
     * @param string $channel manual/epay/alipay/wxpay
     * @param string $note 客户备注
     * @param int $qty 数量
     */
    public static function createOrder(int $productId, int $buyerId, string $channel = 'manual', string $note = '', int $qty = 1): array
    {
        $db = dcai_db();
        $product = $db->queryOne('SELECT * FROM products WHERE id = ?', [$productId]);
        if (!$product) {
            return [false, '产品不存在'];
        }
        if ((int)$product['for_sale'] !== 1 || (float)$product['sale_price'] <= 0) {
            return [false, '该产品未在商城销售'];
        }
        $price = (float)$product['sale_price'];
        $unit = $product['price_unit'] ?: 'year';
        $durationMap = [
            'month'      => 30,
            'quarter'    => 90,
            'half_year'  => 180,
            'year'       => 365,
            'perpetual'  => 0,
        ];
        $durationDays = $durationMap[$unit] ?? 365;
        $orderNo = date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
        $now = dcai_now();
        $amount = round($price * $qty, 2);
        $orderId = $db->insert('orders', [
            'order_no'      => $orderNo,
            'buyer_id'      => $buyerId,
            'product_id'    => $productId,
            'product_code'  => $product['product_code'],
            'product_name'  => $product['name'],
            'qty'           => $qty,
            'price'         => $price,
            'amount'        => $amount,
            'duration_days' => $durationDays,
            'pay_channel'   => $channel,
            'pay_status'    => 0,
            'customer_note' => mb_substr($note, 0, 250),
            'ship_status'   => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        if (!$orderId) {
            return [false, '订单创建失败'];
        }
        return [true, ['order_id' => $orderId, 'order_no' => $orderNo, 'amount' => $amount]];
    }

    /**
     * 订单支付成功处理：更新状态 + 自动发码
     * 幂等：已发货订单直接返回
     * 续费逻辑：买家已有同产品有效授权码时，在原到期时间上追加时长（不新建授权码）
     */
    public static function paySuccess(int $orderId, string $tradeNo = '', string $channel = ''): array
    {
        $db = dcai_db();
        $order = $db->queryOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$order) {
            return [false, '订单不存在'];
        }
        // 幂等：已支付已发货
        if ((int)$order['pay_status'] === 1 && (int)$order['ship_status'] === 1 && $order['license_id']) {
            return [true, ['order' => $order, 'license' => $db->queryOne('SELECT * FROM licenses WHERE id = ?', [(int)$order['license_id']])]];
        }
        $now = dcai_now();
        $db->beginTransaction();
        try {
            $db->update('orders', [
                'pay_status'   => 1,
                'paid_at'      => $now,
                'pay_trade_no' => $tradeNo !== '' ? $tradeNo : $order['pay_trade_no'],
                'pay_channel'  => $channel !== '' ? $channel : $order['pay_channel'],
                'updated_at'   => $now,
            ], 'id = ? AND pay_status = 0', [$orderId]);

            // 计算授权到期时间（0=永久 → expire_at=NULL）
            $expireAt = null;
            if ((int)$order['duration_days'] > 0) {
                $expireAt = date('Y-m-d H:i:s', strtotime("+{$order['duration_days']} days"));
            }

            // 续费检测：买家已有同产品有效授权码
            $existing = $db->queryOne(
                'SELECT * FROM licenses WHERE buyer_id = ? AND product_id = ? AND status = 1 AND source IN (1,2) ORDER BY id DESC LIMIT 1',
                [(int)$order['buyer_id'], (int)$order['product_id']]
            );
            if ($existing && (int)$order['duration_days'] > 0) {
                // 追加时长
                $baseTs = strtotime((string)$existing['expire_at']);
                if ($existing['expire_at'] === null || $existing['expire_at'] === '' || $baseTs < time()) {
                    $baseTs = time();
                }
                $newExpireAt = date('Y-m-d H:i:s', $baseTs + (int)$order['duration_days'] * 86400);
                $db->update('licenses', [
                    'expire_at'  => $newExpireAt,
                    'updated_at' => $now,
                    'remark'     => trim(($existing['remark'] ?? '') . ' | 续费订单 ' . $order['order_no']),
                ], 'id = ?', [(int)$existing['id']]);
                $db->update('orders', [
                    'license_id'  => (int)$existing['id'],
                    'ship_status' => 1,
                    'ship_note'   => '续费成功，已延长至 ' . $newExpireAt,
                    'updated_at'  => $now,
                ], 'id = ?', [$orderId]);
                $db->commit();
                $order = $db->queryOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
                return [true, ['order' => $order, 'license' => $db->queryOne('SELECT * FROM licenses WHERE id = ?', [(int)$existing['id']]), 'renewed' => true]];
            }

            // 自动创建授权码
            [$ok, $lid] = DCAI_LicenseService::create([
                'product_id'     => (int)$order['product_id'],
                'customer_name'  => '订单#' . $order['order_no'],
                'customer_email' => (string)$db->queryValue('SELECT email FROM buyers WHERE id = ?', [(int)$order['buyer_id']]),
                'max_instances'  => 1,
                'expire_at'      => $expireAt,
                'remark'         => '商城订单 ' . $order['order_no'],
                'status'         => 1,
                'source'         => 1,
                'buyer_id'       => (int)$order['buyer_id'],
                'order_id'       => (int)$order['id'],
            ]);
            if (!$ok) {
                $db->rollback();
                return [false, '授权码生成失败: ' . $lid];
            }
            $db->update('orders', [
                'license_id'  => (int)$lid,
                'ship_status' => 1,
                'ship_note'   => '支付成功自动发码',
                'updated_at'  => $now,
            ], 'id = ?', [$orderId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            dcai_log('error', '订单支付成功处理失败', ['order_id' => $orderId, 'err' => $e->getMessage()]);
            return [false, '处理失败: ' . $e->getMessage()];
        }
        $order = $db->queryOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $license = $db->queryOne('SELECT * FROM licenses WHERE id = ?', [(int)$order['license_id']]);
        return [true, ['order' => $order, 'license' => $license, 'renewed' => false]];
    }

    /**
     * 取消/关闭订单
     */
    public static function cancelOrder(int $orderId, int $buyerId): bool
    {
        return (bool)dcai_db()->update(
            'orders',
            ['pay_status' => 2, 'updated_at' => dcai_now()],
            'id = ? AND buyer_id = ? AND pay_status = 0',
            [$orderId, $buyerId]
        );
    }

    /**
     * 买家续费：同产品存在有效授权码时，在现有到期时间上追加时长（永久授权不叠加）
     */
    public static function renewLicense(int $licenseId, int $buyerId, int $durationDays, string $orderNo = ''): array
    {
        $db = dcai_db();
        $license = $db->queryOne('SELECT * FROM licenses WHERE id = ? AND buyer_id = ?', [$licenseId, $buyerId]);
        if (!$license) {
            return [false, '授权码不存在或无权操作'];
        }
        if ((int)$license['status'] !== 1) {
            return [false, '授权码已禁用'];
        }
        $newExpireAt = null; // 默认永久
        if ($license['expire_at'] !== null && $license['expire_at'] !== '') {
            $baseTs = strtotime($license['expire_at']);
            // 已过期则从当前起算
            if ($baseTs < time()) {
                $baseTs = time();
            }
            $newExpireAt = date('Y-m-d H:i:s', $baseTs + $durationDays * 86400);
        } else {
            // 永久授权：续费转为"自当前起 N 天"（从永久变为限时，需谨慎，一般不允许）
            return [false, '永久授权无需续费'];
        }
        $db->update('licenses', [
            'expire_at'  => $newExpireAt,
            'updated_at' => dcai_now(),
            'remark'     => trim(($license['remark'] ?? '') . ' | 续费订单 ' . $orderNo),
        ], 'id = ?', [$licenseId]);
        return [true, $newExpireAt];
    }

    // ============================================================
    // 授权自助绑定
    // ============================================================

    /**
     * 买家查看自己的授权码列表（有效授权 + 域名绑定状态）
     */
    public static function buyerLicenses(int $buyerId): array
    {
        $db = dcai_db();
        $rows = $db->query(
            'SELECT l.*, p.name AS product_name, p.product_code
             FROM licenses l LEFT JOIN products p ON p.id = l.product_id
             WHERE l.buyer_id = ? ORDER BY l.id DESC',
            [$buyerId]
        );
        $out = [];
        foreach ($rows as $r) {
            $isPermanent = $r['expire_at'] === null || $r['expire_at'] === '';
            $out[] = [
                'id'            => (int)$r['id'],
                'license_key'   => $r['license_key'],
                'product_id'    => (int)$r['product_id'],
                'product_name'  => $r['product_name'] ?: $r['product_code'],
                'status'        => (int)$r['status'],
                'expire_at'     => $r['expire_at'],
                'is_permanent'  => $isPermanent,
                'days_left'     => $isPermanent ? null : (int)ceil((strtotime($r['expire_at']) - time()) / 86400),
                'allowed_domains' => DCAI_Util::parseLines($r['allowed_domains'] ?? ''),
                'allowed_ips'   => DCAI_Util::parseLines($r['allowed_ips'] ?? ''),
                'source'        => (int)$r['source'],
                'is_trial'      => (int)$r['source'] === 3 || (int)($r['trial_days'] ?? 0) > 0,
                'trial_days'    => (int)($r['trial_days'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * 买家自助设置授权码绑定域名/IP（仅限本人授权码）
     */
    public static function bindLicense(int $licenseId, int $buyerId, array $domains, array $ips): array
    {
        $db = dcai_db();
        $license = $db->queryOne('SELECT * FROM licenses WHERE id = ? AND buyer_id = ?', [$licenseId, $buyerId]);
        if (!$license) {
            return [false, '授权码不存在或无权操作'];
        }
        if ((int)$license['status'] !== 1) {
            return [false, '授权码已禁用'];
        }
        $cleanDomains = [];
        foreach ($domains as $d) {
            $d = strtolower(trim((string)$d));
            if ($d === '') continue;
            if (!preg_match('/^(?:\*\.)?[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)+$/', $d)) {
                return [false, "域名格式不正确: $d"];
            }
            if (count($cleanDomains) >= 5) break;
            $cleanDomains[] = $d;
        }
        $cleanIps = [];
        foreach ($ips as $ip) {
            $ip = trim((string)$ip);
            if ($ip === '') continue;
            $ipPart = explode('/', $ip, 2)[0];
            if (!filter_var($ipPart, FILTER_VALIDATE_IP)) {
                return [false, "IP 格式不正确: $ip"];
            }
            if (count($cleanIps) >= 10) break;
            $cleanIps[] = $ip;
        }
        $db->update('licenses', [
            'allowed_domains' => implode("\n", $cleanDomains),
            'allowed_ips'     => implode("\n", $cleanIps),
            'updated_at'      => dcai_now(),
        ], 'id = ?', [$licenseId]);
        return [true, null];
    }

    // ============================================================
    // 易支付 (V免签/彩虹易支付通用) 签名
    // ============================================================

    public static function epaySign(array $params, string $key): string
    {
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null || $k === 'sign') continue;
            $str .= $k . '=' . $v . '&';
        }
        return md5($str . $key);
    }

    public static function epayVerify(array $params, string $key): bool
    {
        if (empty($params['sign'])) {
            return false;
        }
        return hash_equals(self::epaySign($params, $key), (string)$params['sign']);
    }
}

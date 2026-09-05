<?php
/**
 * 通知服务：Webhook / 邮件
 * 用于：授权到期提醒、实例离线告警、登录锁定、订单通知等
 */

class DCAI_Notify
{
    public static function send(string $title, string $content, array $extra = []): bool
    {
        $sent = false;
        if ((int)dcai_config('notify.enabled', 0) === 1) {
            $hook = (string)dcai_config('notify.webhook', '');
            if ($hook !== '') {
                $sent = self::webhook($hook, $title, $content) || $sent;
            }
            $email = (string)dcai_config('notify.email', '');
            if ($email !== '') {
                $sent = self::mail($email, $title, $content) || $sent;
            }
        }
        return $sent;
    }

    public static function webhook(string $url, string $title, string $content): bool
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $payload = json_encode([
            'title'   => $title,
            'content' => $content,
            'desp'    => $content,
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || $code >= 400) {
            dcai_log('warning', 'Webhook 通知失败', ['url' => $url, 'code' => $code, 'curl' => $errno]);
            return false;
        }
        return true;
    }

    public static function mail(string $to, string $subject, string $body): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'From: ' . dcai_config('app.name', 'DCAI 授权系统') . ' <no-reply@' . (parse_url((string)dcai_config('app.base_url', ''), PHP_URL_HOST) ?: 'localhost') . '>',
        ];
        $ok = @mail($to, $subject, base64_encode($body), implode("\r\n", $headers));
        if (!$ok) {
            dcai_log('warning', '邮件通知发送失败', ['to' => $to]);
        }
        return $ok;
    }

    /**
     * 授权码到期提醒（N 天内到期，cron 每日调用一次，用 settings 去重防重复推送）
     */
    public static function licenseExpiryReminder(int $daysAhead = 7): int
    {
        if ((int)dcai_config('notify.enabled', 0) !== 1) { return 0; }
        $db = dcai_db();
        $now = dcai_now();
        $deadline = date('Y-m-d H:i:s', strtotime("+{$daysAhead} days"));
        $rows = $db->query(
            'SELECT l.id, l.license_key, l.customer_name, l.customer_email, l.expire_at, p.name AS product_name
             FROM licenses l LEFT JOIN products p ON p.id = l.product_id
             WHERE l.status = 1 AND l.expire_at IS NOT NULL
               AND l.expire_at BETWEEN ? AND ?',
            [$now, $deadline]
        );
        $sent = 0;
        foreach ($rows as $row) {
            $markKey = 'notify_expire_' . $row['id'];
            if ((int)DCAI_Settings::get($markKey, 0) === 1) { continue; }
            $days = (int)ceil((strtotime($row['expire_at']) - time()) / 86400);
            $title = '【授权到期提醒】' . $row['product_name'] . ' 授权即将到期';
            $content = "客户：{$row['customer_name']}（{$row['customer_email']}）\n"
                . '授权码：' . DCAI_LicenseService::mask($row['license_key']) . "\n"
                . "到期时间：{$row['expire_at']}（剩余 {$days} 天）\n"
                . '请及时联系客户续费。';
            if (self::send($title, $content)) {
                DCAI_Settings::set($markKey, '1');
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * 实例离线批量告警（超过阈值时每 6 小时推送一次）
     */
    public static function offlineAlert(int $threshold = 5): int
    {
        if ((int)dcai_config('notify.enabled', 0) !== 1) { return 0; }
        $offline = (int)dcai_db()->queryValue(
            "SELECT COUNT(*) FROM instances WHERE status = 0 AND last_heartbeat_at IS NOT NULL AND last_heartbeat_at < ?",
            [date('Y-m-d H:i:s', time() - 3600)]
        );
        if ($offline < $threshold) { return 0; }
        $lastKey = 'notify_offline_last';
        $lastTs = (int)DCAI_Settings::get($lastKey, 0);
        if (time() - $lastTs < 21600) { return 0; }
        $title = '【离线告警】' . $offline . ' 个实例已离线超过 1 小时';
        $content = "当前离线实例数：{$offline}\n请登录后台检查实例运行状态。";
        if (self::send($title, $content)) {
            DCAI_Settings::set($lastKey, (string)time());
            return $offline;
        }
        return 0;
    }

    public static function sendTest(): array
    {
        $ok = self::send('【测试通知】' . dcai_config('app.name', 'DCAI 授权系统'), '这是一条测试消息。如果你收到此消息，说明通知配置正常。时间：' . dcai_now());
        return [$ok, $ok ? '发送成功' : '发送失败，请检查通知配置'];
    }
}

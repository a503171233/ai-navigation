<?php
/**
 * 签名与加解密
 *  - HMAC-SHA256 请求签名 / 验签
 *  - RSA 验证令牌签发与验签
 */
class DCAI_Signature
{
    /**
     * 计算请求签名
     * sign = HMAC-SHA256(secret, timestamp + "\n" + nonce + "\n" + sha256(body))
     */
    public static function make(string $secret, int $timestamp, string $nonce, string $body): string
    {
        $payload = $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * 校验请求头签名
     * @return array{0:bool,1:string} [是否通过, 失败原因]
     */
    public static function verify(array $headers, string $secret, string $body, int $maxDiff = 300): array
    {
        $ts    = $headers['x-timestamp'] ?? '';
        $nonce = $headers['x-nonce'] ?? '';
        $sign  = $headers['x-sign'] ?? '';

        if ($ts === '' || $nonce === '' || $sign === '') {
            return [false, '缺少签名头'];
        }
        if (!ctype_digit((string)$ts)) {
            return [false, '时间戳格式错误'];
        }
        if (abs(time() - (int)$ts) > $maxDiff) {
            return [false, '时间戳已过期'];
        }
        $expected = self::make($secret, (int)$ts, $nonce, $body);
        if (!hash_equals($expected, $sign)) {
            return [false, '签名不一致'];
        }
        return [true, ''];
    }

    /**
     * 使用 RSA 私钥签发验证令牌（SHA256 签名）
     * 载荷: base64url(JSON)
     */
    public static function signVerificationToken(array $payload): string
    {
        $privateKey = dcai_config('security.rsa_private_key', '');
        $pkey = openssl_pkey_get_private($privateKey);
        if (!$pkey) {
            throw new RuntimeException('RSA 私钥无效');
        }
        $encoded = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $signature = '';
        openssl_sign($encoded, $signature, $pkey, OPENSSL_ALGO_SHA256);
        return $encoded . '.' . self::base64UrlEncode($signature);
    }

    /**
     * 使用 RSA 公钥验证令牌，返回载荷数组；失败返回 null
     */
    public static function verifyVerificationToken(string $token, ?string $publicKey = null): ?array
    {
        $publicKey = $publicKey ?? dcai_config('security.rsa_public_key', '');
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $sigB64] = $parts;
        $pkey = openssl_pkey_get_public($publicKey);
        if (!$pkey) {
            return null;
        }
        $ok = openssl_verify($encoded, self::base64UrlDecode($sigB64), $pkey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return null;
        }
        $json = self::base64UrlDecode($encoded);
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

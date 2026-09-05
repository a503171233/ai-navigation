<?php
/**
 * RFC 6238 TOTP (Google Authenticator 兼容) 纯 PHP 实现
 * - Base32 编解码
 * - 密钥生成
 * - 6 位动态码生成与校验（默认 30s 窗口，允许 ±1 窗口容差）
 */

class DCAI_Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * 生成随机 Base32 密钥（默认 20 字节 = 160bit，兼容 Google Authenticator）
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * 生成 otpauth:// URI（用于二维码）
     */
    public static function otpauthUri(string $secret, string $issuer, string $account): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $iss = rawurlencode($issuer);
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$iss}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * 计算某时间戳对应的 TOTP 码（默认当前时间）
     */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $counter = pack('N*', 0) . pack('N*', intdiv($timestamp, 30));
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        $otp = $binary % 1000000;
        return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 校验用户输入的动态码，容忍 ±1 时间窗口
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if ($secret === '' || $code === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $now + $i * 30), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Base32 编码（RFC 4648，无填充）
     */
    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        $len = strlen($binary);
        for ($i = 0; $i < $len; $i += 5) {
            $chunk = substr($binary, $i, 5);
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    /**
     * Base32 解码
     */
    public static function base32Decode(string $data): string
    {
        $data = strtoupper(preg_replace('/[^A-Z2-7]/', '', $data) ?? '');
        if ($data === '') {
            return '';
        }
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(strpos(self::BASE32_ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        $len = strlen($binary);
        for ($i = 0; $i + 8 <= $len; $i += 8) {
            $out .= chr(bindec(substr($binary, $i, 8)));
        }
        return $out;
    }
}

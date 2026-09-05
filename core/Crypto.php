<?php
/**
 * AES-256-CBC 加解密（instance_token 等敏感数据加密存储）
 */
class DCAI_Crypto
{
    private const CIPHER = 'aes-256-cbc';

    public static function key(): string
    {
        return dcai_config('security.aes_key', '');
    }

    public static function encrypt(string $plaintext, ?string $key = null): string
    {
        $key = $key ?? self::key();
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }

    public static function decrypt(string $payload, ?string $key = null): string
    {
        $key = $key ?? self::key();
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $plain = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    public static function generateKey(int $bytes = 32): string
    {
        return random_bytes($bytes);
    }
}

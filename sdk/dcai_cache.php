<?php
/**
 * DCAI SDK 本地文件缓存
 * 缓存项：授权令牌 / 实例凭证 / 弹窗展示记录 / 心跳时间戳
 */
class DCAI_Cache
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $key) . '.cache';
    }

    public function get(string $key, $default = null)
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            return $default;
        }
        if (isset($data['expire_at']) && $data['expire_at'] !== 0 && time() > (int)$data['expire_at']) {
            @unlink($file);
            return $default;
        }
        return array_key_exists('value', $data) ? $data['value'] : $default;
    }

    /**
     * @param int $ttl 有效期秒数，0 表示长期
     */
    public function set(string $key, $value, int $ttl = 0): void
    {
        $data = [
            'value'     => $value,
            'expire_at' => $ttl > 0 ? time() + $ttl : 0,
            'saved_at'  => time(),
        ];
        @file_put_contents($this->file($key), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public function delete(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__MISS__') !== '__MISS__';
    }
}

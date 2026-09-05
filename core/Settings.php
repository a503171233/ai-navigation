<?php
/**
 * 系统设置读写 (settings 表)
 */
class DCAI_Settings
{
    private static array $cache = [];

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $row = dcai_db()->queryOne('SELECT svalue FROM settings WHERE skey = ?', [$key]);
        $value = $row === null ? $default : $row['svalue'];
        self::$cache[$key] = $value;
        return $value;
    }

    public static function set(string $key, $value): void
    {
        $now = dcai_now();
        // 原子 UPSERT：避免并发下先查后插触发的唯一键冲突
        $stmt = dcai_db()->pdo()->prepare(
            'INSERT INTO settings (skey, svalue, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$key, (string)$value, $now]);
        self::$cache[$key] = (string)$value;
        // 使 dcai_config 的跨请求设置缓存立即失效，保证后台修改即时生效
        $cacheFile = rtrim((string)dcai_config('storage.path', DCAI_ROOT . '/storage'), '/\\') . '/cache/config_overrides.json';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    public static function all(): array
    {
        $rows = dcai_db()->query('SELECT skey, svalue FROM settings');
        $out = [];
        foreach ($rows as $row) {
            $out[$row['skey']] = $row['svalue'];
        }
        return $out;
    }
}

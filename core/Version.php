<?php
/**
 * 版本号比较（x.y.z[.w]，最多 4 段）
 */
class DCAI_Version
{
    /**
     * 解析版本号为可比较数组（最多 4 段，不足补 0）
     */
    public static function parse(string $version): array
    {
        $parts = explode('.', trim($version));
        $nums = [];
        foreach ($parts as $p) {
            $nums[] = (int)filter_var($p, FILTER_SANITIZE_NUMBER_INT);
        }
        while (count($nums) < 4) {
            $nums[] = 0;
        }
        return $nums;
    }

    /**
     * $a > $b ? 1 : ($a == $b ? 0 : -1)
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);
        for ($i = 0; $i < 4; $i++) {
            if ($pa[$i] > $pb[$i]) {
                return 1;
            }
            if ($pa[$i] < $pb[$i]) {
                return -1;
            }
        }
        return 0;
    }

    public static function gt(string $a, string $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    public static function gte(string $a, string $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    public static function lt(string $a, string $b): bool
    {
        return self::compare($a, $b) < 0;
    }

    public static function lte(string $a, string $b): bool
    {
        return self::compare($a, $b) <= 0;
    }

    public static function valid(string $version): bool
    {
        $version = trim($version);
        if ($version === '') {
            return false;
        }
        return (bool)preg_match('/^\d+(\.\d+){0,3}$/', $version);
    }
}

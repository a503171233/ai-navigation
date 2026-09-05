<?php
/**
 * 通用工具函数
 */
class DCAI_Util
{
    /**
     * 读取 JSON 请求体
     */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 生成 UUID v4
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 生成授权码: DCAI- 开头，5 组 4 位 base32 子集字符
     */
    public static function generateLicenseKey(): string
    {
        $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 5; $g++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= $charset[random_int(0, strlen($charset) - 1)];
            }
            $groups[] = $part;
        }
        return 'DCAI-' . implode('-', $groups);
    }

    /**
     * 授权码掩码（前6后4）
     */
    public static function maskLicenseKey(string $key): string
    {
        if (strlen($key) <= 12) {
            return $key;
        }
        return substr($key, 0, 6) . str_repeat('*', max(4, strlen($key) - 10)) . substr($key, -4);
    }

    /**
     * 域名匹配: 支持精确域名与 *.example.com 通配符（example.com 同时匹配自身与子域名）
     */
    public static function domainAllowed(string $domain, array $rules): bool
    {
        $domain = strtolower(trim($domain, '.'));
        foreach ($rules as $rule) {
            $rule = strtolower(trim($rule));
            if ($rule === '') {
                continue;
            }
            if ($rule === '*') {
                return true;
            }
            if (strpos($rule, '*.') === 0) {
                $base = substr($rule, 2);
                if ($domain === $base || substr($domain, -strlen($base) - 1) === '.' . $base) {
                    return true;
                }
            } elseif ($domain === $rule) {
                return true;
            }
        }
        return false;
    }

    /**
     * IP 匹配: 精确 IP 与 CIDR 网段
     */
    public static function ipAllowed(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (strpos($rule, '/') !== false) {
                [$subnet, $prefix] = array_pad(explode('/', $rule, 2), 2, null);
                if (self::ipInCidr($ip, $subnet, (int)$prefix)) {
                    return true;
                }
            } elseif ($ip === $rule) {
                return true;
            }
        }
        return false;
    }

    public static function ipInCidr(string $ip, string $subnet, int $prefix): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($prefix < 0 || $prefix > 32) {
                return false;
            }
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
            return (($ipLong & $mask) === ($subnetLong & $mask));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($prefix < 0 || $prefix > 128) {
                return false;
            }
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $maskBin = str_repeat("\xFF", intdiv($prefix, 8));
            $remain = $prefix % 8;
            if ($remain > 0) {
                $maskBin .= chr(0xFF << (8 - $remain));
            }
            $maskBin = str_pad($maskBin, 16, "\x00");
            return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
        }
        return false;
    }

    /**
     * 解析按行分隔的规则列表
     */
    public static function parseLines(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        $lines = preg_split('/[\r\n]+/', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * HTML 输出编码
     */
    public static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 简易 HTML 白名单过滤（弹窗/模块内容渲染前使用）
     */
    public static function sanitizeHtml(string $html): string
    {
        $allowed = '<p><br><b><strong><i><em><u><h1><h2><h3><h4><ul><ol><li><a><img><span><div><blockquote><code><pre><table><thead><tbody><tr><td><th>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/javascript\s*:/i', '', $html);
        $html = preg_replace('/on\w+\s*=\s*(["\'])[^"\']*\1/i', '', $html);
        return $html;
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    public static function paginateParams(): array
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = min(100, max(10, (int)($_GET['per'] ?? 20)));
        return [$page, $per];
    }

    /**
     * 递归删除目录及所有子文件
     */
    public static function rmRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . '/*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                self::rmRecursive($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }

    public static function paginationHtml(int $total, int $page, int $per, string $baseUrl = ''): string
    {
        $pages = (int)ceil($total / $per);
        if ($pages <= 1) {
            return '<span class="pagination-info">共 ' . $total . ' 条</span>';
        }
        $query = $_GET;
        $html = '<div class="pagination-wrap"><span class="pagination-info">共 ' . $total . ' 条 / ' . $pages . ' 页</span>';
        for ($i = 1; $i <= $pages; $i++) {
            if ($pages > 10 && $i > 2 && $i < $pages - 1 && abs($i - $page) > 2) {
                if ($i === 3 || $i === $pages - 2) {
                    $html .= '<span class="page-dots">…</span>';
                }
                continue;
            }
            $query['page'] = $i;
            $url = $baseUrl !== '' ? $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . http_build_query($query) : '?' . http_build_query($query);
            $cls = $i === $page ? 'page-link active' : 'page-link';
            $html .= '<a class="' . $cls . '" href="' . self::e($url) . '">' . $i . '</a>';
        }
        $html .= '</div>';
        return $html;
    }
}

<?php
/**
 * 后台 CSRF 防护
 * 依赖 PHP 会话（$_SESSION['dcai_csrf']）
 */
class DCAI_Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['dcai_csrf'])) {
            $_SESSION['dcai_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['dcai_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verify(?string $token = null): bool
    {
        $token = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return $token !== '' && hash_equals($_SESSION['dcai_csrf'] ?? '', (string)$token);
    }

    public static function check(): void
    {
        if (!self::verify()) {
            http_response_code(400);
            exit('CSRF 校验失败，请刷新页面重试');
        }
    }
}

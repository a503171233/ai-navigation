<?php
/**
 * 后台管理辅助：会话、登录校验、权限点、操作日志
 */
class DCAI_Admin
{
    // 权限点定义
    public const PERMS = [
        'product'  => '产品管理',
        'package'  => '安装包管理',
        'license'  => '授权码管理',
        'machine'  => '机器绑定',
        'offline'  => '离线激活',
        'instance' => '实例管理',
        'command'  => '命令中心',
        'popup'    => '弹窗管理',
        'update'   => '更新管理',
        'sysupdate'=> '系统升级',
        'module'   => '远程模块',
        'skill'    => '技能管理',
        'order'    => '订单管理',
        'log'      => '日志管理',
        'setting'  => '系统设置',
        'admin'    => '管理员管理',
    ];

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('DCAI_ADMIN');
            session_set_cookie_params([
                'lifetime' => 7200,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function login(array $user): void
    {
        $_SESSION['dcai_admin_id'] = $user['id'];
        $_SESSION['dcai_admin_username'] = $user['username'];
        $_SESSION['dcai_admin_nickname'] = $user['nickname'];
        $_SESSION['dcai_admin_role'] = $user['role'];
    }

    public static function logout(): void
    {
        unset($_SESSION['dcai_admin_id'], $_SESSION['dcai_admin_username'], $_SESSION['dcai_admin_nickname'], $_SESSION['dcai_admin_role']);
        session_regenerate_id(true);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['dcai_admin_id']) ? (int)$_SESSION['dcai_admin_id'] : null;
    }

    public static function user(): ?array
    {
        if (self::id() === null) {
            return null;
        }
        return [
            'id'       => (int)$_SESSION['dcai_admin_id'],
            'username' => $_SESSION['dcai_admin_username'] ?? '',
            'nickname' => $_SESSION['dcai_admin_nickname'] ?? '',
            'role'     => (int)($_SESSION['dcai_admin_role'] ?? 1),
        ];
    }

    public static function isSuper(): bool
    {
        return (($_SESSION['dcai_admin_role'] ?? 0) === 1);
    }

    /**
     * 后台基础路径（动态计算，支持子目录部署）
     */
    public static function baseUrl(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(dirname($script), '/');
        return $dir === '.' ? '' : $dir;
    }

    /**
     * 生成后台页面 URL，如 adminUrl('login.php')
     */
    public static function adminUrl(string $path = ''): string
    {
        $base = self::baseUrl();
        if ($path === '') {
            return $base;
        }
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * 页面访问守卫；$perm 为空表示仅需登录
     */
    public static function guard(string $perm = ''): void
    {
        self::startSession();
        if (self::id() === null) {
            header('Location: ' . self::adminUrl('login.php'));
            exit;
        }
        if ($perm !== '' && !self::hasPerm($perm)) {
            http_response_code(403);
            exit('无权访问该页面');
        }
    }

    public static function hasPerm(string $perm): bool
    {
        if (self::isSuper()) {
            return true;
        }
        $perms = $_SESSION['dcai_admin_perms'] ?? [];
        return in_array($perm, $perms, true);
    }

    public static function setPerms(array $perms): void
    {
        $_SESSION['dcai_admin_perms'] = $perms;
    }

    /**
     * 记录后台操作日志
     */
    public static function opLog(string $action, $detail = ''): void
    {
        try {
            $detail = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE);
            if (strlen($detail) > 8000) {
                $detail = substr($detail, 0, 8000);
            }
            dcai_db()->insert('operation_logs', [
                'admin_id'   => self::id() ?? 0,
                'action'     => $action,
                'detail'     => $detail,
                'ip'         => dcai_client_ip(),
                'created_at' => dcai_now(),
            ]);
        } catch (Throwable $e) {
            dcai_log('error', '写入操作日志失败: ' . $e->getMessage());
        }
    }

    /**
     * 校验后台登录（错误次数锁定）
     */
    public static function checkLoginAttempts(string $username): array
    {
        $max = (int)dcai_config('security.login_max_fail', 5);
        $lockMinutes = (int)dcai_config('security.login_lock_minutes', 15);
        $key = 'login_fail_' . md5(strtolower($username) . '|' . dcai_client_ip());
        $fail = (int)(DCAI_Settings::get($key, 0));
        $lockedUntil = (int)DCAI_Settings::get($key . '_until', 0);
        if ($lockedUntil > time()) {
            $left = (int)ceil(($lockedUntil - time()) / 60);
            return [false, "登录失败次数过多，已被锁定 {$left} 分钟"];
        }
        if ($lockedUntil > 0 && $lockedUntil <= time()) {
            DCAI_Settings::set($key, 0);
            DCAI_Settings::set($key . '_until', 0);
            $fail = 0;
        }
        if ($fail >= $max) {
            DCAI_Settings::set($key . '_until', time() + $lockMinutes * 60);
            return [false, "登录失败次数过多，已被锁定 {$lockMinutes} 分钟"];
        }
        return [true, ''];
    }

    public static function recordLoginFail(string $username): int
    {
        $max = (int)dcai_config('security.login_max_fail', 5);
        $key = 'login_fail_' . md5(strtolower($username) . '|' . dcai_client_ip());
        $fail = (int)(DCAI_Settings::get($key, 0)) + 1;
        DCAI_Settings::set($key, $fail);
        if ($fail >= $max) {
            DCAI_Settings::set($key . '_until', time() + (int)dcai_config('security.login_lock_minutes', 15) * 60);
        }
        return $fail;
    }

    public static function clearLoginFail(string $username): void
    {
        $key = 'login_fail_' . md5(strtolower($username) . '|' . dcai_client_ip());
        DCAI_Settings::set($key, 0);
        DCAI_Settings::set($key . '_until', 0);
    }

    /**
     * 管理员可分配权限点列表（下拉使用）
     */
    public static function permOptions(): array
    {
        $opts = [];
        foreach (self::PERMS as $k => $v) {
            $opts[$k] = $v;
        }
        return $opts;
    }

    /**
     * 解析换行分隔的权限点字符串
     */
    public static function parsePerms(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw) ?: [])));
    }

    /**
     * 普通管理员可向下分配的安全权限点（剔除高危：管理员/系统设置/系统升级）
     */
    public static function assignablePerms(): array
    {
        $blocked = ['admin', 'setting', 'sysupdate'];
        return array_values(array_diff(array_keys(self::PERMS), $blocked));
    }
}

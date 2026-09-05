<?php
/**
 * DCAI SDK 集成辅助：全局守卫与钩子
 *
 * 用法：
 *   require __DIR__ . '/sdk/dcai_client.php';
 *   require __DIR__ . '/sdk/dcai_guard.php';
 *
 *   // 返回 DCAI_Client 单例
 *   $dcai = dcai_instance();
 *
 *   // 在程序入口调用守卫（未授权/被禁用则引导授权页）
 *   dcai_guard(function () {
 *       http_response_code(403);
 *       echo file_get_contents(__DIR__ . '/unauthorized.html');
 *       exit;
 *   });
 *
 *   // 注册框架钩子：请求结束时执行心跳/命令/弹窗
 *   dcai_register_hooks();
 */

if (!function_exists('dcai_instance')) {
    /**
     * 获取 DCAI_Client 全局单例
     */
    function dcai_instance(): DCAI_Client
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new DCAI_Client();
        }
        return $instance;
    }
}

if (!function_exists('dcai_guard')) {
    /**
     * 全局守卫
     */
    function dcai_guard(callable $fail): void
    {
        dcai_instance()->guard($fail);
    }
}

if (!function_exists('dcai_register_hooks')) {
    /**
     * 注册 shutdown 钩子，周期执行心跳/命令/弹窗
     */
    function dcai_register_hooks(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function(function () {
            try {
                dcai_instance()->runHooks();
            } catch (Throwable $e) {
                // 忽略周期任务异常，不影响主流程
            }
        });
    }
}

if (!function_exists('dcai_verify')) {
    /**
     * 便捷授权验证
     */
    function dcai_verify(): bool
    {
        return dcai_instance()->verify();
    }
}

if (!function_exists('dcai_call_module')) {
    /**
     * 便捷远程模块调用
     */
    function dcai_call_module(string $moduleCode, array $params = [])
    {
        return dcai_instance()->callModule($moduleCode, $params);
    }
}

if (!function_exists('dcai_get_popups')) {
    /**
     * 便捷弹窗拉取
     */
    function dcai_get_popups(): array
    {
        $resp = dcai_instance()->getPopups();
        return is_array($resp) && isset($resp['data']['popups']) ? $resp['data']['popups'] : [];
    }
}

if (!function_exists('dcai_get_update_notice')) {
    /**
     * 读取新版本提示（由心跳自动写入；无更新返回 null）
     */
    function dcai_get_update_notice(): ?array
    {
        return dcai_instance()->getUpdateNotice();
    }
}

if (!function_exists('dcai_diagnostics')) {
    /**
     * 一键诊断：返回本地授权状态概览数组，供排查用
     */
    function dcai_diagnostics(): array
    {
        return dcai_instance()->getDiagnostics();
    }
}

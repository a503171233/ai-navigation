<?php
/**
 * DCAI 授权系统 定时任务（建议 cron 每分钟执行）
 *  - 离线实例判定
 *  - 命令超时标记
 *  - 限流文件清理
 *  - 通知提醒（到期提醒/离线告警，每日低频）
 *  - 日志归档清理（保留期策略）
 * 用法: php cron/offline.php
 */
require_once dirname(__DIR__) . '/core/Bootstrap.php';

$offline = DCAI_InstanceService::markOffline();
$timeout = DCAI_CommandService::markTimeout(60);
$rl = new DCAI_RateLimit();
$rl->cleanup();

$out = sprintf("[%s] 离线实例:%d 超时命令:%d\n", dcai_now(), $offline, $timeout);

// 通知任务：到期提醒 / 离线告警（内部有 settings 去重，可安全每次调用）
try {
    $expirySent = DCAI_Notify::licenseExpiryReminder(7);
    $alertN = DCAI_Notify::offlineAlert(5);
    if ($expirySent > 0 || $alertN > 0) {
        $out .= sprintf("  通知: 到期提醒%d条 离线告警%d个\n", $expirySent, $alertN);
    }
} catch (Throwable $e) {
    dcai_log('error', '通知任务异常', ['err' => $e->getMessage()]);
}

// 日志归档清理：保留期策略（verify_logs/update_apply_logs/module_invoke_logs 保留 N 天）
// 分批（LIMIT 5000）删除，避免大表上单条长 DELETE 长时间持锁；数据库不可用时记录日志并继续
$logRetention = (int)dcai_config('log.retention_days', 90);
if ($logRetention > 0) {
    $cutoff = date('Y-m-d H:i:s', time() - $logRetention * 86400);
    $db = dcai_db();
    $batch = 5000;
    $purge = function (string $sql) use ($db, $cutoff, $batch): int {
        $total = 0;
        try {
            while (true) {
                $n = $db->execute($sql, [$cutoff]);
                $total += $n;
                if ($n < $batch) {
                    break;
                }
            }
        } catch (Throwable $e) {
            dcai_log('error', '日志清理异常', ['err' => $e->getMessage()]);
        }
        return $total;
    };
    $v = $purge('DELETE FROM verify_logs WHERE created_at < ? LIMIT ' . $batch);
    $m = $purge('DELETE FROM module_invoke_logs WHERE created_at < ? LIMIT ' . $batch);
    $u = $purge('DELETE FROM update_apply_logs WHERE created_at < ? LIMIT ' . $batch);
    $ops = $purge('DELETE FROM operation_logs WHERE created_at < ? LIMIT ' . $batch);
    $hist = $purge('DELETE FROM instance_commands WHERE executed_at IS NOT NULL AND executed_at < ? LIMIT ' . $batch);
    $out .= sprintf("  日志清理(保留%d天): verify%d module%d update%d op%d cmd%d\n", $logRetention, $v, $m, $u, $ops, $hist);
}

echo $out;

<?php
/**
 * 远程管理命令服务：队列 + 轮询 + 结果上报
 */
class DCAI_CommandService
{
    public const TYPES = ['disable', 'enable', 'config_push', 'maintenance_on', 'maintenance_off', 'reboot', 'update'];

    /**
     * 下发命令
     */
    public static function issue(int $instanceRowId, string $type, array $payload, ?int $adminId = null): array
    {
        if (!in_array($type, self::TYPES, true)) {
            return [false, '不支持的命令类型'];
        }
        $id = dcai_db()->insert('instance_commands', [
            'instance_id' => $instanceRowId,
            'command_type' => $type,
            'payload'     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status'      => 0,
            'issued_by'   => $adminId,
            'issued_at'   => dcai_now(),
        ]);
        return [true, $id];
    }

    /**
     * 轮询待执行命令（取最早 10 条，置为已下发）
     */
    public static function poll(int $instanceRowId): array
    {
        $db = dcai_db();
        $db->beginTransaction();
        try {
            $commands = $db->query(
                'SELECT * FROM instance_commands
                 WHERE instance_id = ? AND status = 0
                 ORDER BY id ASC LIMIT 10
                 FOR UPDATE',
                [$instanceRowId]
            );
            $out = [];
            foreach ($commands as $cmd) {
                $db->update('instance_commands', ['status' => 1, 'picked_at' => dcai_now()], 'id = ?', [(int)$cmd['id']]);
                $out[] = [
                    'id'           => (int)$cmd['id'],
                    'command_type' => $cmd['command_type'],
                    'payload'      => json_decode($cmd['payload'] ?? '{}', true) ?: [],
                ];
            }
            $db->commit();
            return $out;
        } catch (Throwable $e) {
            $db->rollback();
            dcai_log('error', '命令轮询失败', ['err' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 命令结果上报
     */
    public static function report(int $commandId, int $instanceRowId, int $status, array $result): array
    {
        $cmd = dcai_db()->queryOne('SELECT * FROM instance_commands WHERE id = ? AND instance_id = ?', [$commandId, $instanceRowId]);
        if (!$cmd) {
            return [false, '命令不存在'];
        }
        if ($status !== 2 && $status !== 3) {
            return [false, '状态值无效'];
        }
        dcai_db()->update('instance_commands', [
            'status'      => $status,
            'result'      => json_encode($result, JSON_UNESCAPED_UNICODE),
            'executed_at' => dcai_now(),
        ], 'id = ?', [$commandId]);
        return [true, null];
    }

    /**
     * 中止待执行/已下发命令
     */
    public static function cancel(int $commandId): bool
    {
        return (bool)dcai_db()->update(
            'instance_commands',
            ['status' => 4, 'result' => json_encode(['cancelled' => true, 'reason' => '后台中止']), 'executed_at' => dcai_now()],
            'id = ? AND status IN (0,1)',
            [$commandId]
        );
    }

    /**
     * 超时命令标记：已下发超过 60s 未执行视为超时
     */
    public static function markTimeout(int $timeoutSeconds = 60): int
    {
        $deadline = date('Y-m-d H:i:s', time() - $timeoutSeconds);
        $rows = dcai_db()->query(
            'SELECT id FROM instance_commands WHERE status = 1 AND picked_at < ?',
            [$deadline]
        );
        $count = 0;
        foreach ($rows as $row) {
            dcai_db()->update('instance_commands', ['status' => 4], 'id = ?', [(int)$row['id']]);
            $count++;
        }
        return $count;
    }
}

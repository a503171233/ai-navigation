<?php
/**
 * 弹窗服务：命中查询、展示上报
 */
class DCAI_PopupService
{
    /**
     * 查询某实例命中的启用中弹窗（单次 JOIN 查询，避免 N+1）
     */
    public static function listForInstance(int $instanceRowId): array
    {
        $instance = dcai_db()->queryOne('SELECT * FROM instances WHERE id = ?', [$instanceRowId]);
        if (!$instance) {
            return [];
        }
        $now = dcai_now();
        $rows = dcai_db()->query(
            'SELECT p.*, IFNULL(s.shown_count, 0) AS shown_count
             FROM popups p
             LEFT JOIN popup_show_logs s ON s.popup_id = p.id AND s.instance_id = ?
             WHERE p.product_id = ? AND p.status = 1
               AND (p.start_at IS NULL OR p.start_at <= ?)
               AND (p.end_at IS NULL OR p.end_at >= ?)
             ORDER BY p.id DESC',
            [$instanceRowId, (int)$instance['product_id'], $now, $now]
        );

        $out = [];
        foreach ($rows as $popup) {
            // 目标实例过滤
            if ((int)$popup['target_type'] === 1) {
                $targets = json_decode($popup['target_instance_ids'] ?? '[]', true) ?: [];
                if (!in_array($instanceRowId, array_map('intval', $targets), true)) {
                    continue;
                }
            }
            // 展示上限过滤（JOIN 带回展示次数）
            $max = (int)$popup['max_show_per_instance'];
            if ($max > 0 && (int)$popup['shown_count'] >= $max) {
                continue;
            }
            $out[] = [
                'id'         => (int)$popup['id'],
                'title'      => $popup['title'],
                'content'    => DCAI_Util::sanitizeHtml((string)$popup['content']),
                'popup_type' => (int)$popup['popup_type'],
            ];
        }
        return $out;
    }

    /**
     * 命中弹窗数（心跳提示用；轻量 COUNT 查询，避免构建完整弹窗内容）
     */
    public static function hitCount(int $instanceRowId): int
    {
        $instance = dcai_db()->queryOne('SELECT product_id FROM instances WHERE id = ?', [$instanceRowId]);
        if (!$instance) {
            return 0;
        }
        $now = dcai_now();
        $rows = dcai_db()->query(
            'SELECT p.id, p.target_type, p.target_instance_ids, p.max_show_per_instance,
                    IFNULL(s.shown_count, 0) AS shown_count
             FROM popups p
             LEFT JOIN popup_show_logs s ON s.popup_id = p.id AND s.instance_id = ?
             WHERE p.product_id = ? AND p.status = 1
               AND (p.start_at IS NULL OR p.start_at <= ?)
               AND (p.end_at IS NULL OR p.end_at >= ?)',
            [$instanceRowId, (int)$instance['product_id'], $now, $now]
        );
        $count = 0;
        foreach ($rows as $popup) {
            if ((int)$popup['target_type'] === 1) {
                $targets = json_decode($popup['target_instance_ids'] ?? '[]', true) ?: [];
                if (!in_array($instanceRowId, array_map('intval', $targets), true)) {
                    continue;
                }
            }
            $max = (int)$popup['max_show_per_instance'];
            if ($max > 0 && (int)$popup['shown_count'] >= $max) {
                continue;
            }
            $count++;
        }
        return $count;
    }

    /**
     * 展示上报（ON DUPLICATE KEY UPDATE 原子累计，避免并发唯一键冲突）
     */
    public static function reportShown(int $popupId, int $instanceRowId): bool
    {
        $popup = dcai_db()->queryOne('SELECT id FROM popups WHERE id = ?', [$popupId]);
        if (!$popup) {
            return false;
        }
        $now = dcai_now();
        $db = dcai_db();
        // 原子 UPSERT：首插计数 1，重复上报自增；避免并发下先查后插的唯一键冲突
        $stmt = $db->pdo()->prepare(
            'INSERT INTO popup_show_logs (popup_id, instance_id, shown_count, last_shown_at)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE shown_count = shown_count + 1, last_shown_at = VALUES(last_shown_at)'
        );
        $stmt->execute([$popupId, $instanceRowId, $now]);
        return true;
    }
}

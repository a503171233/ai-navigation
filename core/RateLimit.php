<?php
/**
 * 接口限流（基于文件缓存，无外部依赖）
 * 维度: verify 按 IP+授权码; api 按实例 ID
 */
class DCAI_RateLimit
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (dcai_config('storage.path', DCAI_ROOT . '/storage') . '/cache/ratelimit');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . substr(hash('sha256', $key), 0, 32) . '.json';
    }

    /**
     * 判断是否放行。$limit = 每分钟次数上限。
     * 读-改-写全程加文件锁，保证并发请求下计数不丢（避免超限放行）
     */
    public function allow(string $key, int $limit): bool
    {
        $file = $this->file($key);
        $now = time();
        $windowStart = $now - 60;

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            // 文件不可写时降级放行（宁可宽松也不误杀正常请求）
            return true;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return true; // 加锁失败降级放行
            }
            $raw = stream_get_contents($fp);
            $tmp = $raw ? json_decode($raw, true) : null;
            $data = is_array($tmp) ? $tmp : ['count' => 0, 'start' => $now];
            if (($data['start'] ?? 0) < $windowStart) {
                $data = ['count' => 0, 'start' => $now];
            }
            if (($data['count'] ?? 0) >= $limit) {
                return false;
            }
            $data['count'] = ($data['count'] ?? 0) + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
        return true;
    }

    /**
     * 清理过期限流文件（定时任务调用）
     */
    public function cleanup(int $olderThanSeconds = 3600): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (is_file($f) && (time() - filemtime($f)) > $olderThanSeconds) {
                @unlink($f);
            }
        }
    }
}

<?php
/**
 * 文件日志
 * 支持按级别写入 storage/logs/ 下的日志文件
 */
class DCAI_Logger
{
    private static ?DCAI_Logger $instance = null;
    private string $file;
    private string $level;

    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    private function __construct()
    {
        $this->file = dcai_config('log.file', DCAI_ROOT . '/storage/logs/app.log');
        $this->level = dcai_config('log.level', 'info');
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public static function instance(): DCAI_Logger
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        $threshold = self::LEVELS[$this->level] ?? 20;
        if ((self::LEVELS[$level] ?? 20) < $threshold) {
            return;
        }
        if ($context) {
            $message .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        $line = sprintf(
            "[%s] [%s] %s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            PHP_EOL
        );
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    public function debug(string $msg, array $ctx = []): void { $this->log('debug', $msg, $ctx); }
    public function info(string $msg, array $ctx = []): void { $this->log('info', $msg, $ctx); }
    public function warning(string $msg, array $ctx = []): void { $this->log('warning', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void { $this->log('error', $msg, $ctx); }

    public function file(): string
    {
        return $this->file;
    }
}

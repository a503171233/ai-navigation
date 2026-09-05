<?php
/**
 * 远程模块 PHP 代码型沙箱执行器
 *
 * 安全策略：
 *  - 将模块代码注入独立命名空间，通过桩函数遮蔽危险内置函数
 *  - 静态扫描危险模式（eval、反引号、include 变量等）
 *  - 执行时间 ≤ 5s，内存 ≤ 128M，开启输出缓冲
 *  - 仅注入白名单对象：$db(受限查询代理) / $params / $logger
 *  - 返回值仅允许可 JSON 序列化数据
 */

/**
 * 受限数据库代理：仅暴露预处理 SELECT 查询方法
 */
class DCAI_SandboxDb
{
    private DCAI_Database $db;

    public function __construct(DCAI_Database $db)
    {
        $this->db = $db;
    }

    public function queryAll(string $sql, array $params = []): array
    {
        $this->assertReadOnly($sql);
        return $this->db->query($sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->assertReadOnly($sql);
        return $this->db->queryOne($sql, $params);
    }

    public function queryValue(string $sql, array $params = [])
    {
        $this->assertReadOnly($sql);
        return $this->db->queryValue($sql, $params);
    }

    /**
     * 只读 SQL 强校验（加固）：
     *  - 去除前导空白/版本注释（/*! ... *​/）后必须以 SELECT 开头（WITH 也拒绝，避免 CTE 内嵌写）
     *  - 拒绝分号多语句（SELECT 1; DROP ...）——即使 PDO 默认禁多语句，也作纵深防御
     *  - 拒绝 SELECT ... INTO OUTFILE/DUMPFILE（写服务器文件）
     *  - 拒绝 FOR UPDATE / LOCK IN SHARE MODE（避免锁表影响线上）
     *  - 拒绝子查询中出现的写关键字（INSERT/UPDATE/DELETE/DROP 等，防 SELECT (DELETE ...) 等花式绕过）
     */
    private function assertReadOnly(string $sql): void
    {
        // 去掉前导空白与 MySQL 版本注释（/*! ... */ 或 /* ... */）
        $s = trim((string)$sql);
        while (preg_match('/^\/\*.*?\*\//s', $s)) {
            $s = trim(substr($s, strpos($s, '*/') + 2));
        }
        $upper = strtoupper($s);

        // 1. 必须以 SELECT 开头（BY 防 "SELECT ... " 前缀混淆；WITH 拒绝——MySQL 5.6 本无 CTE，但防未来）
        if (strncmp($upper, 'SELECT', 6) !== 0) {
            throw new RuntimeException('沙箱数据库仅允许 SELECT 查询');
        }

        // 2. 多语句（分号）
        if (preg_match('/;/', $s)) {
            throw new RuntimeException('沙箱数据库禁止多语句 SQL');
        }

        // 3. 写文件逃逸
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $s)) {
            throw new RuntimeException('沙箱数据库禁止 INTO OUTFILE/DUMPFILE');
        }

        // 4. 锁
        if (preg_match('/\bFOR\s+UPDATE\b/i', $s) || preg_match('/\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $s)) {
            throw new RuntimeException('沙箱数据库禁止加锁查询');
        }

        // 5. 写关键字（含子查询/注释混淆场景）
        if (preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|CREATE|TRUNCATE|RENAME|GRANT|REVOKE|SET\s+|LOAD\s+DATA|CALL|EXEC|PREPARE|EXECUTE)\b/i', $s)) {
            throw new RuntimeException('沙箱数据库禁止写操作或存储过程调用');
        }
    }
}

class DCAI_Sandbox
{
    public const MAX_TIME = 5;
    public const MAX_MEMORY = '128M';
    public const MAX_OUTPUT = 1048576;

    /** 被遮蔽的危险函数清单（语言构造如 exit/echo 无法遮蔽，由静态扫描兜底） */
    private const STUB_FUNCTIONS = [
        'system', 'exec', 'shell_exec', 'passthru', 'proc_open', 'popen',
        'pcntl_exec', 'pcntl_fork', 'posix_kill', 'dl', 'mail',
        'putenv', 'getenv', 'phpinfo', 'ini_set', 'ini_alter', 'set_time_limit',
        'header', 'setcookie', 'session_start', 'ob_start', 'ob_end_clean',
        'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'fputs', 'unlink',
        'rename', 'copy', 'mkdir', 'rmdir', 'chmod', 'chown', 'chgrp', 'touch',
        'opendir', 'readdir', 'scandir', 'glob', 'link', 'symlink',
        'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client',
        'socket_create', 'socket_connect', 'socket_send', 'parse_ini_file',
        'get_defined_functions', 'get_defined_vars', 'get_declared_classes',
        'debug_backtrace', 'highlight_file', 'show_source', 'php_strip_whitespace',
        'register_shutdown_function', 'set_error_handler', 'set_exception_handler',
    ];

    /** 静态扫描危险模式 */
    private const DANGEROUS_PATTERNS = [
        '/\beval\s*\(/i',
        '/\bassert\s*\(/i',
        '/`[^`]*`/',
        '/\$_[A-Za-z]+\s*\(/',
        '/\\\\(system|exec|shell_exec|passthru|proc_open|popen|eval)\s*\(/',
        '/\b(include|include_once|require|require_once)\s*[^\'\"]/i',
        '/\bnew\s+Reflection/i',
        '/\bunserialize\s*\(/i',
        '/\bcreate_function\s*\(/i',
        '/\bexit\s*\(/i',
        '/\bdie\s*\(/i',
        '/\$GLOBALS\b/',
    ];

    /**
     * 在沙箱中执行模块代码
     * 优先子进程隔离（proc_open 可用时）；被禁用的环境（宝塔默认禁 proc_open）
     * 自动降级为同进程受限执行（命名空间隔离 + 桩函数 + 静态扫描 + 时间限制）。
     *
     * @param string $code 模块 PHP 代码（不含 <?php，语句形式，可 return 结果）
     * @param array $params 调用参数
     * @param array $context 注入上下文（db/logger 由子进程自行初始化）
     * @return array{0:bool,1:mixed,2:string} [是否成功, 返回值, 错误信息]
     */
    public static function run(string $code, array $params, array $context = []): array
    {
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                return [false, null, '模块代码包含被禁止的危险调用'];
            }
        }
        foreach (self::STUB_FUNCTIONS as $fn) {
            if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $code)) {
                return [false, null, '模块代码调用了被禁用的函数: ' . $fn];
            }
        }

        // 优先子进程隔离执行；proc_open 不可用或启动失败时降级同进程执行
        if (function_exists('proc_open')) {
            $sub = self::runInSubprocess($code, $params);
            if ($sub[0] || strpos($sub[2], '无法启动沙箱子进程') === false) {
                return $sub; // 子进程正常返回，或属于明确的模块执行错误（非环境不可用）
            }
            // proc_open 存在但启动被拒（策略/权限）→ 降级
        }
        return self::runInProcess($code, $params);
    }

    /**
     * 子进程隔离执行（原实现）
     */
    private static function runInSubprocess(string $code, array $params): array
    {

        // 每次运行使用独立命名空间，避免同进程多次运行重复声明
        $ns = 'DCAI_Sandbox_' . bin2hex(random_bytes(6));

        $stubs = '';
        foreach (self::STUB_FUNCTIONS as $fn) {
            $stubs .= 'function ' . $fn . '(...$args) { throw new Blocked("' . $fn . '"); }' . PHP_EOL;
        }

        $tmpDir = (dcai_config('storage.path', DCAI_ROOT . '/storage') . '/cache/sandbox');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmpFile = $tmpDir . '/mod_' . bin2hex(random_bytes(8)) . '.php';

        $root = DCAI_ROOT;
        $paramsExport = var_export($params, true);

        $wrapped = '<?php ' . PHP_EOL
            . 'namespace ' . $ns . ' {' . PHP_EOL
            . 'class Blocked extends \RuntimeException {}' . PHP_EOL
            . $stubs
            . 'function dcai_run_module($db, $params, $logger) {' . PHP_EOL
            . '    $__fn = function () use ($db, $params, $logger) {' . PHP_EOL
            . $code . PHP_EOL
            . '    };' . PHP_EOL
            . '    return $__fn();' . PHP_EOL
            . '}' . PHP_EOL
            . '}' . PHP_EOL
            . 'namespace {' . PHP_EOL
            . 'require_once ' . var_export($root . '/core/Bootstrap.php', true) . ';' . PHP_EOL
            . 'require_once ' . var_export($root . '/core/Sandbox.php', true) . ';' . PHP_EOL
            . 'set_time_limit(' . self::MAX_TIME . ');' . PHP_EOL
            . '@ini_set("memory_limit", "' . self::MAX_MEMORY . '");' . PHP_EOL
            . '$db = new \DCAI_SandboxDb(dcai_db());' . PHP_EOL
            . '$params = ' . $paramsExport . ';' . PHP_EOL
            . '$logger = DCAI_Logger::instance();' . PHP_EOL
            . 'ob_start();' . PHP_EOL
            . 'try {' . PHP_EOL
            . '    $__result = \\' . $ns . '\\dcai_run_module($db, $params, $logger);' . PHP_EOL
            . '    $__output = ob_get_clean();' . PHP_EOL
            . '    echo "DCAI_SANDBOX_OK\n" . serialize([$__result, $__output]);' . PHP_EOL
            . '} catch (\Throwable $e) {' . PHP_EOL
            . '    @ob_end_clean();' . PHP_EOL
            . '    echo "DCAI_SANDBOX_ERR\n" . $e->getMessage();' . PHP_EOL
            . '}' . PHP_EOL
            . '}' . PHP_EOL;

        @file_put_contents($tmpFile, $wrapped);

        // 子进程执行：强制时间/内存限制，父进程隔离
        // stderr 重定向到文件，避免管道缓冲填满导致死锁
        $stderrFile = $tmpDir . '/mod_err_' . bin2hex(random_bytes(6)) . '.log';
        $cmd = [
            PHP_BINARY,
            '-d', 'max_execution_time=' . self::MAX_TIME,
            '-d', 'memory_limit=' . self::MAX_MEMORY,
            '-d', 'display_errors=0',
            $tmpFile,
        ];
        $pipes = [];
        // 'NUL' 仅 Windows 有效，Unix 系需使用 /dev/null，否则 proc_open 失败导致沙箱不可用
        $nullDev = (PHP_OS_FAMILY === 'Windows') ? 'NUL' : '/dev/null';
        $proc = @proc_open($cmd, [
            0 => ['file', $nullDev, 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrFile, 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            @unlink($tmpFile);
            return [false, null, '无法启动沙箱子进程'];
        }
        $raw = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($proc);
        $stderr = is_file($stderrFile) ? (string)@file_get_contents($stderrFile) : '';
        @unlink($stderrFile);
        @unlink($tmpFile);

        if ($exitCode !== 0 && strpos($raw, 'DCAI_SANDBOX') !== 0) {
            $msg = trim($stderr);
            if ($msg === '') {
                $msg = '子进程异常退出（code=' . $exitCode . '），可能为超时或内存超限';
            }
            return [false, null, '模块执行异常: ' . mb_substr($msg, 0, 300)];
        }

        if (strncmp($raw, 'DCAI_SANDBOX_OK', 15) === 0) {
            $serialized = substr($raw, 16);
            $unpack = @unserialize($serialized);
            if (!is_array($unpack) || count($unpack) !== 2) {
                return [false, null, '模块返回值解析失败'];
            }
            [$result, $output] = $unpack;
            if (strlen((string)$output) > self::MAX_OUTPUT) {
                return [false, null, '模块输出内容超过上限'];
            }
            if (!self::jsonSafe($result)) {
                return [false, null, '模块返回值不可序列化为 JSON'];
            }
            return [true, $result, ''];
        }
        if (strncmp($raw, 'DCAI_SANDBOX_ERR', 16) === 0) {
            return [false, null, '模块执行异常: ' . trim(substr($raw, 17))];
        }
        return [false, null, '模块无有效返回'];
    }

    /**
     * 同进程受限执行（proc_open 被禁用的环境，如宝塔默认安全配置）
     *
     * 安全策略（与子进程一致，在本进程内重新建立）：
     *  - 随机独立命名空间隔离，桩函数遮蔽 STUB_FUNCTIONS 全部危险函数
     *    （命名空间内函数解析优先于全局，模块代码调用 system()/exec() 等将命中桩函数抛异常）
     *  - 静态扫描危险模式（进入前已做，此处再次兜底，拦截 \system() 全限定等）
     *  - set_time_limit 限制执行时长（php-cgi 下同样生效）；输出缓冲捕获 echo
     *  - 仅注入受限 $db（DCAI_SandboxDb，只读 SELECT）
     *  - 返回值强制 JSON 安全校验
     *
     * 局限说明：同进程无法在超时后立即终止当前请求（死循环会占用直到 max_execution_time），
     * 因此仅作为子进程完全不可用（proc_open 被禁）时的兼容降级，并在错误信息中标注「沙箱降级」。
     */
    private static function runInProcess(string $code, array $params): array
    {
        // 静态扫描兜底（防编码绕过）
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                return [false, null, '模块代码包含被禁止的危险调用'];
            }
        }
        foreach (self::STUB_FUNCTIONS as $fn) {
            if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $code)) {
                return [false, null, '模块代码调用了被禁用的函数: ' . $fn];
            }
        }

        $ns = 'DCAI_Sandbox_Local_' . bin2hex(random_bytes(6));
        // 每次运行使用唯一全局 key（防上一次运行的 DCAI_SANDBOX_TIMEOUT 残留污染本次，且避免多注册 tick 处理器相互干扰）
        $gStart = 'DCAI_TICK_START_' . $ns;
        $gTimeout = 'DCAI_TIMEOUT_' . $ns;
        $stubs = '';
        foreach (self::STUB_FUNCTIONS as $fn) {
            $stubs .= 'function ' . $fn . '(...$args) { throw new \RuntimeException("Blocked: ' . $fn . '"); }' . PHP_EOL;
        }

        // 生成含命名空间桩函数与模块执行函数的临时文件（不用 eval，避免被扫描规则拦截）
        // 结构（PHP 命名空间规则：namespace 须紧跟 declare）：
        //   <?php
        //   declare(ticks=1);
        //   namespace DCAI_Sandbox_Local_xxx {  桩函数 + tick 检查 + 模块执行函数  }
        //   register_tick_function('\ns\tick');  $GLOBALS['DCAI_TICK_START_x']=...;
        // declare(ticks=1) 使临时文件内每条语句触发 tick 检查 → 死循环也能被优雅中断（抛异常可 catch）
        $stubDecl = '<?php ' . PHP_EOL
            . 'declare(ticks=1);' . PHP_EOL
            . 'namespace ' . $ns . ' {' . PHP_EOL
            . 'use \Exception;' . PHP_EOL
            . 'use \RuntimeException;' . PHP_EOL
            . 'use \InvalidArgumentException;' . PHP_EOL
            . 'use \LogicException;' . PHP_EOL
            . 'use \DomainException;' . PHP_EOL
            . 'use \TypeError;' . PHP_EOL
            . $stubs
            . 'function dcai_sandbox_tick() {' . PHP_EOL
            . '    $gStart = "' . $gStart . '";' . PHP_EOL
            . '    $gTimeout = "' . $gTimeout . '";' . PHP_EOL
            . '    if (!isset($GLOBALS[$gStart])) return;' . PHP_EOL
            . '    if (!empty($GLOBALS[$gTimeout])) return;' . PHP_EOL
            . '    if (microtime(true) - $GLOBALS[$gStart] > ' . (self::MAX_TIME - 1) . ') {' . PHP_EOL
            . '        $GLOBALS[$gTimeout] = true;' . PHP_EOL
            . '        throw new \RuntimeException("DCAI_SANDBOX_TIMEOUT");' . PHP_EOL
            . '    }' . PHP_EOL
            . '}' . PHP_EOL
            . 'function dcai_run_module($db, $params) {' . PHP_EOL
            . '    $__fn = function () use ($db, $params) {' . PHP_EOL
            . $code . PHP_EOL
            . '    };' . PHP_EOL
            . '    return $__fn();' . PHP_EOL
            . '}' . PHP_EOL
            . 'register_tick_function(\'\\' . $ns . '\\dcai_sandbox_tick\');' . PHP_EOL
            . '$GLOBALS["' . $gStart . '"] = microtime(true);' . PHP_EOL
            . '}' . PHP_EOL;

        $tmpDir = (dcai_config('storage.path', DCAI_ROOT . '/storage') . '/cache/sandbox');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmp = $tmpDir . '/local_' . bin2hex(random_bytes(8)) . '.php';
        if (@file_put_contents($tmp, $stubDecl) === false) {
            return [false, null, '沙箱临时目录不可写'];
        }

        $hasLoaded = false;
        try {
            require $tmp;
            $hasLoaded = true;
        } catch (\Throwable $e) {
            @unlink($tmp);
            return [false, null, '沙箱桩函数加载失败: ' . mb_substr($e->getMessage(), 0, 200)];
        } finally {
            @unlink($tmp);
        }
        if (!$hasLoaded) {
            return [false, null, '沙箱桩函数加载失败'];
        }

        // 时间限制（php-cgi/cli 均生效；max_execution_time 到点触发致命错误）
        // 死循环由临时文件内 declare(ticks=1) + tick 检查器在超限时抛异常中断（可被下方 catch 捕获）；
        // 无语句空循环（tick 不触发）依赖 set_time_limit 硬超时 + 框架全局致命错误处理器（Bootstrap.php）转 JSON，不裸崩
        @set_time_limit(self::MAX_TIME + 1);

        // 受限数据库代理（同进程：使用真实连接包装只读访问）
        $db = new \DCAI_SandboxDb(dcai_db());

        ob_start();
        try {
            $fn = $ns . '\\dcai_run_module';
            $result = $fn($db, $params);
            $output = ob_get_clean();
            if (strlen((string)$output) > self::MAX_OUTPUT) {
                return [false, null, '模块输出内容超过上限'];
            }
            if (!self::jsonSafe($result)) {
                return [false, null, '模块返回值不可序列化为 JSON'];
            }
            return [true, $result, ''];
        } catch (\Throwable $e) {
            @ob_end_clean();
            // 超时异常（tick 检查器触发）直接返回
            if (strpos($e->getMessage(), 'DCAI_SANDBOX_TIMEOUT') !== false) {
                return [false, null, '模块执行超时（超过 ' . self::MAX_TIME . ' 秒）'];
            }
            return [false, null, '模块执行异常: ' . mb_substr($e->getMessage(), 0, 300)];
        } finally {
            // 清理本次运行的全局 key（防残留影响后续沙箱运行）
            unset($GLOBALS[$gStart], $GLOBALS[$gTimeout]);
        }
    }

    private static function jsonSafe($value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::jsonSafe($item)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}

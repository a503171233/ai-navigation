<?php
/**
 * PDO 单例封装
 */
class DCAI_Database
{
    private static ?DCAI_Database $instance = null;
    private PDO $pdo;
    private bool $reconnecting = false;

    private function __construct()
    {
        $this->connect();
    }

    /**
     * 建立 PDO 连接（含 1 次重试）
     */
    private function connect(): void
    {
        $db = dcai_config('db', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'] ?? '127.0.0.1',
            $db['port'] ?? 3306,
            $db['name'] ?? '',
            $db['charset'] ?? 'utf8mb4'
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $user = $db['user'] ?? '';
        $pass = $db['pass'] ?? '';
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // 首次失败：短延迟重试一次，应对瞬时抖动/连接池回收
            usleep(150000);
            try {
                $this->pdo = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e2) {
                // 兜底：写入日志后抛异常，交由全局异常处理器输出降级响应
                try {
                    dcai_log('error', '数据库连接失败', ['err' => $e2->getMessage()]);
                } catch (Throwable $ignore) {
                }
                throw new RuntimeException('数据库连接失败: ' . $e2->getMessage(), 0, $e2);
            }
        }
    }

    public static function instance(): DCAI_Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * 执行 SQL 前检测连接活性；失效则重连一次（MySQL server has gone away 兜底）
     */
    private function ensureAlive(): void
    {
        if ($this->reconnecting) {
            return;
        }
        try {
            $this->pdo->query('SELECT 1');
        } catch (Throwable $e) {
            $this->reconnecting = true;
            try {
                $this->connect();
            } finally {
                $this->reconnecting = false;
            }
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $this->ensureAlive();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->ensureAlive();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function queryValue(string $sql, array $params = [])
    {
        $this->ensureAlive();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->ensureAlive();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', array_fill(0, count($cols), '?'))
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "`$col` = ?";
            $params[] = $val;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        $params = array_merge($params, $whereParams);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $whereParams = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($whereParams);
        return $stmt->rowCount();
    }

    /**
     * 字段自增/自减
     */
    public function increment(string $table, string $column, string $where, array $whereParams = [], int $by = 1): int
    {
        $sql = sprintf('UPDATE `%s` SET `%s` = `%s` + ? WHERE %s', $table, $column, $column, $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$by], $whereParams));
        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }
}

<?php
/**
 * PlotBird Analytics - PDO wrapper.
 *
 * A thin, strict layer over PDO:
 *   * one connection per request (singleton),
 *   * every query goes through a prepared statement - there is no API that
 *     accepts interpolated user input,
 *   * table names carry the configured prefix transparently,
 *   * exceptions are always thrown; silent failure is not an option.
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class Database
{
    private static ?Database $instance = null;

    private PDO $pdo;
    private string $prefix;
    private int $queryCount = 0;

    /**
     * @param array<string,mixed> $cfg The 'db' section of the configuration.
     */
    private function __construct(array $cfg)
    {
        $this->prefix = (string) ($cfg['prefix'] ?? 'pb_');

        $host    = (string) ($cfg['host'] ?? 'localhost');
        $port    = (int) ($cfg['port'] ?? 3306);
        $name    = (string) ($cfg['name'] ?? '');
        $charset = (string) ($cfg['charset'] ?? 'utf8mb4');

        // A host may be given as "host:port" (common on shared hostings) or as
        // a socket path, in which case the port is irrelevant.
        if (strpos($host, ':') !== false && substr_count($host, ':') === 1 && strpos($host, ']') === false) {
            [$host, $hostPort] = explode(':', $host, 2);
            if (ctype_digit($hostPort)) {
                $port = (int) $hostPort;
            }
        }

        $dsn = $name === ''
            ? sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, so a broken server-side quote can never
            // turn into an injection vector.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];

        // Optional TLS for managed MySQL. Shared hostings usually do not need it.
        $sslCa = $cfg['ssl_ca'] ?? null;
        if (is_string($sslCa) && $sslCa !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        }

        try {
            $this->pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['password'] ?? ''), $options);
        } catch (PDOException $e) {
            // Never leak credentials into the message.
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        // Force strict-ish behaviour for the session without touching the server
        // configuration (shared hostings do not allow SET GLOBAL).
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET SESSION time_zone = '+00:00'");
    }

    /**
     * Connect using the loaded configuration.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            /** @var array<string,mixed> $cfg */
            $cfg = Config::get('db', []);
            if (!is_array($cfg)) {
                throw new RuntimeException('Missing "db" configuration section.');
            }
            self::$instance = new self($cfg);
        }
        return self::$instance;
    }

    /**
     * Open an ad-hoc connection (used by the installer before config.php exists).
     *
     * @param array<string,mixed> $cfg
     */
    public static function connect(array $cfg): self
    {
        return new self($cfg);
    }

    /**
     * Replace the process-wide connection (installer, tests).
     */
    public static function swap(?Database $db): void
    {
        self::$instance = $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Fully qualified, backtick-quoted table name.
     */
    public function table(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * Run a prepared statement.
     *
     * @param array<string|int,mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $this->queryCount++;
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : $key;
            $type = PDO::PARAM_STR;
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_INT;
                $value = $value ? 1 : 0;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            }
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * Fetch a single row or null.
     *
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows.
     *
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();
        return $rows;
    }

    /**
     * Fetch the first column of the first row.
     *
     * @param array<string|int,mixed> $params
     * @param mixed                   $default
     * @return mixed
     */
    public function value(string $sql, array $params = [], $default = null)
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? $default : $value;
    }

    /**
     * Fetch the first column of every row as a flat list.
     *
     * @param array<string|int,mixed> $params
     * @return list<mixed>
     */
    public function column(string $sql, array $params = []): array
    {
        /** @var list<mixed> $values */
        $values = $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
        return $values;
    }

    /**
     * Insert a row and return its id.
     *
     * @param array<string,mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $quoted  = array_map(static fn (string $c): string => '`' . $c . '`', $columns);
        $holders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = 'INSERT INTO ' . $this->table($table)
            . ' (' . implode(', ', $quoted) . ')'
            . ' VALUES (' . implode(', ', $holders) . ')';

        $this->run($sql, $this->namedParams($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update rows matching a prepared WHERE clause and return the row count.
     *
     * @param array<string,mixed>     $data
     * @param array<string|int,mixed> $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = '`' . $column . '` = :set_' . $column;
        }
        $params = [];
        foreach ($data as $column => $value) {
            $params['set_' . $column] = $value;
        }
        foreach ($whereParams as $key => $value) {
            $params[is_int($key) ? $key + 1 : ltrim((string) $key, ':')] = $value;
        }

        $sql = 'UPDATE ' . $this->table($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Delete rows matching a prepared WHERE clause.
     *
     * @param array<string|int,mixed> $params
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->run('DELETE FROM ' . $this->table($table) . ' WHERE ' . $where, $params)->rowCount();
    }

    /**
     * Bind an associative array using named placeholders without the colon.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function namedParams(array $data): array
    {
        $params = [];
        foreach ($data as $column => $value) {
            $params[':' . $column] = $value;
        }
        return $params;
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function serverVersion(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Whether the connection is a MariaDB server (used to pick an SQL dialect
     * only where the two genuinely differ).
     */
    public function isMariaDb(): bool
    {
        return stripos($this->serverVersion(), 'mariadb') !== false;
    }

    /**
     * Execute a multi-statement SQL script (schema.sql).
     *
     * Comments that occupy a whole line are stripped; statements are split on
     * the semicolon that ends a line. The bundled schema deliberately avoids
     * semicolons inside string literals and triggers, so this stays predictable.
     *
     * @return int Number of statements executed.
     */
    public function executeScript(string $sql): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
        $buffer = '';
        $count  = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                continue;
            }
            $buffer .= $line . "\n";
            if (substr($trimmed, -1) === ';') {
                $statement = trim($buffer);
                $buffer    = '';
                if ($statement !== '' && $statement !== ';') {
                    $this->pdo->exec($statement);
                    $count++;
                }
            }
        }

        $rest = trim($buffer);
        if ($rest !== '') {
            $this->pdo->exec($rest);
            $count++;
        }

        return $count;
    }
}

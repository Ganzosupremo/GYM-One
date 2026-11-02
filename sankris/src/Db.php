<?php
declare(strict_types=1);

namespace Sankris;

use PDO;

/** Thin PDO wrapper: prepared statements everywhere, utf8mb4, nested-safe transactions. */
final class Db
{
    private PDO $pdo;
    private int $depth = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function connect(string $host, string $user, string $pass, string $name): self
    {
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass);
        // Same clock for DB defaults (created_at) and PHP dates (received_at, session times)
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = [])
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(fn($c) => "`$c`", $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams): int
    {
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        return $this->run("UPDATE {$table} SET {$set} WHERE {$where}", array_merge(array_values($data), $whereParams))->rowCount();
    }

    /** Runs $fn in a transaction; inner calls join the outer transaction. */
    public function transaction(callable $fn)
    {
        // Join an open transaction (ours, or one started directly on the PDO handle)
        if ($this->depth > 0 || $this->pdo->inTransaction()) {
            $this->depth++;
            try {
                return $fn($this);
            } finally {
                $this->depth--;
            }
        }
        $this->depth = 0;
        $this->pdo->beginTransaction();
        $this->depth = 1;
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public static function isDuplicateKey(\Throwable $e): bool
    {
        return $e instanceof \PDOException && ($e->errorInfo[1] ?? null) === 1062;
    }
}

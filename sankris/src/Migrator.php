<?php
declare(strict_types=1);

namespace Sankris;

/** Applies sankris/migrations/*.sql|*.php once each, in name order. */
final class Migrator
{
    private Db $db;
    private string $dir;

    public function __construct(Db $db, string $dir)
    {
        $this->db = $db;
        $this->dir = $dir;
    }

    public function ensureTable(): void
    {
        $this->db->run("CREATE TABLE IF NOT EXISTS sk_migrations (
            name varchar(120) NOT NULL PRIMARY KEY,
            applied_at datetime NOT NULL DEFAULT current_timestamp()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function pending(): array
    {
        $this->ensureTable();
        $done = array_column($this->db->all('SELECT name FROM sk_migrations'), 'name');
        $files = glob($this->dir . '/*.{sql,php}', GLOB_BRACE) ?: [];
        sort($files);
        return array_values(array_filter($files, fn($f) => !in_array(basename($f), $done, true)));
    }

    /** @return string[] applied migration names */
    public function migrate(): array
    {
        $applied = [];
        foreach ($this->pending() as $file) {
            if (substr($file, -4) === '.sql') {
                foreach (self::splitSql((string) file_get_contents($file)) as $statement) {
                    $this->db->pdo()->exec($statement);
                }
            } else {
                $fn = require $file;
                $fn($this);
            }
            $this->db->run('INSERT INTO sk_migrations (name) VALUES (?)', [basename($file)]);
            $applied[] = basename($file);
        }
        return $applied;
    }

    public function db(): Db
    {
        return $this->db;
    }

    public function addColumn(string $table, string $column, string $definition): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        $exists = $this->db->value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if ((int) $exists === 0) {
            $this->db->pdo()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    public function tableExists(string $table): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    /** Splits on ";" at line ends, ignoring "--" comment lines. */
    public static function splitSql(string $sql): array
    {
        $lines = array_filter(explode("\n", $sql), fn($l) => !preg_match('/^\s*--/', $l));
        $parts = preg_split('/;\s*(\r?\n|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }
}

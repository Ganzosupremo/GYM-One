<?php
declare(strict_types=1);

namespace Sankris;

/** Configurable business rules (sk_settings). Secrets live in .env, not here. */
final class Settings
{
    private Db $db;
    private ?array $cache = null;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function get(string $key, string $default = ''): string
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->all('SELECT `key`, `value` FROM sk_settings') as $row) {
                $this->cache[$row['key']] = $row['value'];
            }
        }
        return $this->cache[$key] ?? $default;
    }

    public function int(string $key, int $default): int
    {
        $v = $this->get($key, (string) $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return int[] */
    public function intList(string $key, string $default): array
    {
        $parts = array_filter(array_map('trim', explode(',', $this->get($key, $default))), 'is_numeric');
        return array_map('intval', array_values($parts));
    }

    public function set(string $key, string $value, ?int $workerId = null): void
    {
        $this->db->run(
            'INSERT INTO sk_settings (`key`, `value`, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)',
            [$key, $value, $workerId]
        );
        $this->cache = null;
    }
}

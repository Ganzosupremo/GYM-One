<?php
declare(strict_types=1);

namespace Sankris;

/** Who changed what and when, with before/after snapshots. */
final class Audit
{
    private Db $db;
    private ?int $workerId;
    private string $actor;

    public function __construct(Db $db, ?int $workerId = null, string $actor = 'staff')
    {
        $this->db = $db;
        $this->workerId = $workerId;
        $this->actor = $actor;
    }

    public function withActor(?int $workerId, string $actor): self
    {
        return new self($this->db, $workerId, $actor);
    }

    public function workerId(): ?int
    {
        return $this->workerId;
    }

    public function log(string $entity, ?int $entityId, string $action, ?array $before = null, ?array $after = null): void
    {
        if ($before !== null && $after !== null) {
            // Keep only changed fields so the history is readable
            $changedBefore = [];
            $changedAfter = [];
            foreach ($after as $k => $v) {
                $old = $before[$k] ?? null;
                if ((string) $old !== (string) $v) {
                    $changedBefore[$k] = $old;
                    $changedAfter[$k] = $v;
                }
            }
            if ($changedAfter === []) {
                return;
            }
            [$before, $after] = [$changedBefore, $changedAfter];
        }
        $this->db->insert('sk_audit_log', [
            'worker_id' => $this->workerId,
            'actor' => $this->actor,
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'before_json' => $before === null ? null : json_encode(self::redact($before), JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode(self::redact($after), JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Medical notes are minors' sensitive data: the audit trail records that they changed, not their content. */
    private static function redact(array $data): array
    {
        foreach (['medical_notes'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                $data[$k] = '[modificado]';
            }
        }
        return $data;
    }
}

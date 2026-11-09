<?php
declare(strict_types=1);

namespace Sankris;

/**
 * Credit ledger. Credits are granted per credit window (week, month, billing period or validity),
 * independent of the billing period. A NULL credit amount on the subscription means unlimited.
 */
final class Credits
{
    private Db $db;
    private Audit $audit;

    public function __construct(Db $db, Audit $audit)
    {
        $this->db = $db;
        $this->audit = $audit;
    }

    /** @return array{0:string,1:string} */
    public function window(array $sub, string $date): array
    {
        return Period::creditWindow($sub['credit_period'], $date, $sub['start_date'], $sub['billing_period'], $sub['validity_days'] !== null ? (int) $sub['validity_days'] : null);
    }

    public static function isUnlimited(array $sub): bool
    {
        return $sub['credits'] === null;
    }

    /** True when the subscription gives access on $date (status, dates and pauses). */
    public static function coversDate(array $sub, string $date): bool
    {
        if (!in_array($sub['status'], ['active', 'paused'], true)) {
            // A cancelled subscription still covers dates before its cancellation date
            if (!($sub['status'] === 'cancelled' && $sub['cancel_at'] !== null && $date < $sub['cancel_at'])) {
                return false;
            }
        }
        if ($date < $sub['start_date']) {
            return false;
        }
        if ($sub['end_date'] !== null && $date > $sub['end_date']) {
            return false;
        }
        if ($sub['cancel_at'] !== null && $date >= $sub['cancel_at']) {
            return false;
        }
        if ($sub['paused_from'] !== null && $date >= $sub['paused_from'] && ($sub['paused_until'] === null || $date <= $sub['paused_until'])) {
            return false;
        }
        return true;
    }

    /** Grants the window's credits once (idempotent through grant_key). */
    public function ensureGrant(array $sub, string $date): void
    {
        if (self::isUnlimited($sub) || !self::coversDate($sub, $date)) {
            return;
        }
        [$start, $end] = $this->window($sub, $date);
        $key = 'sub' . $sub['id'] . ':' . $start;
        try {
            $this->db->insert('sk_credit_ledger', [
                'subscription_id' => $sub['id'],
                'student_id' => $sub['student_id'],
                'delta' => (int) $sub['credits'],
                'reason' => 'grant',
                'period_start' => $start,
                'period_end' => $end,
                'grant_key' => $key,
            ]);
        } catch (\PDOException $e) {
            if (!Db::isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    /** Available credits in the window containing $date; null = unlimited. */
    public function available(array $sub, string $date): ?int
    {
        if (self::isUnlimited($sub)) {
            return null;
        }
        $this->ensureGrant($sub, $date);
        [$start] = $this->window($sub, $date);
        return (int) $this->db->value(
            'SELECT COALESCE(SUM(delta), 0) FROM sk_credit_ledger WHERE subscription_id = ? AND period_start = ?',
            [$sub['id'], $start]
        );
    }

    public function consume(array $sub, int $bookingId, string $date): void
    {
        if (self::isUnlimited($sub)) {
            return;
        }
        [$start, $end] = $this->window($sub, $date);
        $this->db->insert('sk_credit_ledger', [
            'subscription_id' => $sub['id'], 'student_id' => $sub['student_id'], 'delta' => -1, 'reason' => 'booking',
            'period_start' => $start, 'period_end' => $end, 'booking_id' => $bookingId, 'worker_id' => $this->audit->workerId(),
        ]);
    }

    /** Returns the credit of a booking, once: a second refund for the same booking is ignored. */
    public function refund(array $sub, int $bookingId, string $date, string $note = ''): void
    {
        if (self::isUnlimited($sub)) {
            return;
        }
        $consumed = (int) $this->db->value("SELECT COALESCE(-SUM(delta), 0) FROM sk_credit_ledger WHERE booking_id = ? AND reason IN ('booking', 'booking_refund')", [$bookingId]);
        if ($consumed <= 0) {
            return;
        }
        [$start, $end] = $this->window($sub, $date);
        $this->db->insert('sk_credit_ledger', [
            'subscription_id' => $sub['id'], 'student_id' => $sub['student_id'], 'delta' => 1, 'reason' => 'booking_refund',
            'period_start' => $start, 'period_end' => $end, 'booking_id' => $bookingId, 'note' => $note ?: null,
            'worker_id' => $this->audit->workerId(),
        ]);
    }

    public function adjust(array $sub, string $date, int $delta, string $note): void
    {
        [$start, $end] = $this->window($sub, $date);
        $this->db->insert('sk_credit_ledger', [
            'subscription_id' => $sub['id'], 'student_id' => $sub['student_id'], 'delta' => $delta, 'reason' => 'adjustment',
            'period_start' => $start, 'period_end' => $end, 'note' => $note, 'worker_id' => $this->audit->workerId(),
        ]);
        $this->audit->log('subscription', (int) $sub['id'], 'credit_adjust', null, ['delta' => $delta, 'note' => $note, 'period_start' => $start]);
    }

    public function ledger(int $subscriptionId, int $limit = 100): array
    {
        return $this->db->all('SELECT * FROM sk_credit_ledger WHERE subscription_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$subscriptionId]);
    }
}

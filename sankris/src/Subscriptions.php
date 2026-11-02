<?php
declare(strict_types=1);

namespace Sankris;

/**
 * Phase 2: plans and subscriptions.
 * A subscription copies the plan's conditions when it is created, so later
 * plan price changes never alter existing contracts.
 */
final class Subscriptions
{
    private Db $db;
    private Audit $audit;
    private Credits $credits;

    private const PLAN_FIELDS = ['name', 'kind', 'price', 'billing_period', 'credits', 'credit_period', 'validity_days', 'auto_renew', 'is_public', 'active', 'description', 'external_id'];

    public function __construct(Db $db, Audit $audit, Credits $credits)
    {
        $this->db = $db;
        $this->audit = $audit;
        $this->credits = $credits;
    }

    // ---------- Plans ----------

    public function plans(bool $onlyActive = false): array
    {
        $rows = $this->db->all('SELECT * FROM sk_plans' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, kind, name');
        foreach ($rows as &$row) {
            $row['program_ids'] = $this->planPrograms((int) $row['id']);
        }
        return $rows;
    }

    public function plan(int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM sk_plans WHERE id = ?', [$id]);
        if ($row) {
            $row['program_ids'] = $this->planPrograms($id);
        }
        return $row;
    }

    public function planPrograms(int $planId): array
    {
        return array_map('intval', array_column($this->db->all('SELECT program_id FROM sk_plan_programs WHERE plan_id = ?', [$planId]), 'program_id'));
    }

    public function savePlan(?int $id, array $data, array $programIds): int
    {
        $data = Families::pick($data, self::PLAN_FIELDS);
        if (empty($data['name'])) {
            throw new ValidationException('El plan necesita un nombre.');
        }
        if (!isset($data['price']) || !is_numeric($data['price']) || (float) $data['price'] < 0) {
            throw new ValidationException('El precio del plan no es válido.');
        }
        foreach (['credits', 'validity_days'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = ($data[$k] === null || $data[$k] === '') ? null : (int) $data[$k];
            }
        }
        if (($data['credit_period'] ?? '') === 'validity' && empty($data['validity_days'])) {
            throw new ValidationException('Un plan con créditos por vigencia necesita los días de vigencia.');
        }
        return $this->db->transaction(function () use ($id, $data, $programIds) {
            if ($id === null) {
                $id = $this->db->insert('sk_plans', $data);
                $this->audit->log('plan', $id, 'create', null, $data);
            } else {
                $before = $this->plan($id) ?? throw new \RuntimeException('Plan no encontrado');
                unset($before['program_ids']);
                $this->db->update('sk_plans', $data, 'id = ?', [$id]);
                $this->audit->log('plan', $id, 'update', $before, $data);
            }
            $this->db->run('DELETE FROM sk_plan_programs WHERE plan_id = ?', [$id]);
            foreach (array_unique(array_map('intval', $programIds)) as $pid) {
                $this->db->insert('sk_plan_programs', ['plan_id' => $id, 'program_id' => $pid]);
            }
            return $id;
        });
    }

    // ---------- Subscriptions ----------

    public function get(int $id): ?array
    {
        $row = $this->db->one(
            'SELECT s.*, st.first_name, st.last_name FROM sk_subscriptions s JOIN sk_students st ON st.id = s.student_id WHERE s.id = ?',
            [$id]
        );
        if ($row) {
            $row['program_ids'] = array_map('intval', array_column($this->db->all('SELECT program_id FROM sk_subscription_programs WHERE subscription_id = ?', [$id]), 'program_id'));
        }
        return $row;
    }

    public function forStudent(int $studentId): array
    {
        return $this->db->all('SELECT * FROM sk_subscriptions WHERE student_id = ? ORDER BY FIELD(status, "active", "paused", "cancelled", "ended"), start_date DESC', [$studentId]);
    }

    public function forFamily(int $familyId): array
    {
        return $this->db->all(
            'SELECT s.*, st.first_name, st.last_name FROM sk_subscriptions s JOIN sk_students st ON st.id = s.student_id
             WHERE s.family_id = ? ORDER BY FIELD(s.status, "active", "paused", "cancelled", "ended"), s.start_date DESC',
            [$familyId]
        );
    }

    /**
     * @param array $opts price (custom price), discount_type, discount_value, discount_cycles,
     *                    payer_guardian_id, program_ids, notes, external_id
     */
    public function create(int $studentId, int $planId, string $startDate, array $opts = []): int
    {
        $student = $this->db->one('SELECT * FROM sk_students WHERE id = ?', [$studentId]) ?? throw new ValidationException('Alumno no encontrado.');
        $plan = $this->plan($planId) ?? throw new ValidationException('Plan no encontrado.');
        if (!(int) $plan['active']) {
            throw new ValidationException('El plan no está activo.');
        }
        self::validateDate($startDate);
        $discountType = $opts['discount_type'] ?? 'none';
        $discountValue = (float) ($opts['discount_value'] ?? 0);
        if (!in_array($discountType, ['none', 'fixed', 'percent'], true)) {
            throw new ValidationException('Tipo de descuento no válido.');
        }
        if ($discountType === 'percent' && ($discountValue < 0 || $discountValue > 100)) {
            throw new ValidationException('El descuento porcentual debe estar entre 0 y 100.');
        }
        $price = isset($opts['price']) && $opts['price'] !== '' && $opts['price'] !== null ? (float) $opts['price'] : (float) $plan['price'];
        if ($price < 0) {
            throw new ValidationException('El precio no puede ser negativo.');
        }

        $endDate = null;
        if ($plan['billing_period'] === 'once' && $plan['validity_days']) {
            $endDate = (new \DateTimeImmutable($startDate))->modify('+' . ((int) $plan['validity_days'] - 1) . ' days')->format('Y-m-d');
        }

        $payer = $opts['payer_guardian_id'] ?? $this->db->value('SELECT id FROM sk_guardians WHERE family_id = ? ORDER BY is_payer DESC, id LIMIT 1', [$student['family_id']]);
        $programIds = $opts['program_ids'] ?? $plan['program_ids'];
        if ($programIds === [] && $student['program_id']) {
            $programIds = [(int) $student['program_id']];
        }

        $row = [
            'student_id' => $studentId,
            'family_id' => $student['family_id'],
            'payer_guardian_id' => $payer ?: null,
            'plan_id' => $planId,
            'plan_name' => $plan['name'],
            'kind' => $plan['kind'],
            'price' => number_format($price, 2, '.', ''),
            'billing_period' => $plan['billing_period'],
            'credits' => $plan['credits'],
            'credit_period' => $plan['credit_period'],
            'validity_days' => $plan['validity_days'],
            'auto_renew' => $plan['auto_renew'],
            'discount_type' => $discountType,
            'discount_value' => number_format($discountValue, 2, '.', ''),
            'discount_cycles' => isset($opts['discount_cycles']) && $opts['discount_cycles'] !== '' ? (int) $opts['discount_cycles'] : null,
            'status' => 'active',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'next_billing_date' => $startDate,
            'notes' => $opts['notes'] ?? null,
            'external_id' => $opts['external_id'] ?? null,
            'created_by' => $this->audit->workerId(),
        ];
        return $this->db->transaction(function () use ($row, $programIds, $price, $plan) {
            $id = $this->db->insert('sk_subscriptions', $row);
            foreach (array_unique(array_map('intval', $programIds)) as $pid) {
                $this->db->insert('sk_subscription_programs', ['subscription_id' => $id, 'program_id' => $pid]);
            }
            $this->event($id, 'created', $row['start_date'], $price != (float) $plan['price'] ? 'Precio personalizado' : null);
            $this->audit->log('subscription', $id, 'create', null, $row);
            return $id;
        });
    }

    public function pause(int $id, string $from, ?string $until, string $reason = ''): void
    {
        self::validateDate($from);
        if ($until !== null) {
            self::validateDate($until);
            if ($until < $from) {
                throw new ValidationException('La fecha de reanudación debe ser posterior al inicio de la pausa.');
            }
        }
        $sub = $this->require($id);
        if (!in_array($sub['status'], ['active'], true)) {
            throw new ValidationException('Sólo se pueden pausar suscripciones activas.');
        }
        $this->db->update('sk_subscriptions', ['status' => 'paused', 'paused_from' => $from, 'paused_until' => $until], 'id = ?', [$id]);
        $this->event($id, 'paused', $from, trim(($until ? "hasta {$until}. " : '') . $reason));
        $this->audit->log('subscription', $id, 'pause', $sub, ['status' => 'paused', 'paused_from' => $from, 'paused_until' => $until]);
    }

    /**
     * Resumes a paused subscription. Billing that fell inside the pause moves to the resume date,
     * so the pause is not charged.
     */
    public function resume(int $id, string $date): void
    {
        self::validateDate($date);
        $sub = $this->require($id);
        if ($sub['status'] !== 'paused') {
            throw new ValidationException('La suscripción no está pausada.');
        }
        $next = $sub['next_billing_date'];
        if ($next !== null && $next < $date) {
            $next = $date;
        }
        $changes = ['status' => 'active', 'paused_from' => null, 'paused_until' => null, 'next_billing_date' => $next];
        $this->db->update('sk_subscriptions', $changes, 'id = ?', [$id]);
        $this->event($id, 'resumed', $date, null);
        $this->audit->log('subscription', $id, 'resume', $sub, $changes);
    }

    /** Cancels on $date (inclusive end of service is the day before). Future bookings after it are cancelled by the caller. */
    public function cancel(int $id, string $date, string $reason = ''): void
    {
        self::validateDate($date);
        $sub = $this->require($id);
        if (in_array($sub['status'], ['cancelled', 'ended'], true)) {
            throw new ValidationException('La suscripción ya está cancelada.');
        }
        $changes = ['status' => 'cancelled', 'cancel_at' => $date];
        if ($sub['next_billing_date'] !== null && $sub['next_billing_date'] >= $date) {
            $changes['next_billing_date'] = null;
        }
        $this->db->update('sk_subscriptions', $changes, 'id = ?', [$id]);
        $this->event($id, 'cancelled', $date, $reason ?: null);
        $this->audit->log('subscription', $id, 'cancel', $sub, $changes);
    }

    public function reactivate(int $id, string $date): void
    {
        self::validateDate($date);
        $sub = $this->require($id);
        if (!in_array($sub['status'], ['cancelled', 'ended'], true)) {
            throw new ValidationException('Sólo se reactivan suscripciones canceladas o terminadas.');
        }
        $changes = ['status' => 'active', 'cancel_at' => null, 'next_billing_date' => $sub['billing_period'] === 'once' ? null : $date];
        if ($sub['end_date'] !== null && $sub['end_date'] < $date) {
            $changes['end_date'] = null;
        }
        $this->db->update('sk_subscriptions', $changes, 'id = ?', [$id]);
        $this->event($id, 'reactivated', $date, null);
        $this->audit->log('subscription', $id, 'reactivate', $sub, $changes);
    }

    /** Changes the price of one contract for future invoices only. */
    public function changePrice(int $id, float $price, string $reason): void
    {
        if ($price < 0) {
            throw new ValidationException('El precio no puede ser negativo.');
        }
        $sub = $this->require($id);
        $this->db->update('sk_subscriptions', ['price' => number_format($price, 2, '.', '')], 'id = ?', [$id]);
        $this->event($id, 'price_changed', date('Y-m-d'), "{$sub['price']} → {$price}. {$reason}");
        $this->audit->log('subscription', $id, 'price_change', $sub, ['price' => number_format($price, 2, '.', '')]);
    }

    public function setDiscount(int $id, string $type, float $value, ?int $cycles): void
    {
        if (!in_array($type, ['none', 'fixed', 'percent'], true) || ($type === 'percent' && ($value < 0 || $value > 100))) {
            throw new ValidationException('Descuento no válido.');
        }
        $sub = $this->require($id);
        $changes = ['discount_type' => $type, 'discount_value' => number_format($value, 2, '.', ''), 'discount_cycles' => $cycles, 'discount_cycles_used' => 0];
        $this->db->update('sk_subscriptions', $changes, 'id = ?', [$id]);
        $this->audit->log('subscription', $id, 'discount', $sub, $changes);
    }

    /** Auto-resume paused subscriptions whose pause ended (run daily). */
    public function resumeDuePauses(string $today): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT id, paused_until FROM sk_subscriptions WHERE status = 'paused' AND paused_until IS NOT NULL AND paused_until < ?", [$today]) as $row) {
            $resumeOn = (new \DateTimeImmutable($row['paused_until']))->modify('+1 day')->format('Y-m-d');
            $this->resume((int) $row['id'], $resumeOn);
            $n++;
        }
        // Cancellations that reached their date
        $this->db->run("UPDATE sk_subscriptions SET status = 'ended' WHERE status = 'cancelled' AND cancel_at IS NOT NULL AND cancel_at <= ?", [$today]);
        $this->db->run("UPDATE sk_subscriptions SET status = 'ended' WHERE status = 'active' AND end_date IS NOT NULL AND end_date < ?", [$today]);
        return $n;
    }

    /** Amount of the next charge after discounts, in cents. */
    public static function chargeCents(array $sub): array
    {
        $price = Money::toCents($sub['price']);
        $discount = 0;
        $cyclesLeft = $sub['discount_cycles'] === null ? true : ((int) $sub['discount_cycles_used'] < (int) $sub['discount_cycles']);
        if ($cyclesLeft) {
            if ($sub['discount_type'] === 'fixed') {
                $discount = min($price, Money::toCents($sub['discount_value']));
            } elseif ($sub['discount_type'] === 'percent') {
                $discount = (int) round($price * ((float) $sub['discount_value']) / 100);
            }
        }
        return ['price' => $price, 'discount' => $discount, 'total' => $price - $discount];
    }

    public function events(int $id): array
    {
        return $this->db->all(
            'SELECT e.*, CONCAT(w.firstname, " ", w.lastname) AS worker_name FROM sk_subscription_events e
             LEFT JOIN workers w ON w.userid = e.worker_id WHERE e.subscription_id = ? ORDER BY e.id DESC',
            [$id]
        );
    }

    private function event(int $id, string $event, string $date, ?string $details): void
    {
        $this->db->insert('sk_subscription_events', [
            'subscription_id' => $id, 'event' => $event, 'effective_date' => $date, 'details' => $details, 'worker_id' => $this->audit->workerId(),
        ]);
    }

    private function require(int $id): array
    {
        return $this->db->one('SELECT * FROM sk_subscriptions WHERE id = ?', [$id]) ?? throw new ValidationException('Suscripción no encontrada.');
    }

    public static function validateDate(string $date): void
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new ValidationException("Fecha no válida: {$date}");
        }
    }
}

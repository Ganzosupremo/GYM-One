<?php
declare(strict_types=1);

namespace Sankris;

use DateTimeImmutable;

/**
 * Phase 4: invoices and payments are separate records joined by allocations.
 * Every money-creating operation takes an idempotency key, so a retry never duplicates it.
 */
final class Billing
{
    private Db $db;
    private Audit $audit;
    private Settings $settings;
    private string $currency;

    public const METHODS = ['cash' => 'Efectivo', 'transfer' => 'Transferencia', 'card_external' => 'Tarjeta (POS)', 'stripe' => 'Stripe'];

    public function __construct(Db $db, Audit $audit, Settings $settings, string $currency)
    {
        $this->db = $db;
        $this->audit = $audit;
        $this->settings = $settings;
        $this->currency = $currency;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    // ---------- Invoices ----------

    /**
     * @param array $lines each: description, amount (unit price), quantity?, discount?, student_id?, subscription_id?, period_start?, period_end?
     * @param array $opts due_date, issue_date, idempotency_key, notes, autopay, payer_guardian_id, external_id, number
     */
    public function createInvoice(int $familyId, array $lines, array $opts = []): int
    {
        if ($lines === []) {
            throw new ValidationException('La factura necesita al menos un concepto.');
        }
        $key = $opts['idempotency_key'] ?? null;
        if ($key !== null && ($existing = $this->db->value('SELECT id FROM sk_invoices WHERE idempotency_key = ?', [$key]))) {
            return (int) $existing;
        }
        $issue = $opts['issue_date'] ?? date('Y-m-d');
        $due = $opts['due_date'] ?? (new DateTimeImmutable($issue))->modify('+' . $this->settings->int('invoice_due_days', 5) . ' days')->format('Y-m-d');
        $payer = $opts['payer_guardian_id'] ?? $this->db->value('SELECT id FROM sk_guardians WHERE family_id = ? ORDER BY is_payer DESC, id LIMIT 1', [$familyId]);

        $subtotal = 0;
        $discountTotal = 0;
        $prepared = [];
        foreach ($lines as $line) {
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $unit = Money::toCents($line['amount']);
            $disc = Money::toCents($line['discount'] ?? 0);
            if (trim((string) ($line['description'] ?? '')) === '' || $unit < 0 || $disc < 0 || $disc > $unit * $qty) {
                throw new ValidationException('Concepto de factura no válido.');
            }
            $subtotal += $unit * $qty;
            $discountTotal += $disc;
            $prepared[] = [
                'description' => trim($line['description']), 'student_id' => $line['student_id'] ?? null,
                'subscription_id' => $line['subscription_id'] ?? null, 'quantity' => $qty,
                'unit_price' => Money::fromCents($unit), 'discount' => Money::fromCents($disc),
                'amount' => Money::fromCents($unit * $qty - $disc),
                'period_start' => $line['period_start'] ?? null, 'period_end' => $line['period_end'] ?? null,
            ];
        }

        try {
            return $this->db->transaction(function () use ($familyId, $payer, $issue, $due, $subtotal, $discountTotal, $prepared, $key, $opts) {
                $data = [
                    'number' => $opts['number'] ?? $this->nextNumber((int) substr($issue, 0, 4)),
                    'family_id' => $familyId, 'payer_guardian_id' => $payer ?: null, 'status' => 'open', 'currency' => $this->currency,
                    'issue_date' => $issue, 'due_date' => $due, 'subtotal' => Money::fromCents($subtotal),
                    'discount_total' => Money::fromCents($discountTotal), 'total' => Money::fromCents($subtotal - $discountTotal),
                    'amount_paid' => '0.00', 'idempotency_key' => $key, 'autopay' => (int) ($opts['autopay'] ?? 0),
                    'notes' => $opts['notes'] ?? null, 'external_id' => $opts['external_id'] ?? null, 'created_by' => $this->audit->workerId(),
                ];
                $id = $this->db->insert('sk_invoices', $data);
                foreach ($prepared as $line) {
                    $line['invoice_id'] = $id;
                    $this->db->insert('sk_invoice_lines', $line);
                }
                if ($subtotal - $discountTotal === 0) {
                    $this->db->update('sk_invoices', ['status' => 'paid'], 'id = ?', [$id]);
                }
                $this->audit->log('invoice', $id, 'create', null, $data);
                // Use any family credit (unallocated payments) right away
                $this->applyFamilyCredit($familyId);
                return $id;
            });
        } catch (\PDOException $e) {
            // A concurrent run created the same invoice first
            if ($key !== null && Db::isDuplicateKey($e) && ($existing = $this->db->value('SELECT id FROM sk_invoices WHERE idempotency_key = ?', [$key]))) {
                return (int) $existing;
            }
            throw $e;
        }
    }

    public function nextNumber(int $year): string
    {
        $name = 'invoice_' . $year;
        $this->db->run('INSERT INTO sk_sequences (name, value) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)', [$name]);
        $n = (int) $this->db->value('SELECT LAST_INSERT_ID()');
        return sprintf('%s-%d-%06d', $this->settings->get('invoice_prefix', 'SK'), $year, $n);
    }

    public function invoice(int $id): ?array
    {
        $inv = $this->db->one('SELECT i.*, f.name AS family_name FROM sk_invoices i JOIN sk_families f ON f.id = i.family_id WHERE i.id = ?', [$id]);
        if ($inv) {
            $inv['lines'] = $this->db->all('SELECT l.*, s.first_name, s.last_name FROM sk_invoice_lines l LEFT JOIN sk_students s ON s.id = l.student_id WHERE l.invoice_id = ? ORDER BY l.id', [$id]);
            $inv['allocations'] = $this->db->all('SELECT a.*, p.method, p.received_at, p.reference, p.stripe_payment_intent_id FROM sk_payment_allocations a JOIN sk_payments p ON p.id = a.payment_id WHERE a.invoice_id = ? ORDER BY a.id', [$id]);
            $inv['balance'] = Money::fromCents(Money::toCents($inv['total']) - Money::toCents($inv['amount_paid']));
        }
        return $inv;
    }

    public function invoices(array $filters = [], int $limit = 100): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['family_id'])) {
            $where[] = 'i.family_id = ?';
            $params[] = (int) $filters['family_id'];
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'overdue') {
                $where[] = "i.status = 'open' AND i.due_date < ?";
                $params[] = $filters['today'] ?? date('Y-m-d');
            } else {
                $where[] = 'i.status = ?';
                $params[] = $filters['status'];
            }
        }
        if (!empty($filters['q'])) {
            $where[] = '(i.number LIKE ? OR f.name LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }
        return $this->db->all(
            'SELECT i.*, f.name AS family_name, (i.total - i.amount_paid) AS balance,
                    (SELECT status FROM sk_payment_attempts a WHERE a.invoice_id = i.id ORDER BY a.attempt_no DESC LIMIT 1) AS last_attempt_status
             FROM sk_invoices i JOIN sk_families f ON f.id = i.family_id WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.issue_date DESC, i.id DESC LIMIT ' . (int) $limit,
            $params
        );
    }

    public function void(int $invoiceId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new ValidationException('Indica el motivo de la anulación.');
        }
        $this->db->transaction(function () use ($invoiceId, $reason) {
            $inv = $this->db->one('SELECT * FROM sk_invoices WHERE id = ? FOR UPDATE', [$invoiceId]) ?? throw new ValidationException('Factura no encontrada.');
            if (Money::toCents($inv['amount_paid']) > 0) {
                throw new ValidationException('La factura tiene pagos aplicados. Primero reembolsa o reasigna el pago.');
            }
            if ($inv['status'] === 'void') {
                return;
            }
            $this->db->update('sk_invoices', ['status' => 'void', 'voided_reason' => $reason], 'id = ?', [$invoiceId]);
            $this->audit->log('invoice', $invoiceId, 'void', ['status' => $inv['status']], ['status' => 'void', 'voided_reason' => $reason]);
        });
    }

    // ---------- Recurring charges ----------

    /**
     * Creates the invoices of every subscription whose billing date arrived.
     * Idempotent per subscription and period (key "sub:{id}:{date}").
     * @return int[] created invoice ids
     */
    public function generateRecurring(string $today): array
    {
        $lead = $this->settings->int('billing_lead_days', 0);
        $until = (new DateTimeImmutable($today))->modify("+{$lead} days")->format('Y-m-d');
        $created = [];
        $ids = array_column($this->db->all("SELECT id FROM sk_subscriptions WHERE status = 'active' AND next_billing_date IS NOT NULL AND next_billing_date <= ?", [$until]), 'id');
        foreach ($ids as $subId) {
            // Bill every period that is due (catches up if the job did not run for a while)
            for ($guard = 0; $guard < 24; $guard++) {
                $invoiceId = $this->db->transaction(function () use ($subId, $until, $today) {
                    $sub = $this->db->one('SELECT s.*, st.first_name, st.last_name FROM sk_subscriptions s JOIN sk_students st ON st.id = s.student_id WHERE s.id = ? FOR UPDATE', [$subId]);
                    if (!$sub || $sub['status'] !== 'active' || $sub['next_billing_date'] === null || $sub['next_billing_date'] > $until) {
                        return null;
                    }
                    $periodStart = $sub['next_billing_date'];
                    if ($sub['cancel_at'] !== null && $periodStart >= $sub['cancel_at']) {
                        $this->db->update('sk_subscriptions', ['next_billing_date' => null], 'id = ?', [$subId]);
                        return null;
                    }
                    $next = Period::addBilling($periodStart, $sub['billing_period']);
                    $periodEnd = $next !== null ? (new DateTimeImmutable($next))->modify('-1 day')->format('Y-m-d') : $sub['end_date'];
                    $charge = Subscriptions::chargeCents($sub);
                    $payer = $sub['payer_guardian_id'] ? $this->db->one('SELECT * FROM sk_guardians WHERE id = ?', [$sub['payer_guardian_id']]) : null;
                    $id = $this->createInvoice((int) $sub['family_id'], [[
                        'description' => sprintf('%s · %s %s%s', $sub['plan_name'], $sub['first_name'], $sub['last_name'], $periodEnd ? " ({$periodStart} a {$periodEnd})" : ''),
                        'amount' => Money::fromCents($charge['price']), 'discount' => Money::fromCents($charge['discount']),
                        'student_id' => $sub['student_id'], 'subscription_id' => $sub['id'],
                        'period_start' => $periodStart, 'period_end' => $periodEnd,
                    ]], [
                        'idempotency_key' => 'sub:' . $sub['id'] . ':' . $periodStart,
                        'issue_date' => min($periodStart, $today),
                        'payer_guardian_id' => $sub['payer_guardian_id'],
                        'autopay' => $payer && (int) $payer['autopay'] === 1 && $payer['stripe_payment_method_id'] ? 1 : 0,
                    ]);
                    $changes = ['next_billing_date' => $next];
                    if ($charge['discount'] > 0 && $sub['discount_cycles'] !== null) {
                        $changes['discount_cycles_used'] = (int) $sub['discount_cycles_used'] + 1;
                    }
                    if ($next !== null && (!(int) $sub['auto_renew'] && $sub['end_date'] !== null && $next > $sub['end_date'])) {
                        $changes['next_billing_date'] = null;
                    }
                    $this->db->update('sk_subscriptions', $changes, 'id = ?', [$subId]);
                    if ($next !== null) {
                        $this->db->insert('sk_subscription_events', ['subscription_id' => $subId, 'event' => 'renewed', 'effective_date' => $periodStart, 'details' => 'Factura #' . $id, 'worker_id' => $this->audit->workerId()]);
                    }
                    return $id;
                });
                if ($invoiceId === null) {
                    break;
                }
                $created[] = $invoiceId;
            }
        }
        return $created;
    }

    // ---------- Payments ----------

    /**
     * Records a payment and applies it to invoices.
     * @param array $opts guardian_id, received_at, reference, notes, idempotency_key, stripe_payment_intent_id,
     *                    stripe_charge_id, allocations ([invoice_id => amount]; default: oldest open invoices first)
     * @return int payment id (the existing one if the idempotency key or PaymentIntent was already recorded)
     */
    public function recordPayment(int $familyId, string $method, $amount, array $opts = []): int
    {
        if (!isset(self::METHODS[$method])) {
            throw new ValidationException('Método de pago no válido.');
        }
        $cents = Money::toCents($amount);
        if ($cents <= 0) {
            throw new ValidationException('El monto debe ser mayor que cero.');
        }
        $key = $opts['idempotency_key'] ?? null;
        $pi = $opts['stripe_payment_intent_id'] ?? null;
        if ($existing = $this->existingPayment($key, $pi)) {
            return $existing;
        }
        try {
            return $this->db->transaction(function () use ($familyId, $method, $cents, $opts, $key, $pi) {
                $data = [
                    'family_id' => $familyId, 'guardian_id' => $opts['guardian_id'] ?? null, 'method' => $method,
                    'amount' => Money::fromCents($cents), 'currency' => $this->currency,
                    'received_at' => $opts['received_at'] ?? date('Y-m-d H:i:s'), 'reference' => $opts['reference'] ?? null,
                    'stripe_payment_intent_id' => $pi, 'stripe_charge_id' => $opts['stripe_charge_id'] ?? null,
                    'idempotency_key' => $key, 'notes' => $opts['notes'] ?? null, 'external_id' => $opts['external_id'] ?? null,
                    'created_by' => $this->audit->workerId(),
                ];
                $paymentId = $this->db->insert('sk_payments', $data);
                $this->audit->log('payment', $paymentId, 'create', null, $data);
                if (isset($opts['allocations']) && is_array($opts['allocations'])) {
                    foreach ($opts['allocations'] as $invoiceId => $amt) {
                        $this->allocate($paymentId, (int) $invoiceId, Money::toCents($amt));
                    }
                } else {
                    $this->applyFamilyCredit($familyId);
                }
                return $paymentId;
            });
        } catch (\PDOException $e) {
            if (Db::isDuplicateKey($e) && ($existing = $this->existingPayment($key, $pi))) {
                return $existing;
            }
            throw $e;
        }
    }

    private function existingPayment(?string $key, ?string $pi): ?int
    {
        if ($key !== null && ($id = $this->db->value('SELECT id FROM sk_payments WHERE idempotency_key = ?', [$key]))) {
            return (int) $id;
        }
        if ($pi !== null && ($id = $this->db->value('SELECT id FROM sk_payments WHERE stripe_payment_intent_id = ?', [$pi]))) {
            return (int) $id;
        }
        return null;
    }

    /** Applies up to $cents of a payment's unallocated amount to one invoice. */
    public function allocate(int $paymentId, int $invoiceId, int $cents): int
    {
        return $this->db->transaction(function () use ($paymentId, $invoiceId, $cents) {
            $payment = $this->db->one('SELECT * FROM sk_payments WHERE id = ? FOR UPDATE', [$paymentId]) ?? throw new ValidationException('Pago no encontrado.');
            $inv = $this->db->one('SELECT * FROM sk_invoices WHERE id = ? FOR UPDATE', [$invoiceId]) ?? throw new ValidationException('Factura no encontrada.');
            if ((int) $inv['family_id'] !== (int) $payment['family_id']) {
                throw new ValidationException('El pago y la factura son de familias distintas.');
            }
            if ($inv['status'] !== 'open') {
                return 0;
            }
            $unallocated = $this->unallocatedCents($paymentId);
            $due = Money::toCents($inv['total']) - Money::toCents($inv['amount_paid']);
            $apply = min($cents, $unallocated, $due);
            if ($apply <= 0) {
                return 0;
            }
            $this->db->insert('sk_payment_allocations', ['payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'amount' => Money::fromCents($apply)]);
            $this->refreshInvoice($invoiceId);
            return $apply;
        });
    }

    /** Applies every unallocated payment of the family to its open invoices, oldest due first. */
    public function applyFamilyCredit(int $familyId): void
    {
        $this->db->transaction(function () use ($familyId) {
            $payments = $this->db->all('SELECT id FROM sk_payments WHERE family_id = ? ORDER BY received_at, id', [$familyId]);
            foreach ($payments as $p) {
                if ($this->unallocatedCents((int) $p['id']) <= 0) {
                    continue;
                }
                foreach ($this->db->all("SELECT id FROM sk_invoices WHERE family_id = ? AND status = 'open' ORDER BY due_date, id", [$familyId]) as $inv) {
                    $left = $this->unallocatedCents((int) $p['id']);
                    if ($left <= 0) {
                        break;
                    }
                    $this->allocate((int) $p['id'], (int) $inv['id'], $left);
                }
            }
        });
    }

    public function unallocatedCents(int $paymentId): int
    {
        $p = $this->db->one('SELECT amount, refunded_amount FROM sk_payments WHERE id = ?', [$paymentId]);
        if (!$p) {
            return 0;
        }
        $allocated = Money::toCents($this->db->value('SELECT COALESCE(SUM(amount), 0) FROM sk_payment_allocations WHERE payment_id = ?', [$paymentId]));
        return Money::toCents($p['amount']) - Money::toCents($p['refunded_amount']) - $allocated;
    }

    private function refreshInvoice(int $invoiceId): void
    {
        $paid = Money::toCents($this->db->value('SELECT COALESCE(SUM(amount), 0) FROM sk_payment_allocations WHERE invoice_id = ?', [$invoiceId]));
        $inv = $this->db->one('SELECT total, status FROM sk_invoices WHERE id = ?', [$invoiceId]);
        $status = $inv['status'];
        if ($status !== 'void') {
            $status = $paid >= Money::toCents($inv['total']) ? 'paid' : 'open';
        }
        $this->db->update('sk_invoices', ['amount_paid' => Money::fromCents($paid), 'status' => $status], 'id = ?', [$invoiceId]);
    }

    /**
     * Records a refund locally: lowers the payment, takes the amount back from the newest
     * allocations (reopening those invoices) and logs who did it.
     * The Stripe call, when needed, is done by StripeService before calling this.
     */
    public function recordRefund(int $paymentId, $amount, string $reason, string $idempotencyKey, ?string $stripeRefundId = null): int
    {
        $cents = Money::toCents($amount);
        if (trim($reason) === '') {
            throw new ValidationException('Indica el motivo del reembolso.');
        }
        if ($existing = $this->db->value('SELECT id FROM sk_refunds WHERE idempotency_key = ?' . ($stripeRefundId ? ' OR stripe_refund_id = ?' : ''), $stripeRefundId ? [$idempotencyKey, $stripeRefundId] : [$idempotencyKey])) {
            return (int) $existing;
        }
        return $this->db->transaction(function () use ($paymentId, $cents, $reason, $idempotencyKey, $stripeRefundId) {
            $p = $this->db->one('SELECT * FROM sk_payments WHERE id = ? FOR UPDATE', [$paymentId]) ?? throw new ValidationException('Pago no encontrado.');
            $refundable = Money::toCents($p['amount']) - Money::toCents($p['refunded_amount']);
            if ($cents <= 0 || $cents > $refundable) {
                throw new ValidationException('El monto a reembolsar supera lo disponible (' . Money::fromCents($refundable) . ').');
            }
            $id = $this->db->insert('sk_refunds', ['payment_id' => $paymentId, 'amount' => Money::fromCents($cents), 'reason' => $reason, 'stripe_refund_id' => $stripeRefundId, 'idempotency_key' => $idempotencyKey, 'worker_id' => $this->audit->workerId()]);
            $this->db->update('sk_payments', ['refunded_amount' => Money::fromCents(Money::toCents($p['refunded_amount']) + $cents)], 'id = ?', [$paymentId]);
            // Unallocated money is refunded first, then allocations from the newest invoice back
            $toTakeBack = max(0, $cents - max(0, $refundable - Money::toCents($this->db->value('SELECT COALESCE(SUM(amount), 0) FROM sk_payment_allocations WHERE payment_id = ?', [$paymentId]))));
            foreach ($this->db->all('SELECT * FROM sk_payment_allocations WHERE payment_id = ? ORDER BY id DESC', [$paymentId]) as $a) {
                if ($toTakeBack <= 0) {
                    break;
                }
                $take = min($toTakeBack, Money::toCents($a['amount']));
                $left = Money::toCents($a['amount']) - $take;
                if ($left > 0) {
                    $this->db->update('sk_payment_allocations', ['amount' => Money::fromCents($left)], 'id = ?', [$a['id']]);
                } else {
                    $this->db->run('DELETE FROM sk_payment_allocations WHERE id = ?', [$a['id']]);
                }
                $this->refreshInvoice((int) $a['invoice_id']);
                $toTakeBack -= $take;
            }
            $this->audit->log('payment', $paymentId, 'refund', null, ['refund_id' => $id, 'amount' => Money::fromCents($cents), 'reason' => $reason, 'stripe_refund_id' => $stripeRefundId]);
            return $id;
        });
    }

    public function payments(array $filters = [], int $limit = 100): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['family_id'])) {
            $where[] = 'p.family_id = ?';
            $params[] = (int) $filters['family_id'];
        }
        if (!empty($filters['method'])) {
            $where[] = 'p.method = ?';
            $params[] = $filters['method'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'p.received_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'p.received_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        return $this->db->all(
            'SELECT p.*, f.name AS family_name, (SELECT COALESCE(SUM(a.amount), 0) FROM sk_payment_allocations a WHERE a.payment_id = p.id) AS allocated
             FROM sk_payments p JOIN sk_families f ON f.id = p.family_id WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.received_at DESC, p.id DESC LIMIT ' . (int) $limit,
            $params
        );
    }

    /** @return array{debt:string, credit:string, balance:string} balance > 0 means the family owes money */
    public function familyBalance(int $familyId): array
    {
        $debt = Money::toCents($this->db->value("SELECT COALESCE(SUM(total - amount_paid), 0) FROM sk_invoices WHERE family_id = ? AND status = 'open'", [$familyId]));
        $credit = 0;
        foreach ($this->db->all('SELECT id FROM sk_payments WHERE family_id = ?', [$familyId]) as $p) {
            $credit += max(0, $this->unallocatedCents((int) $p['id']));
        }
        return ['debt' => Money::fromCents($debt), 'credit' => Money::fromCents($credit), 'balance' => Money::fromCents($debt - $credit)];
    }

    /** Open debt by days past due: current, 1-30, 31-60, 61-90, 90+. */
    public function aging(string $today): array
    {
        $rows = $this->db->all(
            "SELECT i.family_id, f.name AS family_name, i.due_date, (i.total - i.amount_paid) AS balance
             FROM sk_invoices i JOIN sk_families f ON f.id = i.family_id WHERE i.status = 'open'"
        );
        $buckets = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90plus' => 0];
        $families = [];
        foreach ($rows as $r) {
            $days = (int) (new DateTimeImmutable($r['due_date']))->diff(new DateTimeImmutable($today))->format('%r%a');
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'd90plus')));
            $cents = Money::toCents($r['balance']);
            $buckets[$bucket] += $cents;
            $fid = (int) $r['family_id'];
            $families[$fid] ??= ['family_id' => $fid, 'family_name' => $r['family_name'], 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90plus' => 0, 'total' => 0];
            $families[$fid][$bucket] += $cents;
            $families[$fid]['total'] += $cents;
        }
        usort($families, fn($a, $b) => $b['total'] <=> $a['total']);
        return ['buckets' => $buckets, 'families' => $families];
    }
}

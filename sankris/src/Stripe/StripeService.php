<?php
declare(strict_types=1);

namespace Sankris\Stripe;

use Sankris\Audit;
use Sankris\Billing;
use Sankris\Db;
use Sankris\Money;
use Sankris\Settings;
use Sankris\ValidationException;

/**
 * Stripe flows:
 *  - Save a card: Checkout in "setup" mode, link sent to the payer.
 *  - Pay one invoice: Checkout in "payment" mode.
 *  - Automatic charge: off-session PaymentIntent with the saved card, retried on a configurable schedule.
 *  - Webhooks reconcile everything; each event is processed once.
 */
final class StripeService
{
    private Db $db;
    private Audit $audit;
    private Billing $billing;
    private Settings $settings;
    private ?Gateway $gateway;
    private string $currency;
    private string $baseUrl;

    public function __construct(Db $db, Audit $audit, Billing $billing, Settings $settings, ?Gateway $gateway, string $currency, string $baseUrl)
    {
        $this->db = $db;
        $this->audit = $audit;
        $this->billing = $billing;
        $this->settings = $settings;
        $this->gateway = $gateway;
        $this->currency = strtolower($currency);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function enabled(): bool
    {
        return $this->gateway !== null;
    }

    private function gw(): Gateway
    {
        if ($this->gateway === null) {
            throw new ValidationException('Stripe no está configurado: agrega STRIPE_SECRET_KEY en el archivo .env.');
        }
        return $this->gateway;
    }

    public function ensureCustomer(int $guardianId): string
    {
        $g = $this->db->one('SELECT * FROM sk_guardians WHERE id = ?', [$guardianId]) ?? throw new ValidationException('Responsable no encontrado.');
        if (!empty($g['stripe_customer_id'])) {
            return $g['stripe_customer_id'];
        }
        $customer = $this->gw()->createCustomer([
            'name' => trim(($g['billing_name'] ?: $g['first_name'] . ' ' . $g['last_name'])),
            'email' => $g['email'] ?: null,
            'phone' => $g['phone'] ?: null,
            'metadata' => ['sankris_guardian_id' => $guardianId, 'sankris_family_id' => $g['family_id']],
        ], 'sk-guardian-' . $guardianId . '-customer');
        $this->db->update('sk_guardians', ['stripe_customer_id' => $customer['id']], 'id = ?', [$guardianId]);
        $this->audit->log('guardian', $guardianId, 'stripe_customer', null, ['stripe_customer_id' => $customer['id']]);
        return $customer['id'];
    }

    /** Link for the payer to save a card for automatic charges. */
    public function setupLink(int $guardianId): string
    {
        $customer = $this->ensureCustomer($guardianId);
        $session = $this->gw()->createCheckoutSession([
            'mode' => 'setup',
            'customer' => $customer,
            'payment_method_types' => ['card'],
            'success_url' => $this->baseUrl . '/sankris-pago/?estado=tarjeta-guardada',
            'cancel_url' => $this->baseUrl . '/sankris-pago/?estado=cancelado',
            'metadata' => ['sankris_purpose' => 'setup', 'sankris_guardian_id' => $guardianId],
            'setup_intent_data' => ['metadata' => ['sankris_guardian_id' => $guardianId]],
        ], 'sk-setup-g' . $guardianId . '-' . date('YmdH'));
        $this->saveLink($session, 'setup', $guardianId, null);
        return $session['url'];
    }

    /** Payment link for the current balance of an invoice. */
    public function invoicePayLink(int $invoiceId): string
    {
        $inv = $this->billing->invoice($invoiceId) ?? throw new ValidationException('Factura no encontrada.');
        $balance = Money::toCents($inv['balance']);
        if ($inv['status'] !== 'open' || $balance <= 0) {
            throw new ValidationException('La factura no tiene saldo pendiente.');
        }
        if (!$inv['payer_guardian_id']) {
            throw new ValidationException('La factura no tiene responsable pagador.');
        }
        $customer = $this->ensureCustomer((int) $inv['payer_guardian_id']);
        $meta = ['sankris_purpose' => 'invoice', 'sankris_invoice_id' => $invoiceId];
        $session = $this->gw()->createCheckoutSession([
            'mode' => 'payment',
            'customer' => $customer,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => $this->currency, 'unit_amount' => $balance, 'product_data' => ['name' => 'Factura ' . $inv['number'] . ' · ' . $inv['family_name']]],
            ]],
            'payment_intent_data' => ['metadata' => $meta, 'setup_future_usage' => 'off_session'],
            'metadata' => $meta,
            'success_url' => $this->baseUrl . '/sankris-pago/?estado=pagado',
            'cancel_url' => $this->baseUrl . '/sankris-pago/?estado=cancelado',
        ], 'sk-paylink-inv' . $invoiceId . '-' . $balance);
        $this->saveLink($session, 'invoice', (int) $inv['payer_guardian_id'], $invoiceId);
        return $session['url'];
    }

    private function saveLink(array $session, string $purpose, int $guardianId, ?int $invoiceId): void
    {
        try {
            $this->db->insert('sk_stripe_links', [
                'checkout_session_id' => $session['id'], 'purpose' => $purpose, 'guardian_id' => $guardianId,
                'invoice_id' => $invoiceId, 'url' => $session['url'], 'created_by' => $this->audit->workerId(),
            ]);
        } catch (\PDOException $e) {
            if (!Db::isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    /**
     * Charges the saved card for the invoice balance.
     * The attempt row is created first (unique per invoice and number), and its number is part of the
     * Stripe idempotency key: re-running the same attempt returns Stripe's first answer, never a second charge.
     * @return array{status:string, message:string}
     */
    public function chargeInvoice(int $invoiceId): array
    {
        $prep = $this->db->transaction(function () use ($invoiceId) {
            $inv = $this->db->one('SELECT * FROM sk_invoices WHERE id = ? FOR UPDATE', [$invoiceId]) ?? throw new ValidationException('Factura no encontrada.');
            $balance = Money::toCents($inv['total']) - Money::toCents($inv['amount_paid']);
            if ($inv['status'] !== 'open' || $balance <= 0) {
                return ['skip' => 'La factura no tiene saldo pendiente.'];
            }
            if ($this->db->value("SELECT id FROM sk_payment_attempts WHERE invoice_id = ? AND status = 'pending'", [$invoiceId])) {
                return ['skip' => 'Ya hay un cobro en curso para esta factura.'];
            }
            $g = $inv['payer_guardian_id'] ? $this->db->one('SELECT * FROM sk_guardians WHERE id = ?', [$inv['payer_guardian_id']]) : null;
            if (!$g || !$g['stripe_customer_id'] || !$g['stripe_payment_method_id']) {
                return ['skip' => 'El responsable pagador no tiene una tarjeta guardada en Stripe.'];
            }
            $n = (int) $this->db->value('SELECT COALESCE(MAX(attempt_no), 0) FROM sk_payment_attempts WHERE invoice_id = ?', [$invoiceId]) + 1;
            $attemptId = $this->db->insert('sk_payment_attempts', ['invoice_id' => $invoiceId, 'attempt_no' => $n, 'amount' => Money::fromCents($balance), 'status' => 'pending']);
            // An older failed attempt no longer needs its own retry
            $this->db->run('UPDATE sk_payment_attempts SET next_retry_at = NULL WHERE invoice_id = ? AND id <> ?', [$invoiceId, $attemptId]);
            return ['attempt_id' => $attemptId, 'attempt_no' => $n, 'balance' => $balance, 'inv' => $inv, 'guardian' => $g];
        });
        if (isset($prep['skip'])) {
            return ['status' => 'skipped', 'message' => $prep['skip']];
        }
        return $this->runAttempt($prep['attempt_id'], $prep['attempt_no'], $prep['balance'], $prep['inv'], $prep['guardian']);
    }

    private function runAttempt(int $attemptId, int $n, int $balance, array $inv, array $g): array
    {
        try {
            $pi = $this->gw()->createPaymentIntent([
                'amount' => $balance,
                'currency' => $this->currency,
                'customer' => $g['stripe_customer_id'],
                'payment_method' => $g['stripe_payment_method_id'],
                'off_session' => 'true',
                'confirm' => 'true',
                'description' => 'Factura ' . $inv['number'],
                'metadata' => ['sankris_invoice_id' => $inv['id'], 'sankris_attempt_no' => $n],
            ], 'sk-inv' . $inv['id'] . '-attempt' . $n);
        } catch (StripeError $e) {
            $pi = $e->paymentIntent();
            $this->attemptFailed($attemptId, $n, $pi['id'] ?? null, $e->getMessage());
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
        return $this->applyPaymentIntent($pi, $attemptId, $n);
    }

    private function applyPaymentIntent(array $pi, ?int $attemptId = null, ?int $n = null): array
    {
        $invoiceId = (int) ($pi['metadata']['sankris_invoice_id'] ?? 0);
        if ($attemptId === null && !empty($pi['id'])) {
            $row = $this->db->one('SELECT id, attempt_no FROM sk_payment_attempts WHERE stripe_payment_intent_id = ?', [$pi['id']]);
            if ($row === null && isset($pi['metadata']['sankris_attempt_no'])) {
                $row = $this->db->one('SELECT id, attempt_no FROM sk_payment_attempts WHERE invoice_id = ? AND attempt_no = ?', [$invoiceId, (int) $pi['metadata']['sankris_attempt_no']]);
            }
            if ($row) {
                $attemptId = (int) $row['id'];
                $n = (int) $row['attempt_no'];
            }
        }
        switch ($pi['status'] ?? '') {
            case 'succeeded':
                $this->recordStripePayment($pi, $invoiceId);
                if ($attemptId) {
                    $this->db->update('sk_payment_attempts', ['status' => 'succeeded', 'stripe_payment_intent_id' => $pi['id'], 'error' => null, 'next_retry_at' => null], 'id = ?', [$attemptId]);
                }
                return ['status' => 'succeeded', 'message' => 'Cobro aprobado.'];
            case 'requires_action':
            case 'requires_payment_method':
                $msg = ($pi['status'] === 'requires_action')
                    ? 'El banco pide autenticación del titular: envía el link de pago.'
                    : ($pi['last_payment_error']['message'] ?? 'La tarjeta fue rechazada.');
                if ($attemptId) {
                    $this->attemptFailed($attemptId, (int) $n, $pi['id'] ?? null, $msg, $pi['status'] === 'requires_action' ? 'requires_action' : 'failed');
                }
                return ['status' => 'failed', 'message' => $msg];
            default: // processing: the webhook will finish it
                if ($attemptId) {
                    $this->db->update('sk_payment_attempts', ['stripe_payment_intent_id' => $pi['id'] ?? null], 'id = ?', [$attemptId]);
                }
                return ['status' => 'pending', 'message' => 'Cobro en proceso.'];
        }
    }

    private function attemptFailed(int $attemptId, int $n, ?string $piId, string $error, string $status = 'failed'): void
    {
        $schedule = $this->settings->intList('retry_schedule_days', '1,3,7');
        $next = isset($schedule[$n - 1]) ? date('Y-m-d H:i:s', time() + $schedule[$n - 1] * 86400) : null;
        $this->db->update('sk_payment_attempts', [
            'status' => $status, 'stripe_payment_intent_id' => $piId, 'error' => mb_substr($error, 0, 250),
            'next_retry_at' => $status === 'requires_action' ? null : $next,
        ], 'id = ?', [$attemptId]);
    }

    private function recordStripePayment(array $pi, int $invoiceId): int
    {
        $inv = $invoiceId ? $this->db->one('SELECT * FROM sk_invoices WHERE id = ?', [$invoiceId]) : null;
        $familyId = $inv ? (int) $inv['family_id'] : (int) ($this->db->value('SELECT family_id FROM sk_guardians WHERE stripe_customer_id = ?', [$pi['customer'] ?? '']) ?? 0);
        if ($familyId === 0) {
            throw new \RuntimeException('Pago de Stripe sin factura ni cliente conocidos: ' . ($pi['id'] ?? '?'));
        }
        $received = $pi['amount_received'] ?? $pi['amount'];
        if (strtolower((string) ($pi['currency'] ?? $this->currency)) !== $this->currency) {
            throw new \RuntimeException('Moneda de Stripe distinta a la configurada: ' . ($pi['currency'] ?? '?'));
        }
        $guardianId = $this->db->value('SELECT id FROM sk_guardians WHERE stripe_customer_id = ?', [$pi['customer'] ?? '']);
        return $this->billing->recordPayment($familyId, 'stripe', Money::fromCents((int) $received), [
            'guardian_id' => $guardianId ? (int) $guardianId : null,
            'stripe_payment_intent_id' => $pi['id'],
            'stripe_charge_id' => is_string($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : ($pi['latest_charge']['id'] ?? null),
            'reference' => $pi['id'],
            'received_at' => isset($pi['created']) ? date('Y-m-d H:i:s', (int) $pi['created']) : null,
            'allocations' => $inv ? [$invoiceId => Money::fromCents((int) $received)] : null,
        ]);
    }

    /** Charges new autopay invoices and runs due retries. Safe to run repeatedly. */
    public function runCollections(?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $results = [];
        if (!$this->enabled()) {
            return $results;
        }
        $autopay = $this->db->all(
            "SELECT i.id FROM sk_invoices i WHERE i.status = 'open' AND i.autopay = 1 AND i.issue_date <= ?
               AND NOT EXISTS (SELECT 1 FROM sk_payment_attempts a WHERE a.invoice_id = i.id)",
            [substr($now, 0, 10)]
        );
        $retries = $this->db->all(
            "SELECT a.invoice_id AS id FROM sk_payment_attempts a JOIN sk_invoices i ON i.id = a.invoice_id
             WHERE a.status = 'failed' AND a.next_retry_at IS NOT NULL AND a.next_retry_at <= ? AND i.status = 'open'
               AND a.attempt_no = (SELECT MAX(attempt_no) FROM sk_payment_attempts a2 WHERE a2.invoice_id = a.invoice_id)",
            [$now]
        );
        foreach (array_merge($autopay, $retries) as $row) {
            $results[(int) $row['id']] = $this->chargeInvoice((int) $row['id']);
        }
        return $results;
    }

    /** Refunds a payment; for Stripe payments the refund is created in Stripe first. */
    public function refund(int $paymentId, $amount, string $reason, string $requestKey): int
    {
        $p = $this->db->one('SELECT * FROM sk_payments WHERE id = ?', [$paymentId]) ?? throw new ValidationException('Pago no encontrado.');
        $cents = Money::toCents($amount);
        $key = 'sk-refund-p' . $paymentId . '-' . preg_replace('/[^a-zA-Z0-9]/', '', $requestKey);
        $stripeRefundId = null;
        if ($p['method'] === 'stripe') {
            if ($cents <= 0 || $cents > Money::toCents($p['amount']) - Money::toCents($p['refunded_amount'])) {
                throw new ValidationException('Monto de reembolso no válido.');
            }
            $refund = $this->gw()->createRefund([
                'payment_intent' => $p['stripe_payment_intent_id'],
                'amount' => $cents,
                'metadata' => ['sankris_payment_id' => $paymentId, 'reason' => mb_substr($reason, 0, 200)],
            ], $key);
            $stripeRefundId = $refund['id'];
        }
        return $this->billing->recordRefund($paymentId, Money::fromCents($cents), $reason, $key, $stripeRefundId);
    }

    // ---------- Webhooks ----------

    /** @return string processed | duplicate | ignored */
    public function handleEvent(array $event): string
    {
        $id = (string) $event['id'];
        try {
            $this->db->insert('sk_stripe_events', ['event_id' => $id, 'type' => $event['type'], 'payload' => json_encode($event)]);
        } catch (\PDOException $e) {
            if (!Db::isDuplicateKey($e)) {
                throw $e;
            }
            if ($this->db->value('SELECT processed_at FROM sk_stripe_events WHERE event_id = ?', [$id])) {
                return 'duplicate';
            }
        }
        try {
            $result = $this->dispatch($event);
            $this->db->update('sk_stripe_events', ['processed_at' => date('Y-m-d H:i:s'), 'error' => null], 'event_id = ?', [$id]);
            return $result;
        } catch (\Throwable $e) {
            $this->db->update('sk_stripe_events', ['error' => mb_substr($e->getMessage(), 0, 250)], 'event_id = ?', [$id]);
            throw $e;
        }
    }

    private function dispatch(array $event): string
    {
        $obj = $event['data']['object'] ?? [];
        switch ($event['type']) {
            case 'checkout.session.completed':
                $this->db->update('sk_stripe_links', ['status' => 'completed'], 'checkout_session_id = ?', [$obj['id'] ?? '']);
                if (($obj['mode'] ?? '') === 'setup' && !empty($obj['setup_intent'])) {
                    $si = is_array($obj['setup_intent']) ? $obj['setup_intent'] : $this->gw()->retrieveSetupIntent($obj['setup_intent']);
                    $this->savePaymentMethod((string) ($obj['customer'] ?? $si['customer'] ?? ''), (string) $si['payment_method']);
                    return 'processed';
                }
                if (($obj['mode'] ?? '') === 'payment' && !empty($obj['payment_intent'])) {
                    $pi = is_array($obj['payment_intent']) ? $obj['payment_intent'] : $this->gw()->retrievePaymentIntent($obj['payment_intent']);
                    $this->applyPaymentIntent($pi);
                    return 'processed';
                }
                return 'ignored';
            case 'checkout.session.expired':
                $this->db->update('sk_stripe_links', ['status' => 'expired'], 'checkout_session_id = ?', [$obj['id'] ?? '']);
                return 'processed';
            case 'payment_intent.succeeded':
            case 'payment_intent.payment_failed':
                if (empty($obj['metadata']['sankris_invoice_id'])) {
                    return 'ignored';
                }
                $this->applyPaymentIntent($obj);
                return 'processed';
            case 'charge.refunded':
                return $this->syncRefunds($obj);
            default:
                return 'ignored';
        }
    }

    private function savePaymentMethod(string $customerId, string $paymentMethodId): void
    {
        $g = $this->db->one('SELECT * FROM sk_guardians WHERE stripe_customer_id = ?', [$customerId]);
        if (!$g) {
            throw new \RuntimeException('Cliente de Stripe desconocido: ' . $customerId);
        }
        $pm = $this->gw()->retrievePaymentMethod($paymentMethodId);
        $this->gw()->updateCustomer($customerId, ['invoice_settings' => ['default_payment_method' => $paymentMethodId]]);
        $changes = ['stripe_payment_method_id' => $paymentMethodId, 'card_brand' => $pm['card']['brand'] ?? null, 'card_last4' => $pm['card']['last4'] ?? null, 'autopay' => 1];
        $this->db->update('sk_guardians', $changes, 'id = ?', [$g['id']]);
        $this->audit->withActor(null, 'stripe')->log('guardian', (int) $g['id'], 'card_saved', ['stripe_payment_method_id' => $g['stripe_payment_method_id']], $changes);
    }

    /** Records refunds made directly in the Stripe dashboard. */
    private function syncRefunds(array $charge): string
    {
        $piId = is_string($charge['payment_intent'] ?? null) ? $charge['payment_intent'] : ($charge['payment_intent']['id'] ?? null);
        $payment = $piId ? $this->db->one('SELECT * FROM sk_payments WHERE stripe_payment_intent_id = ?', [$piId]) : null;
        if (!$payment) {
            return 'ignored';
        }
        $refunds = $charge['refunds']['data'] ?? [];
        foreach ($refunds as $r) {
            if (($r['status'] ?? 'succeeded') !== 'succeeded' || $this->db->value('SELECT id FROM sk_refunds WHERE stripe_refund_id = ?', [$r['id']])) {
                continue;
            }
            $this->billing->recordRefund((int) $payment['id'], Money::fromCents((int) $r['amount']), 'Reembolso hecho en Stripe', 'stripe-' . $r['id'], $r['id']);
        }
        return 'processed';
    }

    /** Compares local Stripe payments with Stripe (for the reconciliation page). */
    public function reconcile(string $from, string $to): array
    {
        $rows = $this->db->all(
            "SELECT p.*, f.name AS family_name FROM sk_payments p JOIN sk_families f ON f.id = p.family_id
             WHERE p.method = 'stripe' AND p.received_at BETWEEN ? AND ? ORDER BY p.received_at",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        $out = [];
        foreach ($rows as $p) {
            $remote = null;
            $issue = null;
            if ($this->enabled() && $p['stripe_payment_intent_id']) {
                try {
                    $remote = $this->gw()->retrievePaymentIntent($p['stripe_payment_intent_id']);
                    if (($remote['status'] ?? '') !== 'succeeded') {
                        $issue = 'Estado en Stripe: ' . ($remote['status'] ?? '?');
                    } elseif ((int) ($remote['amount_received'] ?? 0) !== Money::toCents($p['amount'])) {
                        $issue = 'Monto distinto en Stripe: ' . Money::fromCents((int) $remote['amount_received']);
                    }
                } catch (StripeError $e) {
                    $issue = $e->getMessage();
                }
            }
            $p['issue'] = $issue;
            $p['checked'] = $remote !== null;
            $out[] = $p;
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

use Sankris\App;
use Sankris\Db;
use Sankris\Stripe\Webhook;
use Sankris\Tests\FakeGateway;

test('recurring invoice generation is idempotent', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $app->subscriptions()->create($s, make_plan($app), '2026-11-01');
    eq(1, count($app->billing()->generateRecurring('2026-11-01')));
    eq(0, count($app->billing()->generateRecurring('2026-11-01')), 'second run creates nothing');
    eq(1, (int) $db->value('SELECT COUNT(*) FROM sk_invoices'));
    eq('2026-12-01', $db->value('SELECT next_billing_date FROM sk_subscriptions'));
});

test('invoice numbers are sequential per year', function (App $app) {
    $a = $app->billing()->nextNumber(2026);
    $b = $app->billing()->nextNumber(2026);
    eq(true, $a !== $b && substr($a, 0, 8) === 'SK-2026-');
});

test('partial payments, overpayment credit and family balance', function (App $app, Db $db) {
    [$f, $g] = make_family($app);
    $i1 = $app->billing()->createInvoice($f, [['description' => 'Inscripción anual', 'amount' => '585.00']]);
    $i2 = $app->billing()->createInvoice($f, [['description' => 'Baby Gym', 'amount' => '975.00']]);
    $app->billing()->recordPayment($f, 'cash', '500.00');
    eq('open', $app->billing()->invoice($i1)['status']);
    eq('85.00', $app->billing()->invoice($i1)['balance']);
    $app->billing()->recordPayment($f, 'transfer', '1100.00', ['reference' => 'BI-123']);
    eq('paid', $app->billing()->invoice($i1)['status']);
    eq('paid', $app->billing()->invoice($i2)['status']);
    $bal = $app->billing()->familyBalance($f);
    eq('40.00', $bal['credit'], 'overpayment stays as family credit');
    // The next invoice uses the credit automatically
    $i3 = $app->billing()->createInvoice($f, [['description' => 'Camiseta', 'amount' => '100.00']]);
    eq('60.00', $app->billing()->invoice($i3)['balance']);
});

test('payment with the same idempotency key is recorded once', function (App $app, Db $db) {
    [$f] = make_family($app);
    $a = $app->billing()->recordPayment($f, 'cash', '100', ['idempotency_key' => 'form-abc']);
    $b = $app->billing()->recordPayment($f, 'cash', '100', ['idempotency_key' => 'form-abc']);
    eq($a, $b);
    eq(1, (int) $db->value('SELECT COUNT(*) FROM sk_payments'));
});

test('refund reopens the invoice and is audited; voiding a paid invoice is refused', function (App $app, Db $db) {
    [$f] = make_family($app);
    $inv = $app->billing()->createInvoice($f, [['description' => 'Baby Gym', 'amount' => '975.00']]);
    $p = $app->billing()->recordPayment($f, 'cash', '975.00');
    throws('validation', fn() => $app->billing()->void($inv, 'error'));
    $app->stripe()->refund($p, '975.00', 'Baja médica', 'req1');
    $app->stripe()->refund($p, '975.00', 'Baja médica', 'req1'); // double submit
    eq(1, (int) $db->value('SELECT COUNT(*) FROM sk_refunds'));
    eq('open', $app->billing()->invoice($inv)['status']);
    eq(1, (int) $db->value("SELECT COUNT(*) FROM sk_audit_log WHERE entity = 'payment' AND action = 'refund'"));
    $app->billing()->void($inv, 'Baja');
    eq('void', $app->billing()->invoice($inv)['status']);
});

test('aging buckets open debt by days past due', function (App $app) {
    [$f] = make_family($app);
    $app->billing()->createInvoice($f, [['description' => 'A', 'amount' => '100']], ['issue_date' => '2026-08-15', 'due_date' => '2026-08-20']);
    $app->billing()->createInvoice($f, [['description' => 'B', 'amount' => '50']], ['issue_date' => '2026-10-25', 'due_date' => '2026-10-30']);
    $aging = $app->billing()->aging('2026-11-10');
    eq(5000, $aging['buckets']['d30']);
    eq(10000, $aging['buckets']['d90']);
});

function stripe_setup(App $app, Db $db, int $g): FakeGateway
{
    $fake = new FakeGateway();
    $app->setStripeGateway($fake);
    $db->update('sk_guardians', ['stripe_customer_id' => 'cus_test', 'stripe_payment_method_id' => 'pm_test', 'autopay' => 1], 'id = ?', [$g]);
    return $fake;
}

test('autopay charge succeeds once even if collections run twice', function (App $app, Db $db) {
    [, $g, $s] = make_family($app);
    $fake = stripe_setup($app, $db, $g);
    $app->subscriptions()->create($s, make_plan($app), '2026-11-01');
    [$inv] = $app->billing()->generateRecurring('2026-11-01');
    eq(1, (int) $db->value('SELECT autopay FROM sk_invoices WHERE id = ?', [$inv]));
    $r = $app->stripe()->runCollections('2026-11-01 08:00:00');
    eq('succeeded', $r[$inv]['status']);
    eq([], $app->stripe()->runCollections('2026-11-01 09:00:00'), 'nothing left to charge');
    eq(1, count($fake->paymentIntents));
    eq('paid', $app->billing()->invoice($inv)['status']);
    // The webhook for the same PaymentIntent does not record a second payment
    $pi = array_values($fake->paymentIntents)[0];
    $app->stripe()->handleEvent(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => $pi]]);
    eq('duplicate', $app->stripe()->handleEvent(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => $pi]]));
    eq(1, (int) $db->value('SELECT COUNT(*) FROM sk_payments'));
});

test('declined charge is retried on schedule and stops after the last retry', function (App $app, Db $db) {
    [$f, $g] = make_family($app);
    $fake = stripe_setup($app, $db, $g);
    $app->settings->set('retry_schedule_days', '1');
    $inv = $app->billing()->createInvoice($f, [['description' => 'Baby Gym', 'amount' => '975.00']], ['autopay' => 1]);
    $fake->outcomes = ['declined', 'declined'];
    $r = $app->stripe()->runCollections(date('Y-m-d H:i:s'));
    eq('failed', $r[$inv]['status']);
    $attempt = $db->one('SELECT * FROM sk_payment_attempts WHERE invoice_id = ?', [$inv]);
    eq(true, $attempt['next_retry_at'] !== null, 'retry scheduled');
    eq([], $app->stripe()->runCollections(date('Y-m-d H:i:s')), 'not before the retry time');
    $later = date('Y-m-d H:i:s', time() + 2 * 86400);
    $r2 = $app->stripe()->runCollections($later);
    eq('failed', $r2[$inv]['status']);
    eq(null, $db->value('SELECT next_retry_at FROM sk_payment_attempts WHERE invoice_id = ? AND attempt_no = 2', [$inv]), 'no more retries');
    eq([], $app->stripe()->runCollections(date('Y-m-d H:i:s', time() + 10 * 86400)));
    eq(2, count($fake->paymentIntents));
});

test('webhook signature is verified', function () {
    $payload = json_encode(['id' => 'evt_x', 'type' => 'ping']);
    $sig = Webhook::sign($payload, 'whsec_test', 1000);
    eq('evt_x', Webhook::verify($payload, $sig, 'whsec_test', 300, 1100)['id']);
    $failed = false;
    try {
        Webhook::verify($payload, $sig, 'whsec_other', 300, 1100);
    } catch (\Sankris\Stripe\StripeError $e) {
        $failed = true;
    }
    eq(true, $failed, 'wrong secret rejected');
});

test('checkout payment and saved card via webhooks', function (App $app, Db $db) {
    [$f, $g] = make_family($app);
    $fake = new FakeGateway();
    $app->setStripeGateway($fake);
    $inv = $app->billing()->createInvoice($f, [['description' => 'Inscripción', 'amount' => '585.00']]);
    $url = $app->stripe()->invoicePayLink($inv);
    eq(true, str_starts_with($url, 'https://checkout.stripe.test/'));
    $customer = $db->value('SELECT stripe_customer_id FROM sk_guardians WHERE id = ?', [$g]);
    $pi = ['id' => 'pi_web', 'status' => 'succeeded', 'amount' => 58500, 'amount_received' => 58500, 'currency' => 'gtq', 'customer' => $customer, 'metadata' => ['sankris_invoice_id' => $inv]];
    $fake->paymentIntents['pi_web'] = $pi;
    $app->stripe()->handleEvent(['id' => 'evt_cs', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'mode' => 'payment', 'payment_intent' => 'pi_web']]]);
    eq('paid', $app->billing()->invoice($inv)['status']);
    $app->stripe()->handleEvent(['id' => 'evt_setup', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_2', 'mode' => 'setup', 'customer' => $customer, 'setup_intent' => 'seti_1']]]);
    $guardian = $db->one('SELECT * FROM sk_guardians WHERE id = ?', [$g]);
    eq('4242', $guardian['card_last4']);
    eq(1, (int) $guardian['autopay']);
    // Refund made in the Stripe dashboard is synced once
    $charge = ['payment_intent' => 'pi_web', 'refunds' => ['data' => [['id' => 're_dash', 'amount' => 10000, 'status' => 'succeeded']]]];
    $app->stripe()->handleEvent(['id' => 'evt_r1', 'type' => 'charge.refunded', 'data' => ['object' => $charge]]);
    $app->stripe()->handleEvent(['id' => 'evt_r2', 'type' => 'charge.refunded', 'data' => ['object' => $charge]]);
    eq(1, (int) $db->value('SELECT COUNT(*) FROM sk_refunds'));
    eq('100.00', $app->billing()->invoice($inv)['balance']);
});

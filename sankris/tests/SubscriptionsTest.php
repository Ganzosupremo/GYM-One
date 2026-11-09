<?php
declare(strict_types=1);

use Sankris\App;
use Sankris\Db;
use Sankris\Period;

test('subscription keeps its price when the plan price changes', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $plan = make_plan($app);
    $sub = $app->subscriptions()->create($s, $plan, '2026-11-01');
    $app->subscriptions()->savePlan($plan, ['name' => 'Baby Gym 2x semana', 'price' => '1100.00'], [1]);
    eq('975.00', $app->subscriptions()->get($sub)['price']);
    eq('1100.00', $app->subscriptions()->plan($plan)['price']);
});

test('custom price and percent discount for a limited number of cycles', function (App $app, Db $db) {
    [$f, , $s] = make_family($app);
    $plan = make_plan($app);
    $sub = $app->subscriptions()->create($s, $plan, '2026-11-01', ['price' => '900', 'discount_type' => 'percent', 'discount_value' => '10', 'discount_cycles' => 1]);
    $ids = $app->billing()->generateRecurring('2026-12-01');
    eq(2, count($ids), 'Nov and Dec invoices');
    eq('810.00', $app->billing()->invoice($ids[0])['total'], 'first cycle has 10% off');
    eq('900.00', $app->billing()->invoice($ids[1])['total'], 'discount ended after one cycle');
});

test('credits are granted per month, independent of an annual billing period', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $plan = make_plan($app, ['name' => 'Anual 2x', 'price' => '10000', 'billing_period' => 'annual', 'credits' => 8, 'credit_period' => 'month']);
    $sub = $app->subscriptions()->get($app->subscriptions()->create($s, $plan, '2026-01-15'));
    eq(8, $app->credits()->available($sub, '2026-03-10'));
    eq(8, $app->credits()->available($sub, '2026-04-10'));
    eq(8, $app->credits()->available($sub, '2026-03-20'), 'grant is idempotent');
    eq(['2026-03-01', '2026-03-31'], $app->credits()->window($sub, '2026-03-10'));
    $ids = $app->billing()->generateRecurring('2026-06-01');
    eq(1, count($ids), 'only one annual invoice');
});

test('pause stops access and billing; resume moves billing to the resume date', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $plan = make_plan($app);
    $id = $app->subscriptions()->create($s, $plan, '2026-11-01');
    $app->billing()->generateRecurring('2026-11-01');
    $app->subscriptions()->pause($id, '2026-11-20', '2026-12-31', 'Viaje');
    eq(0, count($app->billing()->generateRecurring('2026-12-15')), 'no invoice while paused');
    eq(false, \Sankris\Credits::coversDate($app->subscriptions()->get($id), '2026-12-10'));
    $app->subscriptions()->resumeDuePauses('2027-01-02');
    $sub = $app->subscriptions()->get($id);
    eq('active', $sub['status']);
    eq('2027-01-01', $sub['next_billing_date']);
    eq(['resumed', 'paused', 'renewed', 'created'], array_column($app->subscriptions()->events($id), 'event'));
});

test('cancel stops future invoices; reactivate restarts billing', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $id = $app->subscriptions()->create($s, make_plan($app), '2026-11-01');
    $app->billing()->generateRecurring('2026-11-01');
    $app->subscriptions()->cancel($id, '2026-12-01', 'Se muda');
    eq(0, count($app->billing()->generateRecurring('2027-02-01')));
    $app->subscriptions()->reactivate($id, '2027-02-01');
    eq(1, count($app->billing()->generateRecurring('2027-02-01')));
});

test('month math keeps the anchor day and clamps to month end', function () {
    eq('2026-02-28', Period::addMonths('2026-01-31', 1));
    eq('2026-03-31', Period::addMonths('2026-01-31', 2));
    eq('2027-01-15', Period::addBilling('2026-01-15', 'annual'));
    eq(null, Period::addBilling('2026-01-15', 'once'));
});

test('the 2026 price list loads once, with enrollment linked to the gymnastics programs', function (App $app, Db $db) {
    $db->run('DELETE FROM sk_plan_programs');
    $db->run('DELETE FROM sk_plans');
    eq(15, \Sankris\Seed::plans2026($db));
    eq(0, \Sankris\Seed::plans2026($db), 'second run does nothing');
    $fee = $db->one("SELECT * FROM sk_plans WHERE name = 'Inscripción anual Gimnasia'");
    eq('585.00', $fee['price']);
    eq('annual', $fee['billing_period']);
    eq(5, count($app->subscriptions()->planPrograms((int) $fee['id'])));
});

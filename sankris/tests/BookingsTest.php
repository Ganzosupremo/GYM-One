<?php
declare(strict_types=1);

use Sankris\App;
use Sankris\Db;

// 2026-11-03 is a Tuesday. Baby 4-5 has Martes 16:00 (capacity 6).
const NOW = '2026-11-01 10:00:00';

test('booking consumes a credit and respects the monthly credit limit', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $sub = $app->subscriptions()->create($s, make_plan($app, ['credits' => 2]), '2026-11-01');
    $slot = slot_id($app, 1, 'Martes', '16:00:00');
    $app->bookings()->book($s, $slot, '2026-11-03', ['now' => NOW]);
    $app->bookings()->book($s, $slot, '2026-11-10', ['now' => NOW]);
    throws('no_credits', fn() => $app->bookings()->book($s, $slot, '2026-11-17', ['now' => NOW]));
    eq(0, $app->credits()->available($app->subscriptions()->get($sub), '2026-11-17'));
    // December has its own credits
    $app->bookings()->book($s, $slot, '2026-12-01', ['now' => NOW]);
});

test('capacity is enforced', function (App $app, Db $db) {
    $slot = slot_id($app, 1, 'Martes', '16:00:00');
    $plan = make_plan($app);
    for ($i = 0; $i < 6; $i++) {
        [, , $s] = make_family($app, 'Niña' . $i);
        $app->subscriptions()->create($s, $plan, '2026-11-01');
        $app->bookings()->book($s, $slot, '2026-11-03', ['now' => NOW]);
    }
    [, , $extra] = make_family($app, 'Extra');
    $app->subscriptions()->create($extra, $plan, '2026-11-01');
    throws('full', fn() => $app->bookings()->book($extra, $slot, '2026-11-03', ['now' => NOW]));
});

test('program must be included in the subscription', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $app->subscriptions()->create($s, make_plan($app), '2026-11-01'); // Baby only
    $start = slot_id($app, 2, 'Martes', '15:30:00');
    throws('no_subscription', fn() => $app->bookings()->book($s, $start, '2026-11-03', ['now' => NOW]));
});

test('cancel in time returns the credit; late cancel keeps it', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $sub = $app->subscriptions()->create($s, make_plan($app, ['credits' => 4]), '2026-11-01');
    $slot = slot_id($app, 1, 'Martes', '16:00:00');
    $b1 = $app->bookings()->book($s, $slot, '2026-11-03', ['now' => NOW]);
    eq('cancelled', $app->bookings()->cancel($b1, ['now' => '2026-11-02 10:00:00']));
    eq(4, $app->credits()->available($app->subscriptions()->get($sub), '2026-11-03'));
    $b2 = $app->bookings()->book($s, $slot, '2026-11-10', ['now' => NOW]);
    eq('late_cancelled', $app->bookings()->cancel($b2, ['now' => '2026-11-10 12:00:00']));
    eq(3, $app->credits()->available($app->subscriptions()->get($sub), '2026-11-10'));
});

test('re-booking a cancelled slot works and a credit refund happens only once', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $sub = $app->subscriptions()->create($s, make_plan($app, ['credits' => 4]), '2026-11-01');
    $slot = slot_id($app, 1, 'Martes', '16:00:00');
    $b = $app->bookings()->book($s, $slot, '2026-11-03', ['now' => NOW]);
    $app->bookings()->cancel($b, ['now' => NOW]);
    $b2 = $app->bookings()->book($s, $slot, '2026-11-03', ['now' => NOW]);
    eq($b, $b2, 'same row reused');
    eq(3, $app->credits()->available($app->subscriptions()->get($sub), '2026-11-03'));
});

test('cancelled session returns credits and blocks new bookings', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $sub = $app->subscriptions()->create($s, make_plan($app, ['credits' => 4]), '2026-11-01');
    $slot = slot_id($app, 1, 'Martes', '16:00:00');
    $app->bookings()->book($s, $slot, '2026-11-03', ['now' => NOW]);
    eq(1, $app->bookings()->cancelSession('2026-11-03', null, 'Asueto'));
    eq(4, $app->credits()->available($app->subscriptions()->get($sub), '2026-11-03'));
    throws('session_cancelled', fn() => $app->bookings()->book($s, $slot, '2026-11-03', ['now' => NOW]));
});

test('makeups are limited per month', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $app->subscriptions()->create($s, make_plan($app), '2026-11-01');
    $db->run('UPDATE programs SET makeups_per_month = 1 WHERE id = 1');
    $slot = slot_id($app, 1, 'Martes', '16:00:00');
    $app->bookings()->book($s, $slot, '2026-11-03', ['now' => NOW, 'source' => 'makeup']);
    throws('makeup_limit', fn() => $app->bookings()->book($s, $slot, '2026-11-10', ['now' => NOW, 'source' => 'makeup']));
});

test('booking window is enforced when requested', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $app->subscriptions()->create($s, make_plan($app), '2026-11-01');
    $slot = slot_id($app, 1, 'Martes', '16:00:00');
    throws('window', fn() => $app->bookings()->book($s, $slot, '2026-12-29', ['now' => NOW, 'enforce_window' => true]));
});

test('recurring bookings are generated once and failures become alerts', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $app->subscriptions()->create($s, make_plan($app, ['credits' => 2]), '2026-11-01');
    $slot = slot_id($app, 1, 'Martes', '16:00:00');
    $app->bookings()->addRecurring($s, $slot, '2026-11-01', null);
    $r1 = $app->bookings()->generateRecurring('2026-11-01', 21, NOW);
    eq(['created' => 2, 'alerts' => 1], $r1, 'Nov 3, 10 booked; Nov 17 has no credits');
    $r2 = $app->bookings()->generateRecurring('2026-11-01', 21, NOW);
    eq(['created' => 0, 'alerts' => 0], $r2, 'idempotent');
    eq(1, count($app->bookings()->openAlerts()));
});

test('attendance is recorded on the booking', function (App $app, Db $db) {
    [, , $s] = make_family($app);
    $app->subscriptions()->create($s, make_plan($app), '2026-11-01');
    $b = $app->bookings()->book($s, slot_id($app, 1, 'Martes', '16:00:00'), '2026-11-03', ['now' => NOW]);
    $app->bookings()->markAttendance($b, 'attended');
    eq('attended', $db->value('SELECT status FROM sk_bookings WHERE id = ?', [$b]));
});

test('evaluation classes need no subscription', function (App $app, Db $db) {
    $f = $app->families()->createFamily(['name' => 'Nueva']);
    $s = $app->families()->addStudent($f, ['first_name' => 'Ana', 'last_name' => 'Pérez', 'status' => 'trial']);
    $eval = (int) $db->value("SELECT id FROM timetable WHERE is_evaluation = 1 AND day_of_week = 'Martes' AND program_id = 1");
    $b = $app->bookings()->book($s, $eval, '2026-11-03', ['now' => NOW]);
    eq('evaluation', $db->value('SELECT source FROM sk_bookings WHERE id = ?', [$b]));
});

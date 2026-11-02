<?php
declare(strict_types=1);

use Sankris\App;
use Sankris\Db;

test('family can hold several students and guardians without duplicating the payer', function (App $app, Db $db) {
    [$f, $g, $s1] = make_family($app);
    $s2 = $app->families()->addStudent($f, ['first_name' => 'Lucía', 'last_name' => 'López', 'birthdate' => '2016-02-01']);
    $g2 = $app->families()->addGuardian($f, ['first_name' => 'José', 'last_name' => 'López', 'relationship' => 'Padre']);
    eq(2, count($app->families()->students($f)));
    eq(2, count($app->families()->guardians($f)));
    eq($g, (int) $app->families()->payer($f)['id'], 'first guardian is the payer');
    // Making the second guardian the payer removes the flag from the first
    $app->families()->updateGuardian($g2, ['is_payer' => 1]);
    eq(1, (int) $db->value('SELECT COUNT(*) FROM sk_guardians WHERE family_id = ? AND is_payer = 1', [$f]));
    eq($g2, (int) $app->families()->payer($f)['id']);
});

test('changes are audited and medical notes are redacted in the audit trail', function (App $app, Db $db) {
    [$f, , $s] = make_family($app);
    $app->families()->updateStudent($s, ['level' => 'Nivel 2', 'medical_notes' => 'Asma']);
    $row = $db->one("SELECT * FROM sk_audit_log WHERE entity = 'student' AND entity_id = ? AND action = 'update'", [$s]);
    $after = json_decode($row['after_json'], true);
    eq('Nivel 2', $after['level']);
    eq('[modificado]', $after['medical_notes']);
    eq(1, (int) $row['worker_id']);
});

test('invalid guardian email is rejected', function (App $app) {
    $f = $app->families()->createFamily(['name' => 'X']);
    throws('validation', fn() => $app->families()->addGuardian($f, ['first_name' => 'A', 'last_name' => 'B', 'email' => 'no-es-email']));
});

test('roles: reception cannot see medical notes or refund, coach can mark attendance', function () {
    eq(false, \Sankris\Auth::roleCan('recepcion', 'students.medical'));
    eq(false, \Sankris\Auth::roleCan('recepcion', 'billing.refund'));
    eq(true, \Sankris\Auth::roleCan('coach', 'attendance.mark'));
    eq(false, \Sankris\Auth::roleCan('coach', 'billing.view'));
});

<?php
declare(strict_types=1);

use Sankris\App;

/** Creates a family with a payer and one student; returns [familyId, guardianId, studentId]. */
function make_family(App $app, string $child = 'Sofía', string $birth = '2019-04-10', int $programId = 1): array
{
    $f = $app->families()->createFamily(['name' => 'Familia López']);
    $g = $app->families()->addGuardian($f, ['first_name' => 'María', 'last_name' => 'López', 'email' => 'maria@example.com', 'phone' => '+502 5555 0000']);
    $s = $app->families()->addStudent($f, ['first_name' => $child, 'last_name' => 'López', 'birthdate' => $birth, 'program_id' => $programId]);
    return [$f, $g, $s];
}

function make_plan(App $app, array $over = [], array $programs = [1]): int
{
    return $app->subscriptions()->savePlan(null, array_merge([
        'name' => 'Baby Gym 2x semana', 'kind' => 'membership', 'price' => '975.00', 'billing_period' => 'monthly',
        'credits' => 8, 'credit_period' => 'month', 'auto_renew' => 1, 'active' => 1,
    ], $over), $programs);
}

/** First timetable slot id for a program on a given Spanish weekday name. */
function slot_id(App $app, int $programId, string $day, string $time): int
{
    return (int) $app->db->value('SELECT id FROM timetable WHERE program_id = ? AND day_of_week = ? AND start_time = ? AND is_evaluation = 0', [$programId, $day, $time]);
}

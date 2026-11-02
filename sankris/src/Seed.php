<?php
declare(strict_types=1);

namespace Sankris;

/** Initial Sankris catalog (price list 2026). Loads only when there are programs and no plans yet. */
final class Seed
{
    /** @return int plans created */
    public static function plans2026(Db $db): int
    {
        if ((int) $db->value('SELECT COUNT(*) FROM sk_plans') > 0 || (int) $db->value('SELECT COUNT(*) FROM programs') === 0) {
            return 0;
        }
        $programs = array_column($db->all('SELECT id, name FROM programs'), 'id', 'name');
        $pid = fn(string $name) => isset($programs[$name]) ? [(int) $programs[$name]] : [];
        $gym = array_values(array_filter(array_map(fn($n) => $programs[$n] ?? null, [
            'Baby Gymnastics', 'Start Gymnastics', 'Intermediate Gymnastics', 'Advanced Gymnastics - Recreativo', 'Advanced Gymnastics - Competitivo',
        ])));
        // name, kind, price, billing_period, credits per month, programs
        $plans = [
            ['Inscripción anual Gimnasia', 'fee', 585, 'annual', null, $gym],
            ['Inscripción anual Tae Kwon Do', 'fee', 300, 'annual', null, $pid('Tae Kwon Do')],
            ['Inscripción anual Golden Gym', 'fee', 450, 'annual', null, $pid('Golden Gymnastics')],
            ['Baby Gym - 1 clase por semana', 'membership', 770, 'monthly', 4, $pid('Baby Gymnastics')],
            ['Baby Gym - 2 clases por semana', 'membership', 975, 'monthly', 8, $pid('Baby Gymnastics')],
            ['Start - 1 clase por semana', 'membership', 770, 'monthly', 4, $pid('Start Gymnastics')],
            ['Start - 2 clases por semana', 'membership', 975, 'monthly', 8, $pid('Start Gymnastics')],
            ['Start - 3 clases por semana', 'membership', 1200, 'monthly', 12, $pid('Start Gymnastics')],
            ['Intermediate - 2 clases por semana', 'membership', 975, 'monthly', 8, $pid('Intermediate Gymnastics')],
            ['Intermediate - 3 clases por semana', 'membership', 1200, 'monthly', 12, $pid('Intermediate Gymnastics')],
            ['Advanced Recreativo - 2 clases por semana', 'membership', 975, 'monthly', 8, $pid('Advanced Gymnastics - Recreativo')],
            ['Advanced Competitivo - 3 clases por semana', 'membership', 1500, 'monthly', 12, $pid('Advanced Gymnastics - Competitivo')],
            ['Tae Kwon Do - 1 clase por semana', 'membership', 450, 'monthly', 4, $pid('Tae Kwon Do')],
            ['Golden Gym - 1 clase por semana', 'membership', 450, 'monthly', 4, $pid('Golden Gymnastics')],
            ['Golden Gym - 2 clases por semana', 'membership', 575, 'monthly', 8, $pid('Golden Gymnastics')],
        ];
        return $db->transaction(function () use ($db, $plans) {
            foreach ($plans as [$name, $kind, $price, $period, $credits, $programIds]) {
                $id = $db->insert('sk_plans', [
                    'name' => $name, 'kind' => $kind, 'price' => $price, 'billing_period' => $period, 'credits' => $credits,
                    'credit_period' => $kind === 'fee' ? 'billing' : 'month', 'auto_renew' => 1, 'is_public' => 1, 'active' => 1,
                ]);
                foreach ($programIds as $p) {
                    $db->insert('sk_plan_programs', ['plan_id' => $id, 'program_id' => (int) $p]);
                }
            }
            return count($plans);
        });
    }
}

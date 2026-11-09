<?php
/**
 * Sankris daily job. Run once a day (early morning), e.g. cron:
 *   15 5 * * * php /path/to/gymone/sankris/bin/daily.php >> /var/log/sankris-daily.log 2>&1
 * Every step is idempotent: running it twice the same day creates nothing twice.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

/** @var \Sankris\App $app */
$app = require __DIR__ . '/../bootstrap.php';
$today = $argv[1] ?? date('Y-m-d');
$log = fn(string $msg) => fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);

$lock = fopen(sys_get_temp_dir() . '/sankris-daily.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('Otra ejecución sigue en curso; salgo.');
    exit(0);
}

$failed = false;
$step = function (string $name, callable $fn) use ($log, &$failed): void {
    try {
        $log($name . ': ' . $fn());
    } catch (\Throwable $e) {
        $failed = true;
        $log($name . ' FALLÓ: ' . $e->getMessage());
    }
};

$step('Migraciones', fn() => implode(', ', $app->migrator()->migrate()) ?: 'al día');
$step('Pausas que terminan', fn() => $app->subscriptions()->resumeDuePauses($today) . ' reanudadas');
$step('Facturas de suscripciones', fn() => count($app->billing()->generateRecurring($today)) . ' creadas');
$step('Cobros con tarjeta y reintentos', function () use ($app) {
    if (!$app->stripe()->enabled()) {
        return 'Stripe no configurado';
    }
    $results = $app->stripe()->runCollections();
    $by = array_count_values(array_column($results, 'status'));
    return count($results) . ' intentos ' . json_encode($by);
});
$step('Reservas fijas', function () use ($app, $today) {
    $r = $app->bookings()->generateRecurring($today, $app->settings->int('recurring_booking_horizon_days', 14));
    return $r['created'] . ' creadas, ' . $r['alerts'] . ' alertas';
});

exit($failed ? 1 : 0);

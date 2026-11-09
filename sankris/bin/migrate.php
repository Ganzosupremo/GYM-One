<?php
// Applies pending Sankris migrations: php sankris/bin/migrate.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
/** @var \Sankris\App $app */
$app = require __DIR__ . '/../bootstrap.php';
$done = $app->migrator()->migrate();
echo $done ? 'Aplicadas: ' . implode(', ', $done) . PHP_EOL : 'La base de datos ya está al día.' . PHP_EOL;

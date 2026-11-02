<?php
/**
 * Sankris module bootstrap: autoloader + shared services.
 * Usage: $app = require __DIR__ . '/../../sankris/bootstrap.php';
 */
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Sankris\\', 8) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set(getenv('SANKRIS_TZ') ?: 'America/Guatemala');

return \Sankris\App::boot(dirname(__DIR__));

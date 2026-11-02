<?php
/**
 * Runs the Sankris module tests against a throwaway MySQL/MariaDB database.
 *   php sankris/tests/run.php
 * Uses the DB credentials from .env and creates/drops the database "<DB_NAME>_sk_test".
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/FakeGateway.php';
require __DIR__ . '/helpers.php';

use Sankris\App;
use Sankris\Auth;
use Sankris\Db;
use Sankris\Env;

$root = dirname(__DIR__, 2);
$env = Env::read($root . '/.env');
$testDb = ($env['DB_NAME'] ?? 'gymone') . '_sk_test';
$pdo = new PDO('mysql:host=' . ($env['DB_SERVER'] ?? 'localhost') . ';charset=utf8mb4', $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '');
$pdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
$pdo->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$testDb}`");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// GYM One base schema, then the Sankris migrations
$init = (string) file_get_contents($root . '/assets/SQL/Init.sql');
$init = preg_replace('/^(CREATE DATABASE|USE) .*$/m', '', $init);
foreach (\Sankris\Migrator::splitSql($init) as $stmt) {
    if (!preg_match('/^\s*\/\*!/', $stmt)) {
        $pdo->exec($stmt);
    }
}
$db = new Db($pdo);

function fresh_app(Db $db, string $root, array $env): App
{
    $app = App::boot($root, $db, $env);
    $app->setAuth(new Auth(['userid' => 1, 'firstname' => 'Test', 'lastname' => 'Admin', 'username' => 'admin', 'is_boss' => 1], 'admin'));
    return $app;
}

$app = fresh_app($db, $root, $env);
$applied = $app->migrator()->migrate();
$pdo->exec(file_get_contents($root . '/assets/SQL/sankris_seed.sql'));

$tests = [];
function test(string $name, callable $fn): void
{
    global $tests;
    $tests[$name] = $fn;
}
function eq($expected, $actual, string $msg = ''): void
{
    if ($expected != $actual) {
        throw new RuntimeException(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function throws(string $code, callable $fn): void
{
    try {
        $fn();
    } catch (\Sankris\BookingException $e) {
        eq($code, $e->reasonCode, 'booking reason');
        return;
    } catch (\Sankris\ValidationException $e) {
        if ($code === 'validation') {
            return;
        }
        throw $e;
    }
    throw new RuntimeException("expected exception {$code}");
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$failed = 0;
foreach ($tests as $name => $fn) {
    $pdo->beginTransaction();
    try {
        $app = fresh_app($db, $root, $env);
        $fn($app, $db);
        echo "  ok   {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "  FAIL {$name}\n       " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
$pdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
echo "\n" . count($tests) . ' tests, ' . $failed . " failed\n";
exit($failed > 0 ? 1 : 0);

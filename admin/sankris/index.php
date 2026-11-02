<?php
/**
 * Sankris module (families, plans, bookings, billing, Stripe).
 * Front controller: ?p=<page> renders sankris/views/<page>.php
 */
declare(strict_types=1);

session_start();
if (!isset($_SESSION['adminuser'])) {
    header('Location: ../');
    exit;
}

/** @var \Sankris\App $app */
$app = require __DIR__ . '/../../sankris/bootstrap.php';
$viewsDir = __DIR__ . '/../../sankris/views';
require $viewsDir . '/_helpers.php';
require $viewsDir . '/_layout.php';

// Tables must exist before any page runs
if ($app->migrator()->pending() !== []) {
    $app->migrator()->migrate();
}

$auth = $app->auth();
$app->setAuth($auth);
if (!$auth->loggedIn()) {
    header('Location: ../');
    exit;
}

$page = preg_replace('/[^a-z_]/', '', (string) ($_GET['p'] ?? 'home'));
$file = $viewsDir . '/' . $page . '.php';
if ($page === '' || $page[0] === '_' || !is_file($file)) {
    $page = 'home';
    $file = $viewsDir . '/home.php';
}
require $file;

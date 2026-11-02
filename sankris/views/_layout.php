<?php
declare(strict_types=1);

/** @var \Sankris\App $app */

function sk_nav(): array
{
    return [
        ['home', 'bi-speedometer2', 'Resumen', null],
        ['families', 'bi-people', 'Familias y alumnos', 'families.view'],
        ['schedule', 'bi-calendar-week', 'Clases y asistencia', 'schedule.view'],
        ['alerts', 'bi-exclamation-triangle', 'Alertas de reservas', 'bookings.edit'],
        ['plans', 'bi-card-checklist', 'Planes', 'plans.edit'],
        ['invoices', 'bi-receipt', 'Facturas', 'billing.view'],
        ['payments', 'bi-cash-coin', 'Pagos', 'billing.view'],
        ['reconcile', 'bi-arrow-left-right', 'Conciliación Stripe', 'billing.refund'],
        ['settings', 'bi-sliders', 'Reglas y Stripe', 'settings.edit'],
        ['staff', 'bi-person-badge', 'Roles del personal', 'staff.edit'],
        ['audit', 'bi-clock-history', 'Historial de cambios', 'audit.view'],
    ];
}

function sk_header(string $title, string $active): void
{
    global $app;
    $GLOBALS['sk_header_done'] = true;
    $auth = $app->auth();
    $business = $app->env['BUSINESS_NAME'] ?? 'Sankris';
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · <?= h($business) ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/sankris-admin.css">
    <link rel="shortcut icon" href="../../assets/img/brand/favicon.png" type="image/x-icon">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</head>
<body>
<nav class="navbar navbar-inverse visible-xs">
    <div class="container-fluid">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#skNav">
                <span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span>
            </button>
            <a class="navbar-brand" href="?p=home"><img src="../../assets/img/brand/logo-white.png" width="50" alt="Logo"></a>
        </div>
        <div class="collapse navbar-collapse" id="skNav">
            <ul class="nav navbar-nav">
                <?php foreach (sk_nav() as [$p, $icon, $label, $perm]): if ($perm && !$auth->can($perm)) continue; ?>
                    <li class="<?= $p === $active ? 'active' : '' ?>"><a href="<?= url($p) ?>"><i class="bi <?= $icon ?>"></i> <?= h($label) ?></a></li>
                <?php endforeach; ?>
                <li><a href="../dashboard/"><i class="bi bi-arrow-left"></i> Panel GYM One</a></li>
            </ul>
        </div>
    </div>
</nav>
<div class="container-fluid">
    <div class="row content">
        <div class="col-sm-2 sidenav hidden-xs text-center">
            <h2><img src="../../assets/img/brand/logo-white.png" width="105" alt="Logo"></h2>
            <p class="lead mb-4 fs-4"><?= h($business) ?></p>
            <ul class="nav nav-pills nav-stacked">
                <?php foreach (sk_nav() as [$p, $icon, $label, $perm]): if ($perm && !$auth->can($perm)) continue; ?>
                    <li class="sidebar-item <?= $p === $active ? 'active' : '' ?>"><a class="sidebar-link" href="<?= url($p) ?>"><i class="bi <?= $icon ?>"></i> <?= h($label) ?></a></li>
                <?php endforeach; ?>
                <li class="sidebar-header">GYM One</li>
                <li class="sidebar-item"><a class="sidebar-link" href="../dashboard/"><i class="bi bi-arrow-left"></i> Panel GYM One</a></li>
            </ul>
        </div>
        <div class="col-sm-10 sk-main">
            <div class="sk-topbar">
                <h3><?= h($title) ?></h3>
                <span class="text-muted"><i class="bi bi-person-circle"></i> <?= h($auth->name()) ?> · <?= h(\Sankris\Auth::ROLES[$auth->role()] ?? $auth->role()) ?></span>
            </div>
            <?php foreach ($_SESSION['sk_flash'] ?? [] as [$type, $msg]): ?>
                <div class="alert alert-<?= h($type) ?>"><?= h($msg) ?></div>
            <?php endforeach; unset($_SESSION['sk_flash']); ?>
    <?php
}

function sk_footer(): void
{
    ?>
        </div>
    </div>
</div>
</body>
</html>
    <?php
}

<?php
/** @var \Sankris\App $app */
$today = date('Y-m-d');
$db = $app->db;
$aging = $app->auth()->can('billing.view') ? $app->billing()->aging($today) : null;
$sessions = $app->schedule()->sessionsOn($today);
$counts = $app->bookings()->counts($today);
$stats = [
    'families' => (int) $db->value("SELECT COUNT(*) FROM sk_families WHERE status = 'active'"),
    'students' => (int) $db->value("SELECT COUNT(*) FROM sk_students WHERE status IN ('active','trial')"),
    'subs' => (int) $db->value("SELECT COUNT(*) FROM sk_subscriptions WHERE status = 'active'"),
    'alerts' => count($app->bookings()->openAlerts()),
];
$failed = $app->auth()->can('billing.view') ? $db->all(
    "SELECT a.*, i.number, f.name AS family_name FROM sk_payment_attempts a JOIN sk_invoices i ON i.id = a.invoice_id JOIN sk_families f ON f.id = i.family_id
     WHERE a.status IN ('failed','requires_action') AND i.status = 'open'
       AND a.attempt_no = (SELECT MAX(attempt_no) FROM sk_payment_attempts a2 WHERE a2.invoice_id = a.invoice_id) ORDER BY a.updated_at DESC LIMIT 10"
) : [];
sk_header('Resumen', 'home');
?>
<div class="row">
    <div class="col-sm-3"><div class="sk-stat"><div class="v"><?= $stats['families'] ?></div><div class="l">Familias activas</div></div></div>
    <div class="col-sm-3"><div class="sk-stat blue"><div class="v"><?= $stats['students'] ?></div><div class="l">Alumnos activos o en prueba</div></div></div>
    <div class="col-sm-3"><div class="sk-stat pink"><div class="v"><?= $stats['subs'] ?></div><div class="l">Suscripciones activas</div></div></div>
    <div class="col-sm-3"><div class="sk-stat warn"><div class="v"><?= $stats['alerts'] ?></div><div class="l"><a href="<?= url('alerts') ?>">Reservas fijas fallidas</a></div></div></div>
</div>
<div class="row">
    <div class="col-md-6">
        <div class="sk-card">
            <h4>Clases de hoy</h4>
            <?php if (!$sessions): ?><p class="sk-muted">No hay clases hoy.</p><?php endif; ?>
            <table class="table table-condensed">
                <?php foreach ($sessions as $s): $c = $counts[(int) $s['id']] ?? ['booked' => 0, 'attended' => 0]; ?>
                    <tr class="<?= $s['exception'] ? 'text-muted' : '' ?>">
                        <td><?= h(substr($s['start_time'], 0, 5)) ?>–<?= h(substr($s['end_time'], 0, 5)) ?></td>
                        <td><a href="<?= url('session', ['t' => $s['id'], 'd' => $today]) ?>"><?= h($s['event_name']) ?></a><?= $s['exception'] ? ' <span class="label label-default">Cancelada</span>' : '' ?></td>
                        <td class="text-right"><?= (int) $c['booked'] ?><?= $s['capacity'] ? ' / ' . (int) $s['capacity'] : '' ?> reservas</td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <?php if ($aging): $b = $aging['buckets']; ?>
    <div class="col-md-6">
        <div class="sk-card">
            <h4>Deuda pendiente</h4>
            <table class="table table-condensed">
                <tr><td>Al día (sin vencer)</td><td class="text-right"><?= money($b['current'] / 100) ?></td></tr>
                <tr><td>Vencida 1–30 días</td><td class="text-right"><?= money($b['d30'] / 100) ?></td></tr>
                <tr><td>Vencida 31–60 días</td><td class="text-right"><?= money($b['d60'] / 100) ?></td></tr>
                <tr><td>Vencida 61–90 días</td><td class="text-right"><?= money($b['d90'] / 100) ?></td></tr>
                <tr><td>Más de 90 días</td><td class="text-right"><?= money($b['d90plus'] / 100) ?></td></tr>
            </table>
            <a href="<?= url('invoices', ['status' => 'overdue']) ?>" class="btn btn-default btn-sm">Ver facturas vencidas</a>
        </div>
        <?php if ($failed): ?>
        <div class="sk-card">
            <h4>Cobros con tarjeta fallidos</h4>
            <table class="table table-condensed">
                <?php foreach ($failed as $f): ?>
                    <tr><td><a href="<?= url('invoice', ['id' => $f['invoice_id']]) ?>"><?= h($f['number']) ?></a></td><td><?= h($f['family_name']) ?></td>
                        <td><?= status_label($f['status']) ?> <small class="sk-muted"><?= h($f['error']) ?></small></td>
                        <td><?= $f['next_retry_at'] ? 'Reintento ' . fdate($f['next_retry_at']) : 'Sin más reintentos' ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php sk_footer();

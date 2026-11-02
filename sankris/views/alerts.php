<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('bookings.edit') || deny();

on_post('resolve', function (array $in) use ($app) {
    $app->bookings()->resolveAlert((int) $in['alert_id']);
    $app->audit()->log('booking_alert', (int) $in['alert_id'], 'resolve');
    flash('success', 'Alerta marcada como resuelta.');
    redirect('alerts');
});
on_post('retry', function (array $in) use ($app) {
    $alert = $app->db->one('SELECT * FROM sk_booking_alerts WHERE id = ? AND resolved = 0', [(int) $in['alert_id']]);
    if ($alert) {
        $app->bookings()->book((int) $alert['student_id'], (int) $alert['timetable_id'], $alert['session_date'], ['source' => 'recurring', 'recurring_id' => $alert['recurring_id']]);
        $app->bookings()->resolveAlert((int) $alert['id']);
        flash('success', 'Reserva creada.');
    }
    redirect('alerts');
});
$alerts = $app->bookings()->openAlerts();
sk_header('Alertas de reservas fijas', 'alerts');
?>
<div class="sk-card">
    <p class="sk-muted">Cuando la tarea diaria no puede crear una reserva fija (sin créditos, suscripción vencida, clase llena...), queda aquí para que recepción lo resuelva.</p>
    <table class="table">
        <thead><tr><th>Fecha</th><th>Clase</th><th>Alumno</th><th>Motivo</th><th></th></tr></thead>
        <?php foreach ($alerts as $a): ?>
            <tr>
                <td><?= fdate($a['session_date']) ?></td>
                <td><a href="<?= url('session', ['t' => $a['timetable_id'], 'd' => $a['session_date']]) ?>"><?= h(substr($a['start_time'], 0, 5)) ?> <?= h($a['event_name']) ?></a></td>
                <td><a href="<?= url('student', ['id' => $a['student_id']]) ?>"><?= h($a['first_name'] . ' ' . $a['last_name']) ?></a></td>
                <td><?= h(\Sankris\BookingException::label($a['reason'])) ?></td>
                <td class="text-right">
                    <?= form_open('retry', 'class="sk-inline"') ?><input type="hidden" name="alert_id" value="<?= (int) $a['id'] ?>"><button class="btn btn-default btn-xs">Reintentar</button></form>
                    <?= form_open('resolve', 'class="sk-inline"') ?><input type="hidden" name="alert_id" value="<?= (int) $a['id'] ?>"><button class="btn btn-link btn-xs">Marcar resuelta</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$alerts): ?><tr><td colspan="5" class="sk-muted">No hay alertas pendientes.</td></tr><?php endif; ?>
    </table>
</div>
<?php sk_footer();

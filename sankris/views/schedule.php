<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('schedule.view') || deny();
$date = (string) ($_GET['d'] ?? date('Y-m-d'));
if (!\DateTimeImmutable::createFromFormat('Y-m-d', $date)) {
    $date = date('Y-m-d');
}

on_post('cancel_day', function (array $in) use ($app, $auth, $date) {
    $auth->can('schedule.edit') || deny();
    $n = $app->bookings()->cancelSession((string) $in['date'], null, (string) ($in['reason'] ?? ''));
    flash('success', 'Día cancelado. Reservas canceladas con crédito devuelto: ' . $n . '.');
    redirect('schedule', ['d' => $in['date']]);
});
on_post('restore', function (array $in) use ($app, $auth, $date) {
    $auth->can('schedule.edit') || deny();
    $app->bookings()->removeException((int) $in['exception_id']);
    flash('success', 'Clase habilitada de nuevo. Las reservas canceladas no se restauran solas.');
    redirect('schedule', ['d' => $date]);
});
on_post('program_rules', function (array $in) use ($app, $auth, $date) {
    $auth->can('schedule.edit') || deny();
    foreach ((array) ($in['rules'] ?? []) as $pid => $r) {
        $before = $app->db->one('SELECT id, booking_open_days, cancel_deadline_hours, makeups_per_month FROM programs WHERE id = ?', [(int) $pid]);
        if (!$before) {
            continue;
        }
        $after = [
            'booking_open_days' => max(0, (int) $r['booking_open_days']),
            'cancel_deadline_hours' => max(0, (int) $r['cancel_deadline_hours']),
            'makeups_per_month' => max(0, (int) $r['makeups_per_month']),
        ];
        if (array_diff_assoc(array_map('strval', $after), array_map('strval', array_intersect_key($before, $after)))) {
            $app->db->update('programs', $after, 'id = ?', [(int) $pid]);
            $app->audit()->log('program', (int) $pid, 'rules', $before, $after);
        }
    }
    flash('success', 'Reglas de los programas guardadas.');
    redirect('schedule', ['d' => $date]);
});

$d = new DateTimeImmutable($date);
$sessions = $app->schedule()->sessionsOn($date);
$counts = $app->bookings()->counts($date);
$dayEx = $app->schedule()->exceptionsOn($date)[0] ?? null;
$upcoming = $app->db->all('SELECT e.*, t.event_name, t.start_time FROM sk_session_exceptions e LEFT JOIN timetable t ON t.id = e.timetable_id WHERE e.session_date >= CURDATE() ORDER BY e.session_date LIMIT 20');
$programs = $app->db->all('SELECT * FROM programs ORDER BY sort_order, name');
$days = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
sk_header('Clases y asistencia', 'schedule');
?>
<div class="sk-card">
    <form class="form-inline" method="get">
        <input type="hidden" name="p" value="schedule">
        <a class="btn btn-default" href="<?= url('schedule', ['d' => $d->modify('-1 day')->format('Y-m-d')]) ?>"><i class="bi bi-chevron-left"></i></a>
        <input type="date" name="d" class="form-control" value="<?= h($date) ?>" onchange="this.form.submit()">
        <a class="btn btn-default" href="<?= url('schedule', ['d' => $d->modify('+1 day')->format('Y-m-d')]) ?>"><i class="bi bi-chevron-right"></i></a>
        <a class="btn btn-link" href="<?= url('schedule') ?>">Hoy</a>
        <strong style="margin-left:12px;font-size:16px"><?= h($days[(int) $d->format('N')]) ?> <?= $d->format('d/m/Y') ?></strong>
    </form>
</div>
<?php if ($dayEx): ?>
    <div class="alert alert-warning">Día sin clases: <strong><?= h($dayEx['reason']) ?></strong>
        <?php if ($auth->can('schedule.edit')): ?><?= form_open('restore', 'class="sk-inline"') ?><input type="hidden" name="exception_id" value="<?= (int) $dayEx['id'] ?>"><button class="btn btn-default btn-xs">Habilitar el día</button></form><?php endif; ?>
    </div>
<?php endif; ?>
<div class="row">
    <div class="col-md-8">
        <?php if (!$sessions): ?><div class="sk-card sk-muted">No hay clases programadas este día.</div><?php endif; ?>
        <?php foreach ($sessions as $s): $c = $counts[(int) $s['id']] ?? ['booked' => 0, 'attended' => 0]; $cap = (int) ($s['capacity'] ?: 0); ?>
            <a href="<?= url('session', ['t' => $s['id'], 'd' => $date]) ?>" style="text-decoration:none;color:inherit">
            <div class="sk-card sk-session <?= $s['exception'] ? 'cancelled' : '' ?>" style="border-left-color:<?= h($s['color'] ?: '#90278e') ?>">
                <div class="row">
                    <div class="col-xs-3"><strong><?= h(substr($s['start_time'], 0, 5)) ?>–<?= h(substr($s['end_time'], 0, 5)) ?></strong></div>
                    <div class="col-xs-5"><strong><?= h($s['event_name']) ?></strong><?= (int) $s['is_evaluation'] ? ' <span class="sk-chip">Evaluación</span>' : '' ?>
                        <div class="sk-muted small"><?= h($s['trainer_name'] ?? 'Sin coach asignado') ?><?= $s['room'] ? ' · ' . h($s['room']) : '' ?></div></div>
                    <div class="col-xs-4 text-right">
                        <?php if ($s['exception']): ?><span class="label label-default">Cancelada</span><br><small><?= h($s['exception']['reason']) ?></small>
                        <?php else: ?><strong><?= (int) $c['booked'] ?><?= $cap ? ' / ' . $cap : '' ?></strong> reservas<br><small class="sk-muted"><?= (int) $c['attended'] ?> asistencias</small><?php endif; ?>
                    </div>
                </div>
            </div>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="col-md-4">
        <?php if ($auth->can('schedule.edit')): ?>
        <div class="sk-card">
            <h4>Asueto o día sin clases</h4>
            <?= form_open('cancel_day', 'onsubmit="return confirm(\'Se cancelan todas las clases del día y se devuelven los créditos. ¿Continuar?\')"') ?>
            <div class="form-group"><input type="date" name="date" class="form-control input-sm" value="<?= h($date) ?>"></div>
            <div class="form-group"><input name="reason" class="form-control input-sm" placeholder="Motivo (Asueto 15 de septiembre)" required></div>
            <button class="btn btn-warning btn-sm">Cancelar todas las clases del día</button>
            </form>
        </div>
        <?php endif; ?>
        <div class="sk-card">
            <h4>Próximas cancelaciones</h4>
            <?php foreach ($upcoming as $u): ?>
                <div class="small"><strong><?= fdate($u['session_date']) ?></strong> · <?= $u['timetable_id'] ? h(substr((string) $u['start_time'], 0, 5) . ' ' . $u['event_name']) : 'Todo el día' ?> · <?= h($u['reason']) ?></div>
            <?php endforeach; ?>
            <?php if (!$upcoming): ?><p class="sk-muted small">Ninguna.</p><?php endif; ?>
        </div>
    </div>
</div>
<?php if ($auth->can('schedule.edit')): ?>
<div class="sk-card">
    <h4>Reglas por programa</h4>
    <p class="sk-muted small">Los horarios, coaches, salas y cupos se editan en <a href="../trainers/timetable/">Horarios</a>. Aquí van las reglas de reserva de cada programa.</p>
    <?= form_open('program_rules') ?>
    <table class="table table-condensed">
        <thead><tr><th>Programa</th><th>Se puede reservar con (días)</th><th>Cancelar sin perder crédito hasta (horas antes)</th><th>Reposiciones por mes</th></tr></thead>
        <?php foreach ($programs as $p): $k = 'rules[' . (int) $p['id'] . ']'; ?>
            <tr><td><span class="sk-chip" style="background:<?= h($p['color']) ?>;color:#fff"><?= h($p['name']) ?></span></td>
                <td><input type="number" min="0" class="form-control input-sm" style="width:80px" name="<?= $k ?>[booking_open_days]" value="<?= (int) $p['booking_open_days'] ?>"></td>
                <td><input type="number" min="0" class="form-control input-sm" style="width:80px" name="<?= $k ?>[cancel_deadline_hours]" value="<?= (int) $p['cancel_deadline_hours'] ?>"></td>
                <td><input type="number" min="0" class="form-control input-sm" style="width:80px" name="<?= $k ?>[makeups_per_month]" value="<?= (int) $p['makeups_per_month'] ?>"></td></tr>
        <?php endforeach; ?>
    </table>
    <button class="btn btn-primary btn-sm">Guardar reglas</button>
    </form>
</div>
<?php endif; ?>
<?php sk_footer();

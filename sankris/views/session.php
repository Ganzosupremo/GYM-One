<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('schedule.view') || deny();
$tid = (int) ($_GET['t'] ?? 0);
$date = (string) ($_GET['d'] ?? date('Y-m-d'));
$slot = $app->schedule()->slot($tid);
if (!$slot || !\DateTimeImmutable::createFromFormat('Y-m-d', $date)) {
    flash('danger', 'Clase no encontrada.');
    redirect('schedule');
}
$back = ['t' => $tid, 'd' => $date];
$owns = function (int $bookingId) use ($app, $tid, $date): void {
    $b = $app->db->one('SELECT timetable_id, session_date FROM sk_bookings WHERE id = ?', [$bookingId]);
    if (!$b || (int) $b['timetable_id'] !== $tid || $b['session_date'] !== $date) {
        deny();
    }
};

on_post('attendance', function (array $in) use ($app, $auth, $back, $owns) {
    $auth->can('attendance.mark') || deny();
    $owns((int) $in['booking_id']);
    $app->bookings()->markAttendance((int) $in['booking_id'], (string) $in['status']);
    redirect('session', $back);
});
on_post('all_attended', function (array $in) use ($app, $auth, $back, $tid, $date) {
    $auth->can('attendance.mark') || deny();
    foreach ($app->db->all("SELECT id FROM sk_bookings WHERE timetable_id = ? AND session_date = ? AND status = 'booked'", [$tid, $date]) as $b) {
        $app->bookings()->markAttendance((int) $b['id'], 'attended');
    }
    flash('success', 'Asistencia marcada para todos los reservados.');
    redirect('session', $back);
});
on_post('book', function (array $in) use ($app, $auth, $back, $tid, $date) {
    $auth->can('bookings.edit') || deny();
    $app->bookings()->book((int) $in['student_id'], $tid, $date, ['source' => $in['source'] ?? 'manual']);
    flash('success', 'Reserva creada.');
    redirect('session', $back);
});
on_post('cancel_booking', function (array $in) use ($app, $auth, $back, $owns) {
    $auth->can('bookings.edit') || deny();
    $owns((int) $in['booking_id']);
    $status = $app->bookings()->cancel((int) $in['booking_id'], ['force_refund' => !empty($in['force_refund']) && $auth->can('subscriptions.price')]);
    flash($status === 'cancelled' ? 'success' : 'warning', $status === 'cancelled' ? 'Reserva cancelada y crédito devuelto.' : 'Cancelación tardía: el crédito no se devuelve.');
    redirect('session', $back);
});
on_post('cancel_session', function (array $in) use ($app, $auth, $back, $tid, $date) {
    $auth->can('schedule.edit') || deny();
    $n = $app->bookings()->cancelSession($date, $tid, (string) ($in['reason'] ?? ''));
    flash('success', 'Clase cancelada. Créditos devueltos a ' . $n . ' reserva(s).');
    redirect('session', $back);
});
on_post('restore', function (array $in) use ($app, $auth, $back) {
    $auth->can('schedule.edit') || deny();
    $app->bookings()->removeException((int) $in['exception_id']);
    flash('success', 'Clase habilitada de nuevo.');
    redirect('session', $back);
});

$roster = $app->bookings()->roster($tid, $date);
$exception = $app->schedule()->isCancelled($tid, $date);
$runs = $app->schedule()->runsOn($slot, $date);
$active = array_filter($roster, fn($r) => in_array($r['status'], ['booked', 'attended', 'no_show'], true));
$q = trim((string) ($_GET['q'] ?? ''));
$found = [];
if ($q !== '' && $auth->can('bookings.edit')) {
    $found = $app->db->all("SELECT s.id, s.first_name, s.last_name, s.birthdate, f.name AS family_name FROM sk_students s JOIN sk_families f ON f.id = s.family_id
        WHERE s.status IN ('active','trial') AND CONCAT(s.first_name, ' ', s.last_name) LIKE ? ORDER BY s.first_name LIMIT 15", ['%' . $q . '%']);
}
$medical = $auth->can('students.medical');
sk_header($slot['event_name'] . ' · ' . date('d/m/Y', strtotime($date)), 'schedule');
?>
<p><a href="<?= url('schedule', ['d' => $date]) ?>"><i class="bi bi-arrow-left"></i> Clases del día</a></p>
<?php if (!$runs): ?><div class="alert alert-warning">Esta clase no se da ese día de la semana (<?= h($slot['day_of_week']) ?>).</div><?php endif; ?>
<?php if ($exception): ?>
    <div class="alert alert-warning">Clase cancelada: <strong><?= h($exception['reason']) ?></strong>
        <?php if ($auth->can('schedule.edit')): ?><?= form_open('restore', 'class="sk-inline"') ?><input type="hidden" name="exception_id" value="<?= (int) $exception['id'] ?>"><button class="btn btn-default btn-xs">Habilitar</button></form><?php endif; ?>
    </div>
<?php endif; ?>
<div class="row">
    <div class="col-md-8">
        <div class="sk-card">
            <h4><?= h(substr($slot['start_time'], 0, 5)) ?>–<?= h(substr($slot['end_time'], 0, 5)) ?> · <?= h($slot['program_name'] ?? $slot['event_name']) ?>
                <small class="pull-right"><?= count($active) ?><?= $slot['capacity'] ? ' / ' . (int) $slot['capacity'] : '' ?> lugares</small></h4>
            <p class="sk-muted"><?= h($slot['trainer_name'] ?? 'Sin coach asignado') ?><?= $slot['room'] ? ' · ' . h($slot['room']) : '' ?>
                · cancelar sin perder crédito hasta <?= (int) ($slot['cancel_deadline_hours'] ?? 12) ?> h antes</p>
            <table class="table">
                <thead><tr><th>Alumno</th><th>Tipo</th><th>Estado</th><th class="text-right">Asistencia</th></tr></thead>
                <?php foreach ($roster as $r): ?>
                    <tr class="<?= in_array($r['status'], ['cancelled', 'late_cancelled', 'session_cancelled'], true) ? 'text-muted' : '' ?>">
                        <td><a href="<?= url('student', ['id' => $r['student_id']]) ?>"><strong><?= h($r['first_name'] . ' ' . $r['last_name']) ?></strong></a>
                            <small class="sk-muted"><?= age_label($r['birthdate']) ?><?= $r['level'] ? ' · ' . h($r['level']) : '' ?> · <?= (int) $r['attended_total'] ?> clases</small>
                            <?php if ($medical && $r['medical_notes']): ?><div class="sk-medical small"><i class="bi bi-heart-pulse"></i> <?= h($r['medical_notes']) ?></div><?php endif; ?></td>
                        <td><?= h(SOURCE_LABELS[$r['source']] ?? $r['source']) ?></td>
                        <td><?= status_label($r['status']) ?></td>
                        <td class="text-right" style="white-space:nowrap">
                            <?php if (in_array($r['status'], ['booked', 'attended', 'no_show'], true) && $auth->can('attendance.mark')): ?>
                                <?php foreach (['attended' => ['success', 'bi-check-lg', 'Asistió'], 'no_show' => ['danger', 'bi-x-lg', 'No vino'], 'booked' => ['default', 'bi-arrow-counterclockwise', 'Sin marcar']] as $st => [$cls, $icon, $label]): if ($st === $r['status']) continue; ?>
                                    <?= form_open('attendance', 'class="sk-inline"') ?><input type="hidden" name="booking_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>"><button class="btn btn-<?= $cls ?> btn-xs" title="<?= $label ?>"><i class="bi <?= $icon ?>"></i> <?= $label ?></button></form>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($r['status'] === 'booked' && $auth->can('bookings.edit')): ?>
                                <?= form_open('cancel_booking', 'class="sk-inline"') ?><input type="hidden" name="booking_id" value="<?= (int) $r['id'] ?>"><button class="btn btn-link btn-xs">Cancelar</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$roster): ?><tr><td colspan="4" class="sk-muted">Nadie reservado todavía.</td></tr><?php endif; ?>
            </table>
            <?php if ($auth->can('attendance.mark') && array_filter($roster, fn($r) => $r['status'] === 'booked')): ?>
                <?= form_open('all_attended') ?><button class="btn btn-success btn-sm"><i class="bi bi-check2-all"></i> Marcar a todos los reservados como presentes</button></form>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-4">
        <?php if ($auth->can('bookings.edit') && !$exception): ?>
        <div class="sk-card">
            <h4>Agregar alumno</h4>
            <form method="get">
                <input type="hidden" name="p" value="session"><input type="hidden" name="t" value="<?= $tid ?>"><input type="hidden" name="d" value="<?= h($date) ?>">
                <div class="input-group"><input name="q" class="form-control input-sm" value="<?= h($q) ?>" placeholder="Nombre del alumno"><span class="input-group-btn"><button class="btn btn-default btn-sm"><i class="bi bi-search"></i></button></span></div>
            </form>
            <?php foreach ($found as $s): ?>
                <div style="margin-top:8px"><?= form_open('book', 'class="form-inline"') ?>
                    <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                    <strong><?= h($s['first_name'] . ' ' . $s['last_name']) ?></strong> <small class="sk-muted"><?= h($s['family_name']) ?></small><br>
                    <select name="source" class="form-control input-sm"><?= opt((int) $slot['is_evaluation'] ? ['evaluation' => 'Evaluación'] : ['manual' => 'Reserva', 'makeup' => 'Reposición'], '') ?></select>
                    <button class="btn btn-success btn-sm">Reservar</button>
                </form></div>
            <?php endforeach; ?>
            <?php if ($q !== '' && !$found): ?><p class="sk-muted small" style="margin-top:8px">Sin resultados.</p><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($auth->can('schedule.edit') && !$exception): ?>
        <div class="sk-card">
            <h4>Cancelar esta clase</h4>
            <?= form_open('cancel_session', 'onsubmit="return confirm(\'Se cancelan todas las reservas y se devuelven los créditos. ¿Continuar?\')"') ?>
            <div class="form-group"><input name="reason" class="form-control input-sm" placeholder="Motivo (coach enfermo, mantenimiento...)" required></div>
            <button class="btn btn-warning btn-sm">Cancelar clase</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php sk_footer();

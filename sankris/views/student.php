<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('families.view') || deny();
$sid = (int) ($_GET['id'] ?? 0);
$student = $app->families()->student($sid);
if (!$student) {
    flash('danger', 'Alumno no encontrado.');
    redirect('families');
}
$back = ['id' => $sid];
$today = date('Y-m-d');

on_post('update_student', function (array $in) use ($app, $auth, $sid, $back) {
    $auth->can('families.edit') || deny();
    $fields = ['first_name', 'last_name', 'birthdate', 'gender', 'program_id', 'level', 'status', 'tags', 'notes', 'emergency_contact_name', 'emergency_contact_phone'];
    $data = array_intersect_key($in, array_flip($fields));
    $data['photo_consent'] = empty($in['photo_consent']) ? 0 : 1;
    if ($auth->can('students.medical') && array_key_exists('medical_notes', $in)) {
        $data['medical_notes'] = $in['medical_notes'];
    }
    $app->families()->updateStudent($sid, $data);
    flash('success', 'Ficha actualizada.');
    redirect('student', $back);
});
on_post('create_subscription', function (array $in) use ($app, $auth, $sid, $back) {
    $auth->can('subscriptions.edit') || deny();
    $opts = ['notes' => $in['notes'] ?? ''];
    if ($auth->can('subscriptions.price')) {
        $opts += ['price' => $in['price'] ?? '', 'discount_type' => $in['discount_type'] ?? 'none', 'discount_value' => $in['discount_value'] ?? 0, 'discount_cycles' => $in['discount_cycles'] ?? ''];
    }
    $id = $app->subscriptions()->create($sid, (int) $in['plan_id'], (string) $in['start_date'], $opts);
    flash('success', 'Suscripción creada. Su factura se genera en la fecha de cobro (tarea diaria o botón "Generar facturas pendientes").');
    redirect('subscription', ['id' => $id]);
});
on_post('book', function (array $in) use ($app, $auth, $sid, $back) {
    $auth->can('bookings.edit') || deny();
    $app->bookings()->book($sid, (int) $in['timetable_id'], (string) $in['date'], ['source' => $in['source'] ?? 'manual']);
    flash('success', 'Reserva creada.');
    redirect('student', $back);
});
on_post('cancel_booking', function (array $in) use ($app, $auth, $sid, $back) {
    $auth->can('bookings.edit') || deny();
    $status = $app->bookings()->cancel((int) $in['booking_id'], ['force_refund' => !empty($in['force_refund']) && $auth->can('subscriptions.price')]);
    flash($status === 'cancelled' ? 'success' : 'warning', $status === 'cancelled' ? 'Reserva cancelada y crédito devuelto.' : 'Cancelación tardía: el crédito no se devuelve.');
    redirect('student', $back);
});
on_post('add_recurring', function (array $in) use ($app, $auth, $sid, $back) {
    $auth->can('bookings.edit') || deny();
    $app->bookings()->addRecurring($sid, (int) $in['timetable_id'], (string) $in['start_date'], ($in['end_date'] ?? '') !== '' ? $in['end_date'] : null);
    $days = $app->settings->int('recurring_booking_horizon_days', 14);
    $r = $app->bookings()->generateRecurring(date('Y-m-d'), $days);
    flash('success', 'Reserva fija creada. Reservas generadas: ' . $r['created'] . ($r['alerts'] ? ', con ' . $r['alerts'] . ' alerta(s).' : '.'));
    redirect('student', $back);
});
on_post('stop_recurring', function (array $in) use ($app, $auth, $sid, $back) {
    $auth->can('bookings.edit') || deny();
    $app->bookings()->stopRecurring((int) $in['recurring_id'], (string) ($in['from_date'] ?? date('Y-m-d')));
    flash('success', 'Reserva fija terminada y reservas futuras canceladas.');
    redirect('student', $back);
});
on_post('adjust_credits', function (array $in) use ($app, $auth, $sid, $back) {
    $auth->can('subscriptions.edit') || deny();
    $sub = $app->subscriptions()->get((int) $in['subscription_id']);
    if (!$sub || (int) $sub['student_id'] !== $sid) {
        deny();
    }
    if (trim($in['note'] ?? '') === '') {
        throw new \Sankris\ValidationException('Indica el motivo del ajuste.');
    }
    $app->credits()->adjust($sub, date('Y-m-d'), (int) $in['delta'], (string) $in['note']);
    flash('success', 'Créditos ajustados.');
    redirect('student', $back);
});

$family = $app->families()->family((int) $student['family_id']);
$subs = $app->subscriptions()->forStudent($sid);
$bookings = $app->bookings()->forStudent($sid, 40);
$recurring = $app->bookings()->recurringFor($sid);
$plans = $app->subscriptions()->plans(true);
$programs = $app->db->all('SELECT id, name FROM programs ORDER BY sort_order, name');
$slots = $app->db->all('SELECT t.id, t.event_name, t.day_of_week, t.start_time, t.is_evaluation FROM timetable t ORDER BY FIELD(t.day_of_week, "Lunes","Martes","Miércoles","Jueves","Viernes","Sábado","Domingo"), t.start_time');
$slotOpts = [];
foreach ($slots as $s) {
    $slotOpts[$s['id']] = $s['day_of_week'] . ' ' . substr($s['start_time'], 0, 5) . ' · ' . $s['event_name'] . ((int) $s['is_evaluation'] ? ' (evaluación)' : '');
}
$medical = $auth->can('students.medical');
$edit = $auth->can('families.edit');
sk_header($student['first_name'] . ' ' . $student['last_name'], 'families');
?>
<p><a href="<?= url('family', ['id' => $student['family_id']]) ?>"><i class="bi bi-arrow-left"></i> <?= h($family['name'] ?? 'Familia') ?></a></p>
<div class="row">
    <div class="col-md-4">
        <div class="sk-card">
            <h4>Ficha del alumno</h4>
            <p><?= status_label($student['status']) ?> · <?= age_label($student['birthdate']) ?> · <?= h($student['program_name'] ?? 'Sin programa') ?></p>
            <?php if ($medical && $student['medical_notes']): ?><div class="sk-medical"><i class="bi bi-heart-pulse"></i> <?= nl2br(h($student['medical_notes'])) ?></div><br><?php endif; ?>
            <?= form_open('update_student') ?>
            <fieldset <?= $edit ? '' : 'disabled' ?>>
            <div class="row">
                <div class="col-xs-6 form-group"><label class="small">Nombre</label><input class="form-control input-sm" name="first_name" value="<?= h($student['first_name']) ?>"></div>
                <div class="col-xs-6 form-group"><label class="small">Apellido</label><input class="form-control input-sm" name="last_name" value="<?= h($student['last_name']) ?>"></div>
                <div class="col-xs-6 form-group"><label class="small">Nacimiento</label><input type="date" class="form-control input-sm" name="birthdate" value="<?= h($student['birthdate']) ?>"></div>
                <div class="col-xs-6 form-group"><label class="small">Sexo</label><select class="form-control input-sm" name="gender"><?= opt(['' => '—', 'F' => 'Femenino', 'M' => 'Masculino', 'X' => 'Otro'], $student['gender']) ?></select></div>
                <div class="col-xs-6 form-group"><label class="small">Programa</label><select class="form-control input-sm" name="program_id"><?= opt(['' => '—'] + array_column($programs, 'name', 'id'), $student['program_id']) ?></select></div>
                <div class="col-xs-6 form-group"><label class="small">Nivel</label><input class="form-control input-sm" name="level" value="<?= h($student['level']) ?>"></div>
                <div class="col-xs-6 form-group"><label class="small">Estado</label><select class="form-control input-sm" name="status"><?= opt(['active' => 'Activo', 'trial' => 'Prueba', 'inactive' => 'Inactivo'], $student['status']) ?></select></div>
                <div class="col-xs-6 form-group"><label class="small">Etiquetas</label><input class="form-control input-sm" name="tags" value="<?= h($student['tags']) ?>"></div>
                <div class="col-xs-6 form-group"><label class="small">Contacto de emergencia</label><input class="form-control input-sm" name="emergency_contact_name" value="<?= h($student['emergency_contact_name']) ?>"></div>
                <div class="col-xs-6 form-group"><label class="small">Teléfono de emergencia</label><input class="form-control input-sm" name="emergency_contact_phone" value="<?= h($student['emergency_contact_phone']) ?>"></div>
                <div class="col-xs-12 form-group"><label class="small">Notas</label><textarea class="form-control input-sm" name="notes" rows="2"><?= h($student['notes']) ?></textarea></div>
                <?php if ($medical): ?>
                    <div class="col-xs-12 form-group"><label class="small"><i class="bi bi-lock"></i> Notas médicas (sólo administración y coaches)</label><textarea class="form-control input-sm" name="medical_notes" rows="2"><?= h($student['medical_notes']) ?></textarea></div>
                <?php else: ?>
                    <div class="col-xs-12"><p class="small sk-muted"><i class="bi bi-lock"></i> Las notas médicas sólo las ven administración y coaches.</p></div>
                <?php endif; ?>
                <div class="col-xs-12"><div class="checkbox"><label><input type="checkbox" name="photo_consent" value="1" <?= (int) $student['photo_consent'] ? 'checked' : '' ?>> Autoriza fotos y videos</label></div></div>
            </div>
            <?php if ($edit): ?><button class="btn btn-primary btn-sm">Guardar ficha</button><?php endif; ?>
            </fieldset>
            </form>
        </div>
    </div>
    <div class="col-md-8">
        <div class="sk-card">
            <h4>Suscripciones y créditos</h4>
            <table class="table table-condensed">
                <thead><tr><th>Plan</th><th>Precio</th><th>Créditos disponibles</th><th>Próximo cobro</th><th>Estado</th></tr></thead>
                <?php foreach ($subs as $s): $avail = $s['status'] === 'active' && \Sankris\Credits::coversDate($s, $today) ? $app->credits()->available($s, $today) : false; ?>
                    <tr>
                        <td><a href="<?= url('subscription', ['id' => $s['id']]) ?>"><?= h($s['plan_name']) ?></a><br><small class="sk-muted">desde <?= fdate($s['start_date']) ?><?= $s['end_date'] ? ' hasta ' . fdate($s['end_date']) : '' ?></small></td>
                        <td><?= money($s['price']) ?> <small class="sk-muted"><?= h(PERIOD_LABELS[$s['billing_period']] ?? '') ?></small></td>
                        <td><?= $avail === false ? '—' : ($avail === null ? 'Ilimitados' : (int) $avail . ' <small class="sk-muted">' . h(CREDIT_PERIOD_LABELS[$s['credit_period']] ?? '') . '</small>') ?>
                            <?php if ($avail !== false && $avail !== null && $auth->can('subscriptions.edit')): ?>
                                <a href="#" class="small" data-toggle="collapse" data-target="#adj<?= (int) $s['id'] ?>">ajustar</a>
                                <div id="adj<?= (int) $s['id'] ?>" class="collapse"><?= form_open('adjust_credits', 'class="form-inline"') ?>
                                    <input type="hidden" name="subscription_id" value="<?= (int) $s['id'] ?>">
                                    <input type="number" name="delta" class="form-control input-sm" style="width:70px" value="1">
                                    <input name="note" class="form-control input-sm" placeholder="Motivo" required>
                                    <button class="btn btn-default btn-sm">Aplicar</button></form></div>
                            <?php endif; ?>
                        </td>
                        <td><?= fdate($s['next_billing_date']) ?></td>
                        <td><?= status_label($s['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$subs): ?><tr><td colspan="5" class="sk-muted">Sin suscripciones.</td></tr><?php endif; ?>
            </table>
            <?php if ($auth->can('subscriptions.edit')): ?>
                <button class="btn btn-success btn-sm" data-toggle="collapse" data-target="#newSub"><i class="bi bi-plus-lg"></i> Inscribir en un plan</button>
                <div id="newSub" class="collapse" style="margin-top:12px">
                    <?= form_open('create_subscription') ?>
                    <div class="row sk-form-row">
                        <div class="col-sm-5 form-group"><label>Plan</label><select class="form-control" name="plan_id" required>
                            <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"><?= h($p['name']) ?> · <?= money($p['price']) ?> <?= h(PERIOD_LABELS[$p['billing_period']] ?? '') ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="col-sm-3 form-group"><label>Inicio</label><input type="date" class="form-control" name="start_date" value="<?= $today ?>" required></div>
                        <?php if ($auth->can('subscriptions.price')): ?>
                            <div class="col-sm-4 form-group"><label>Precio personalizado</label><input type="number" step="0.01" min="0" class="form-control" name="price" placeholder="Precio del plan"></div>
                            <div class="col-sm-4 form-group"><label>Descuento</label><select class="form-control" name="discount_type"><?= opt(['none' => 'Sin descuento', 'percent' => 'Porcentaje', 'fixed' => 'Monto fijo'], 'none') ?></select></div>
                            <div class="col-sm-4 form-group"><label>Valor del descuento</label><input type="number" step="0.01" min="0" class="form-control" name="discount_value" value="0"></div>
                            <div class="col-sm-4 form-group"><label>Durante (cobros)</label><input type="number" min="1" class="form-control" name="discount_cycles" placeholder="Siempre"></div>
                        <?php endif; ?>
                        <div class="col-sm-12 form-group"><label>Notas</label><input class="form-control" name="notes"></div>
                    </div>
                    <p class="small sk-muted">El precio, créditos y condiciones del plan quedan guardados en la suscripción: si luego cambias la tarifa del plan, esta suscripción no cambia.</p>
                    <button class="btn btn-success">Crear suscripción</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="sk-card">
            <h4>Reservas fijas</h4>
            <table class="table table-condensed">
                <?php foreach ($recurring as $r): ?>
                    <tr class="<?= (int) $r['active'] ? '' : 'text-muted' ?>">
                        <td><?= h($r['day_of_week']) ?> <?= h(substr($r['start_time'], 0, 5)) ?> · <?= h($r['event_name']) ?></td>
                        <td>desde <?= fdate($r['start_date']) ?><?= $r['end_date'] ? ' hasta ' . fdate($r['end_date']) : '' ?></td>
                        <td class="text-right"><?php if ((int) $r['active'] && $auth->can('bookings.edit')): ?>
                            <?= form_open('stop_recurring', 'class="sk-inline" onsubmit="return confirm(\'¿Terminar esta reserva fija y cancelar sus reservas futuras?\')"') ?>
                            <input type="hidden" name="recurring_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="from_date" value="<?= $today ?>">
                            <button class="btn btn-default btn-xs">Terminar</button></form>
                        <?php elseif (!(int) $r['active']): ?>Terminada<?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recurring): ?><tr><td class="sk-muted">Sin reservas fijas.</td></tr><?php endif; ?>
            </table>
            <?php if ($auth->can('bookings.edit')): ?>
                <?= form_open('add_recurring', 'class="form-inline"') ?>
                <select class="form-control input-sm" name="timetable_id"><?= opt($slotOpts, '') ?></select>
                desde <input type="date" class="form-control input-sm" name="start_date" value="<?= $today ?>">
                hasta <input type="date" class="form-control input-sm" name="end_date">
                <button class="btn btn-success btn-sm"><i class="bi bi-plus-lg"></i> Agregar reserva fija</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="sk-card">
            <h4>Reservas y asistencia</h4>
            <?php if ($auth->can('bookings.edit')): ?>
                <?= form_open('book', 'class="form-inline" style="margin-bottom:12px"') ?>
                <select class="form-control input-sm" name="timetable_id"><?= opt($slotOpts, '') ?></select>
                <input type="date" class="form-control input-sm" name="date" value="<?= $today ?>">
                <select class="form-control input-sm" name="source"><?= opt(['manual' => 'Reserva normal', 'makeup' => 'Reposición', 'evaluation' => 'Clase de evaluación'], 'manual') ?></select>
                <button class="btn btn-success btn-sm"><i class="bi bi-plus-lg"></i> Reservar</button>
                </form>
            <?php endif; ?>
            <table class="table table-condensed">
                <thead><tr><th>Fecha</th><th>Clase</th><th>Tipo</th><th>Estado</th><th></th></tr></thead>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td><?= fdate($b['session_date']) ?> <?= h(substr($b['start_time'], 0, 5)) ?></td>
                        <td><a href="<?= url('session', ['t' => $b['timetable_id'], 'd' => $b['session_date']]) ?>"><?= h($b['event_name']) ?></a></td>
                        <td><?= h(SOURCE_LABELS[$b['source']] ?? $b['source']) ?></td>
                        <td><?= status_label($b['status']) ?></td>
                        <td class="text-right"><?php if ($b['status'] === 'booked' && $auth->can('bookings.edit')): ?>
                            <?= form_open('cancel_booking', 'class="sk-inline"') ?><input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                            <?php if ($auth->can('subscriptions.price')): ?><label class="small" title="Devolver el crédito aunque sea tarde"><input type="checkbox" name="force_refund" value="1"> devolver crédito</label><?php endif; ?>
                            <button class="btn btn-default btn-xs">Cancelar</button></form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$bookings): ?><tr><td colspan="5" class="sk-muted">Sin reservas.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php sk_footer();

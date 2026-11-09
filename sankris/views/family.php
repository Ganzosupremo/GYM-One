<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('families.view') || deny();
$fid = (int) ($_GET['id'] ?? 0);
$family = $app->families()->family($fid);
if (!$family) {
    flash('danger', 'Familia no encontrada.');
    redirect('families');
}
$guardianFields = fn(array $in) => [
    'first_name' => $in['first_name'] ?? '', 'last_name' => $in['last_name'] ?? '', 'relationship' => $in['relationship'] ?? '',
    'email' => $in['email'] ?? '', 'phone' => $in['phone'] ?? '', 'tax_id' => $in['tax_id'] ?? '', 'billing_name' => $in['billing_name'] ?? '',
    'is_payer' => empty($in['is_payer']) ? 0 : 1, 'is_emergency_contact' => empty($in['is_emergency_contact']) ? 0 : 1,
];

on_post('update_family', function (array $in) use ($app, $auth, $fid) {
    $auth->can('families.edit') || deny();
    $app->families()->updateFamily($fid, ['name' => $in['name'] ?? '', 'status' => $in['status'] ?? 'active', 'tags' => $in['tags'] ?? '', 'notes' => $in['notes'] ?? '']);
    flash('success', 'Familia actualizada.');
    redirect('family', ['id' => $fid]);
});
on_post('add_guardian', function (array $in) use ($app, $auth, $fid, $guardianFields) {
    $auth->can('families.edit') || deny();
    $app->families()->addGuardian($fid, $guardianFields($in));
    flash('success', 'Responsable agregado.');
    redirect('family', ['id' => $fid]);
});
on_post('update_guardian', function (array $in) use ($app, $auth, $fid, $guardianFields) {
    $auth->can('families.edit') || deny();
    $g = $app->families()->guardian((int) $in['guardian_id']);
    if (!$g || (int) $g['family_id'] !== $fid) {
        deny();
    }
    $data = $guardianFields($in);
    $data['autopay'] = empty($in['autopay']) ? 0 : 1;
    if ($data['autopay'] && empty($g['stripe_payment_method_id'])) {
        $data['autopay'] = 0;
        flash('warning', 'El cobro automático se activa cuando el responsable guarde una tarjeta.');
    }
    $app->families()->updateGuardian((int) $g['id'], $data);
    flash('success', 'Responsable actualizado.');
    redirect('family', ['id' => $fid]);
});
on_post('add_student', function (array $in) use ($app, $auth, $fid) {
    $auth->can('families.edit') || deny();
    $sid = $app->families()->addStudent($fid, [
        'first_name' => $in['first_name'] ?? '', 'last_name' => $in['last_name'] ?? '', 'birthdate' => $in['birthdate'] ?? '',
        'gender' => $in['gender'] ?? '', 'program_id' => $in['program_id'] ?? '', 'level' => $in['level'] ?? '', 'status' => $in['status'] ?? 'active',
    ]);
    flash('success', 'Alumno agregado. Completa su ficha.');
    redirect('student', ['id' => $sid]);
});
on_post('setup_link', function (array $in) use ($app, $auth, $fid) {
    $auth->can('billing.edit') || deny();
    $g = $app->families()->guardian((int) $in['guardian_id']);
    if (!$g || (int) $g['family_id'] !== $fid) {
        deny();
    }
    $link = $app->stripe()->setupLink((int) $g['id']);
    flash('success', 'Enlace para guardar la tarjeta (envíalo al responsable): ' . $link);
    redirect('family', ['id' => $fid]);
});

$guardians = $app->families()->guardians($fid);
$students = $app->families()->students($fid);
$subs = $app->subscriptions()->forFamily($fid);
$canBilling = $auth->can('billing.view');
$invoices = $canBilling ? $app->billing()->invoices(['family_id' => $fid], 20) : [];
$payments = $canBilling ? $app->billing()->payments(['family_id' => $fid], 20) : [];
$balance = $canBilling ? $app->billing()->familyBalance($fid) : null;
$programs = $app->db->all('SELECT id, name FROM programs ORDER BY sort_order, name');
$programOpts = ['' => '—'] + array_column($programs, 'name', 'id');
$edit = $auth->can('families.edit');
sk_header($family['name'], 'families');
?>
<p><a href="<?= url('families') ?>"><i class="bi bi-arrow-left"></i> Familias</a></p>
<div class="row">
    <div class="col-md-8">
        <div class="sk-card">
            <h4>Alumnos</h4>
            <table class="table">
                <thead><tr><th>Alumno</th><th>Edad</th><th>Programa</th><th>Nivel</th><th>Estado</th></tr></thead>
                <?php foreach ($students as $s): ?>
                    <tr>
                        <td><a href="<?= url('student', ['id' => $s['id']]) ?>"><strong><?= h($s['first_name'] . ' ' . $s['last_name']) ?></strong></a></td>
                        <td><?= age_label($s['birthdate']) ?></td>
                        <td><?= h($s['program_name'] ?? '—') ?></td>
                        <td><?= h($s['level'] ?? '—') ?></td>
                        <td><?= status_label($s['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$students): ?><tr><td colspan="5" class="sk-muted">Todavía no hay alumnos.</td></tr><?php endif; ?>
            </table>
            <?php if ($edit): ?>
                <button class="btn btn-success btn-sm" data-toggle="collapse" data-target="#newStudent"><i class="bi bi-plus-lg"></i> Agregar alumno</button>
                <div id="newStudent" class="collapse" style="margin-top:12px">
                    <?= form_open('add_student') ?>
                    <div class="row sk-form-row">
                        <div class="col-sm-3 form-group"><label>Nombre *</label><input class="form-control" name="first_name" required></div>
                        <div class="col-sm-3 form-group"><label>Apellido *</label><input class="form-control" name="last_name" required></div>
                        <div class="col-sm-3 form-group"><label>Fecha de nacimiento</label><input type="date" class="form-control" name="birthdate"></div>
                        <div class="col-sm-3 form-group"><label>Sexo</label><select class="form-control" name="gender"><?= opt(['' => '—', 'F' => 'Femenino', 'M' => 'Masculino', 'X' => 'Otro'], '') ?></select></div>
                        <div class="col-sm-4 form-group"><label>Programa</label><select class="form-control" name="program_id"><?= opt($programOpts, '') ?></select></div>
                        <div class="col-sm-4 form-group"><label>Nivel</label><input class="form-control" name="level" placeholder="Nivel 1"></div>
                        <div class="col-sm-4 form-group"><label>Estado</label><select class="form-control" name="status"><?= opt(['active' => 'Activo', 'trial' => 'Prueba (evaluación)', 'inactive' => 'Inactivo'], 'active') ?></select></div>
                    </div>
                    <button class="btn btn-success">Agregar</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="sk-card">
            <h4>Suscripciones</h4>
            <table class="table table-condensed">
                <thead><tr><th>Alumno</th><th>Plan</th><th>Precio</th><th>Próximo cobro</th><th>Estado</th></tr></thead>
                <?php foreach ($subs as $s): ?>
                    <tr>
                        <td><?= h($s['first_name'] . ' ' . $s['last_name']) ?></td>
                        <td><a href="<?= url('subscription', ['id' => $s['id']]) ?>"><?= h($s['plan_name']) ?></a></td>
                        <td><?= money($s['price']) ?> <small class="sk-muted"><?= h(PERIOD_LABELS[$s['billing_period']] ?? '') ?></small><?= $s['discount_type'] !== 'none' ? ' <span class="sk-chip">desc.</span>' : '' ?></td>
                        <td><?= fdate($s['next_billing_date']) ?></td>
                        <td><?= status_label($s['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$subs): ?><tr><td colspan="5" class="sk-muted">Sin suscripciones. Se crean desde la ficha del alumno.</td></tr><?php endif; ?>
            </table>
        </div>

        <?php if ($canBilling): ?>
        <div class="sk-card">
            <h4>Facturas <a class="btn btn-default btn-xs pull-right" href="<?= url('invoice_new', ['family_id' => $fid]) ?>"><i class="bi bi-plus-lg"></i> Factura manual</a></h4>
            <table class="table table-condensed">
                <thead><tr><th>Número</th><th>Emitida</th><th>Vence</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Estado</th></tr></thead>
                <?php foreach ($invoices as $i): ?>
                    <tr>
                        <td><a href="<?= url('invoice', ['id' => $i['id']]) ?>"><?= h($i['number']) ?></a></td>
                        <td><?= fdate($i['issue_date']) ?></td><td><?= fdate($i['due_date']) ?></td>
                        <td class="text-right"><?= money($i['total']) ?></td><td class="text-right"><?= money($i['balance']) ?></td>
                        <td><?= status_label($i['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$invoices): ?><tr><td colspan="6" class="sk-muted">Sin facturas.</td></tr><?php endif; ?>
            </table>
            <h5>Pagos <a class="btn btn-default btn-xs pull-right" href="<?= url('payment_new', ['family_id' => $fid]) ?>"><i class="bi bi-plus-lg"></i> Registrar pago</a></h5>
            <table class="table table-condensed">
                <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th class="text-right">Monto</th><th class="text-right">Reembolsado</th></tr></thead>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><a href="<?= url('payment', ['id' => $p['id']]) ?>"><?= fdate($p['received_at']) ?></a></td>
                        <td><?= h(\Sankris\Billing::METHODS[$p['method']] ?? $p['method']) ?></td>
                        <td><?= h($p['reference'] ?? $p['stripe_payment_intent_id'] ?? '') ?></td>
                        <td class="text-right"><?= money($p['amount']) ?></td><td class="text-right"><?= (float) $p['refunded_amount'] ? money($p['refunded_amount']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?><tr><td colspan="5" class="sk-muted">Sin pagos.</td></tr><?php endif; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-4">
        <?php if ($balance): ?>
        <div class="sk-stat <?= (float) $balance['balance'] > 0 ? 'warn' : '' ?>">
            <div class="v"><?= money($balance['balance']) ?></div>
            <div class="l"><?= (float) $balance['balance'] > 0 ? 'Saldo pendiente' : 'Saldo' ?> · deuda <?= money($balance['debt']) ?> · a favor <?= money($balance['credit']) ?></div>
        </div>
        <?php endif; ?>
        <div class="sk-card">
            <h4>Responsables</h4>
            <?php foreach ($guardians as $g): ?>
                <div style="border-bottom:1px solid #eee;padding-bottom:10px;margin-bottom:10px">
                    <strong><?= h($g['first_name'] . ' ' . $g['last_name']) ?></strong> <small class="sk-muted"><?= h($g['relationship']) ?></small>
                    <?= (int) $g['is_payer'] ? '<span class="label label-primary">Paga</span>' : '' ?>
                    <?= (int) $g['is_emergency_contact'] ? '<span class="label label-default">Emergencias</span>' : '' ?>
                    <div class="sk-muted"><?= h($g['email']) ?> <?= h($g['phone']) ?><?= $g['tax_id'] ? ' · NIT ' . h($g['tax_id']) : '' ?></div>
                    <?php if ($g['stripe_payment_method_id']): ?>
                        <div><i class="bi bi-credit-card"></i> <?= h(ucfirst((string) $g['card_brand'])) ?> •••• <?= h($g['card_last4']) ?> <?= (int) $g['autopay'] ? '<span class="label label-success">Cobro automático</span>' : '' ?></div>
                    <?php endif; ?>
                    <?php if ($edit): ?>
                        <a href="#" data-toggle="collapse" data-target="#g<?= (int) $g['id'] ?>" class="small">Editar</a>
                        <?php if ($auth->can('billing.edit') && (int) $g['is_payer']): ?>
                            · <?= form_open('setup_link', 'class="sk-inline"') ?><input type="hidden" name="guardian_id" value="<?= (int) $g['id'] ?>"><button class="btn btn-link btn-xs" style="padding:0">Enlace para guardar tarjeta (Stripe)</button></form>
                        <?php endif; ?>
                        <div id="g<?= (int) $g['id'] ?>" class="collapse" style="margin-top:8px">
                            <?= form_open('update_guardian') ?><input type="hidden" name="guardian_id" value="<?= (int) $g['id'] ?>">
                            <?php foreach (['first_name' => 'Nombre', 'last_name' => 'Apellido', 'relationship' => 'Parentesco', 'email' => 'Correo', 'phone' => 'Teléfono', 'tax_id' => 'NIT', 'billing_name' => 'Nombre para factura'] as $k => $label): ?>
                                <div class="form-group"><label class="small"><?= $label ?></label><input class="form-control input-sm" name="<?= $k ?>" value="<?= h($g[$k]) ?>"></div>
                            <?php endforeach; ?>
                            <div class="checkbox"><label><input type="checkbox" name="is_payer" value="1" <?= (int) $g['is_payer'] ? 'checked' : '' ?>> Es quien paga</label></div>
                            <div class="checkbox"><label><input type="checkbox" name="is_emergency_contact" value="1" <?= (int) $g['is_emergency_contact'] ? 'checked' : '' ?>> Contacto de emergencia</label></div>
                            <div class="checkbox"><label><input type="checkbox" name="autopay" value="1" <?= (int) $g['autopay'] ? 'checked' : '' ?>> Cobro automático con la tarjeta guardada</label></div>
                            <button class="btn btn-primary btn-sm">Guardar</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($edit): ?>
                <button class="btn btn-default btn-sm" data-toggle="collapse" data-target="#newGuardian"><i class="bi bi-plus-lg"></i> Agregar responsable</button>
                <div id="newGuardian" class="collapse" style="margin-top:8px">
                    <?= form_open('add_guardian') ?>
                    <?php foreach (['first_name' => 'Nombre *', 'last_name' => 'Apellido *', 'relationship' => 'Parentesco', 'email' => 'Correo', 'phone' => 'Teléfono', 'tax_id' => 'NIT'] as $k => $label): ?>
                        <div class="form-group"><label class="small"><?= $label ?></label><input class="form-control input-sm" name="<?= $k ?>"></div>
                    <?php endforeach; ?>
                    <div class="checkbox"><label><input type="checkbox" name="is_payer" value="1" <?= $guardians ? '' : 'checked' ?>> Es quien paga</label></div>
                    <div class="checkbox"><label><input type="checkbox" name="is_emergency_contact" value="1" checked> Contacto de emergencia</label></div>
                    <button class="btn btn-success btn-sm">Agregar</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <div class="sk-card">
            <h4>Datos de la familia</h4>
            <?= form_open('update_family') ?>
            <div class="form-group"><label class="small">Nombre</label><input class="form-control input-sm" name="name" value="<?= h($family['name']) ?>" <?= $edit ? '' : 'disabled' ?>></div>
            <div class="form-group"><label class="small">Estado</label><select class="form-control input-sm" name="status" <?= $edit ? '' : 'disabled' ?>><?= opt(['active' => 'Activa', 'inactive' => 'Inactiva'], $family['status']) ?></select></div>
            <div class="form-group"><label class="small">Etiquetas (separadas por coma)</label><input class="form-control input-sm" name="tags" value="<?= h($family['tags']) ?>" <?= $edit ? '' : 'disabled' ?>></div>
            <div class="form-group"><label class="small">Notas</label><textarea class="form-control input-sm" name="notes" rows="3" <?= $edit ? '' : 'disabled' ?>><?= h($family['notes']) ?></textarea></div>
            <?php if ($edit): ?><button class="btn btn-primary btn-sm">Guardar</button><?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php sk_footer();

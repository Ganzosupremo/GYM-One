<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('subscriptions.view') || deny();
$id = (int) ($_GET['id'] ?? 0);
$sub = $app->subscriptions()->get($id);
if (!$sub) {
    flash('danger', 'Suscripción no encontrada.');
    redirect('families');
}
$svc = $app->subscriptions();
$back = fn() => redirect('subscription', ['id' => $id]);
$nullDate = fn($v) => ($v ?? '') === '' ? null : (string) $v;

on_post('pause', function (array $in) use ($svc, $auth, $id, $back, $nullDate) {
    $auth->can('subscriptions.edit') || deny();
    $svc->pause($id, (string) $in['from'], $nullDate($in['until'] ?? null), (string) ($in['reason'] ?? ''));
    flash('success', 'Suscripción pausada.');
    $back();
});
on_post('resume', function (array $in) use ($svc, $auth, $id, $back) {
    $auth->can('subscriptions.edit') || deny();
    $svc->resume($id, (string) $in['date']);
    flash('success', 'Suscripción reanudada.');
    $back();
});
on_post('cancel', function (array $in) use ($svc, $auth, $id, $back) {
    $auth->can('subscriptions.edit') || deny();
    $svc->cancel($id, (string) $in['date'], (string) ($in['reason'] ?? ''));
    flash('success', 'Cancelación registrada.');
    $back();
});
on_post('reactivate', function (array $in) use ($svc, $auth, $id, $back) {
    $auth->can('subscriptions.edit') || deny();
    $svc->reactivate($id, (string) $in['date']);
    flash('success', 'Suscripción reactivada.');
    $back();
});
on_post('price', function (array $in) use ($svc, $auth, $id, $back) {
    $auth->can('subscriptions.price') || deny();
    if (trim($in['reason'] ?? '') === '') {
        throw new \Sankris\ValidationException('Indica el motivo del cambio de precio.');
    }
    $svc->changePrice($id, (float) $in['price'], (string) $in['reason']);
    flash('success', 'Precio actualizado. Aplica desde el próximo cobro; las facturas ya emitidas no cambian.');
    $back();
});
on_post('discount', function (array $in) use ($svc, $auth, $id, $back) {
    $auth->can('subscriptions.price') || deny();
    $svc->setDiscount($id, (string) $in['discount_type'], (float) ($in['discount_value'] ?? 0), ($in['discount_cycles'] ?? '') === '' ? null : (int) $in['discount_cycles']);
    flash('success', 'Descuento actualizado.');
    $back();
});

$events = $svc->events($id);
$ledger = $app->credits()->ledger($id, 60);
$programs = $sub['program_ids'] ? $app->db->all('SELECT name FROM programs WHERE id IN (' . implode(',', array_map('intval', $sub['program_ids'])) . ')') : [];
$charge = \Sankris\Subscriptions::chargeCents($sub);
$today = date('Y-m-d');
$edit = $auth->can('subscriptions.edit');
sk_header('Suscripción: ' . $sub['plan_name'], 'families');
$eventLabels = ['created' => 'Creada', 'paused' => 'Pausada', 'resumed' => 'Reanudada', 'cancelled' => 'Cancelada', 'reactivated' => 'Reactivada', 'price_changed' => 'Cambio de precio', 'renewed' => 'Cobro generado', 'ended' => 'Terminada'];
$reasonLabels = ['grant' => 'Créditos del período', 'booking' => 'Reserva', 'booking_refund' => 'Devolución', 'adjustment' => 'Ajuste manual', 'expire' => 'Vencimiento'];
?>
<p><a href="<?= url('student', ['id' => $sub['student_id']]) ?>"><i class="bi bi-arrow-left"></i> <?= h($sub['first_name'] . ' ' . $sub['last_name']) ?></a></p>
<div class="row">
    <div class="col-md-5">
        <div class="sk-card">
            <h4><?= h($sub['plan_name']) ?> <?= status_label($sub['status']) ?></h4>
            <table class="table table-condensed">
                <tr><td>Tipo</td><td><?= h(KIND_LABELS[$sub['kind']] ?? $sub['kind']) ?></td></tr>
                <tr><td>Precio contratado</td><td><?= money($sub['price']) ?> · <?= h(PERIOD_LABELS[$sub['billing_period']] ?? $sub['billing_period']) ?></td></tr>
                <tr><td>Descuento</td><td><?= $sub['discount_type'] === 'none' ? '—' : ($sub['discount_type'] === 'percent' ? h((float) $sub['discount_value']) . ' %' : money($sub['discount_value'])) . ($sub['discount_cycles'] ? ' · ' . (int) $sub['discount_cycles_used'] . ' de ' . (int) $sub['discount_cycles'] . ' cobros' : '') ?></td></tr>
                <tr><td>Próximo cargo</td><td><strong><?= money($charge['total'] / 100) ?></strong> el <?= fdate($sub['next_billing_date']) ?></td></tr>
                <tr><td>Créditos</td><td><?= $sub['credits'] === null ? 'Ilimitados' : (int) $sub['credits'] . ' ' . h(mb_strtolower(CREDIT_PERIOD_LABELS[$sub['credit_period']] ?? '')) ?></td></tr>
                <tr><td>Programas</td><td><?php foreach ($programs as $p): ?><span class="sk-chip"><?= h($p['name']) ?></span><?php endforeach; ?><?= $programs ? '' : 'Todos' ?></td></tr>
                <tr><td>Vigencia</td><td><?= fdate($sub['start_date']) ?> – <?= fdate($sub['end_date']) ?></td></tr>
                <tr><td>Renovación</td><td><?= (int) $sub['auto_renew'] ? 'Automática' : 'Manual' ?></td></tr>
                <?php if ($sub['status'] === 'paused'): ?><tr><td>Pausa</td><td>desde <?= fdate($sub['paused_from']) ?> hasta <?= fdate($sub['paused_until']) ?></td></tr><?php endif; ?>
                <?php if ($sub['cancel_at']): ?><tr><td>Cancelación</td><td><?= fdate($sub['cancel_at']) ?></td></tr><?php endif; ?>
                <?php if ($sub['notes']): ?><tr><td>Notas</td><td><?= h($sub['notes']) ?></td></tr><?php endif; ?>
            </table>
        </div>
        <?php if ($edit): ?>
        <div class="sk-card">
            <h4>Acciones</h4>
            <?php if ($sub['status'] === 'active'): ?>
                <?= form_open('pause') ?><h5>Pausar</h5>
                <div class="row"><div class="col-xs-6"><label class="small">Desde</label><input type="date" name="from" class="form-control input-sm" value="<?= $today ?>"></div>
                <div class="col-xs-6"><label class="small">Hasta (opcional)</label><input type="date" name="until" class="form-control input-sm"></div></div>
                <input name="reason" class="form-control input-sm" style="margin:6px 0" placeholder="Motivo (lesión, viaje...)">
                <button class="btn btn-warning btn-sm">Pausar</button></form>
            <?php elseif ($sub['status'] === 'paused'): ?>
                <?= form_open('resume', 'class="form-inline"') ?><h5>Reanudar</h5><input type="date" name="date" class="form-control input-sm" value="<?= $today ?>"> <button class="btn btn-success btn-sm">Reanudar</button>
                <p class="small sk-muted">El cobro que cayó durante la pausa se mueve a la fecha de reanudación.</p></form>
            <?php endif; ?>
            <?php if (in_array($sub['status'], ['active', 'paused'], true)): ?>
                <?= form_open('cancel', 'onsubmit="return confirm(\'¿Cancelar la suscripción?\')"') ?><h5>Cancelar</h5>
                <div class="row"><div class="col-xs-6"><label class="small">Último día</label><input type="date" name="date" class="form-control input-sm" value="<?= h($sub['next_billing_date'] ?: $today) ?>"></div>
                <div class="col-xs-6"><label class="small">Motivo</label><input name="reason" class="form-control input-sm"></div></div>
                <button class="btn btn-danger btn-sm" style="margin-top:6px">Cancelar suscripción</button></form>
            <?php else: ?>
                <?= form_open('reactivate', 'class="form-inline"') ?><h5>Reactivar</h5><input type="date" name="date" class="form-control input-sm" value="<?= $today ?>"> <button class="btn btn-success btn-sm">Reactivar</button></form>
            <?php endif; ?>
            <?php if ($auth->can('subscriptions.price')): ?>
                <?= form_open('price') ?><h5>Cambiar precio</h5>
                <div class="row"><div class="col-xs-5"><input type="number" step="0.01" min="0" name="price" class="form-control input-sm" value="<?= h($sub['price']) ?>"></div>
                <div class="col-xs-7"><input name="reason" class="form-control input-sm" placeholder="Motivo" required></div></div>
                <button class="btn btn-default btn-sm" style="margin-top:6px">Guardar precio</button></form>
                <?= form_open('discount') ?><h5>Descuento</h5>
                <div class="row"><div class="col-xs-4"><select name="discount_type" class="form-control input-sm"><?= opt(['none' => 'Ninguno', 'percent' => '%', 'fixed' => 'Monto'], $sub['discount_type']) ?></select></div>
                <div class="col-xs-4"><input type="number" step="0.01" min="0" name="discount_value" class="form-control input-sm" value="<?= h($sub['discount_value']) ?>"></div>
                <div class="col-xs-4"><input type="number" min="1" name="discount_cycles" class="form-control input-sm" placeholder="Cobros" value="<?= h($sub['discount_cycles']) ?>"></div></div>
                <button class="btn btn-default btn-sm" style="margin-top:6px">Guardar descuento</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-md-7">
        <div class="sk-card">
            <h4>Historial</h4>
            <table class="table table-condensed">
                <?php foreach ($events as $e): ?>
                    <tr><td><?= fdate($e['effective_date']) ?></td><td><strong><?= h($eventLabels[$e['event']] ?? $e['event']) ?></strong></td><td><?= h($e['details']) ?></td><td class="sk-muted small"><?= h($e['worker_name'] ?? '') ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="sk-card">
            <h4>Movimientos de créditos</h4>
            <table class="table table-condensed">
                <thead><tr><th>Fecha</th><th>Movimiento</th><th>Período</th><th class="text-right">Créditos</th><th>Nota</th></tr></thead>
                <?php foreach ($ledger as $l): ?>
                    <tr><td><?= fdate($l['created_at']) ?></td><td><?= h($reasonLabels[$l['reason']] ?? $l['reason']) ?></td><td><?= fdate($l['period_start']) ?> – <?= fdate($l['period_end']) ?></td>
                        <td class="text-right <?= (int) $l['delta'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $l['delta'] > 0 ? '+' : '' ?><?= (int) $l['delta'] ?></td><td><?= h($l['note']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$ledger): ?><tr><td colspan="5" class="sk-muted">Sin movimientos todavía.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php sk_footer();

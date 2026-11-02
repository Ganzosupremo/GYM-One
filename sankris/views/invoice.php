<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('billing.view') || deny();
$id = (int) ($_GET['id'] ?? 0);
$inv = $app->billing()->invoice($id);
if (!$inv) {
    flash('danger', 'Factura no encontrada.');
    redirect('invoices');
}
$back = ['id' => $id];

on_post('pay_link', function () use ($app, $auth, $id, $back) {
    $auth->can('billing.edit') || deny();
    $link = $app->stripe()->invoicePayLink($id);
    flash('success', 'Enlace de pago creado. Envíalo al responsable: ' . $link);
    redirect('invoice', $back);
});
on_post('charge', function () use ($app, $auth, $id, $back) {
    $auth->can('billing.edit') || deny();
    $r = $app->stripe()->chargeInvoice($id);
    flash($r['status'] === 'succeeded' ? 'success' : ($r['status'] === 'skipped' ? 'info' : 'warning'), $r['message']);
    redirect('invoice', $back);
});
on_post('void', function (array $in) use ($app, $auth, $id, $back) {
    $auth->can('billing.void') || deny();
    $app->billing()->void($id, (string) ($in['reason'] ?? ''));
    flash('success', 'Factura anulada.');
    redirect('invoice', $back);
});

$attempts = $app->db->all('SELECT * FROM sk_payment_attempts WHERE invoice_id = ? ORDER BY attempt_no DESC', [$id]);
$links = $app->db->all('SELECT * FROM sk_stripe_links WHERE invoice_id = ? ORDER BY id DESC LIMIT 5', [$id]);
$payer = $inv['payer_guardian_id'] ? $app->families()->guardian((int) $inv['payer_guardian_id']) : null;
$open = $inv['status'] === 'open' && (float) $inv['balance'] > 0;
$stripeOn = $app->stripe()->enabled();
sk_header('Factura ' . $inv['number'], 'invoices');
?>
<p><a href="<?= url('family', ['id' => $inv['family_id']]) ?>"><i class="bi bi-arrow-left"></i> <?= h($inv['family_name']) ?></a></p>
<div class="row">
    <div class="col-md-8">
        <div class="sk-card">
            <h4><?= h($inv['number']) ?> <?= status_label($inv['status']) ?></h4>
            <p>Emitida <?= fdate($inv['issue_date']) ?> · vence <?= fdate($inv['due_date']) ?> · <?= h($payer ? $payer['first_name'] . ' ' . $payer['last_name'] : 'Sin responsable') ?><?= $payer && $payer['tax_id'] ? ' · NIT ' . h($payer['tax_id']) : '' ?></p>
            <table class="table">
                <thead><tr><th>Concepto</th><th>Alumno</th><th>Período</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Descuento</th><th class="text-right">Importe</th></tr></thead>
                <?php foreach ($inv['lines'] as $l): ?>
                    <tr><td><?= h($l['description']) ?></td><td><?= h(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''))) ?></td>
                        <td><?= $l['period_start'] ? fdate($l['period_start']) . ' – ' . fdate($l['period_end']) : '' ?></td>
                        <td class="text-right"><?= (int) $l['quantity'] ?></td><td class="text-right"><?= money($l['unit_price']) ?></td>
                        <td class="text-right"><?= (float) $l['discount'] ? '-' . money($l['discount']) : '' ?></td><td class="text-right"><?= money($l['amount']) ?></td></tr>
                <?php endforeach; ?>
                <tr><td colspan="6" class="text-right">Subtotal</td><td class="text-right"><?= money($inv['subtotal']) ?></td></tr>
                <?php if ((float) $inv['discount_total']): ?><tr><td colspan="6" class="text-right">Descuentos</td><td class="text-right">-<?= money($inv['discount_total']) ?></td></tr><?php endif; ?>
                <tr><td colspan="6" class="text-right"><strong>Total</strong></td><td class="text-right"><strong><?= money($inv['total']) ?></strong></td></tr>
                <tr><td colspan="6" class="text-right">Pagado</td><td class="text-right"><?= money($inv['amount_paid']) ?></td></tr>
                <tr><td colspan="6" class="text-right"><strong>Saldo</strong></td><td class="text-right"><strong><?= money($inv['balance']) ?></strong></td></tr>
            </table>
            <?php if ($inv['notes']): ?><p class="sk-muted"><?= h($inv['notes']) ?></p><?php endif; ?>
            <?php if ($inv['voided_reason']): ?><p class="text-danger">Anulada: <?= h($inv['voided_reason']) ?></p><?php endif; ?>
        </div>
        <div class="sk-card">
            <h4>Pagos aplicados</h4>
            <table class="table table-condensed">
                <?php foreach ($inv['allocations'] as $a): ?>
                    <tr><td><a href="<?= url('payment', ['id' => $a['payment_id']]) ?>"><?= fdate($a['received_at']) ?></a></td><td><?= h(\Sankris\Billing::METHODS[$a['method']] ?? $a['method']) ?></td>
                        <td><?= h($a['reference'] ?? $a['stripe_payment_intent_id'] ?? '') ?></td><td class="text-right"><?= money($a['amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$inv['allocations']): ?><tr><td class="sk-muted">Sin pagos todavía.</td></tr><?php endif; ?>
            </table>
            <?php if ($attempts): ?>
                <h5>Intentos de cobro con tarjeta</h5>
                <table class="table table-condensed">
                    <?php foreach ($attempts as $a): ?>
                        <tr><td>#<?= (int) $a['attempt_no'] ?></td><td><?= fdate($a['created_at']) ?></td><td><?= money($a['amount']) ?></td><td><?= status_label($a['status']) ?></td>
                            <td class="small"><?= h($a['error']) ?><?= $a['next_retry_at'] ? ' · reintento ' . fdate($a['next_retry_at']) : '' ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-4">
        <?php if ($open && $auth->can('billing.edit')): ?>
        <div class="sk-card">
            <h4>Cobrar</h4>
            <a class="btn btn-success btn-block" href="<?= url('payment_new', ['family_id' => $inv['family_id'], 'invoice_id' => $id]) ?>"><i class="bi bi-cash"></i> Registrar pago (efectivo, transferencia, POS)</a>
            <?php if ($stripeOn): ?>
                <?php if ($payer && $payer['stripe_payment_method_id']): ?>
                    <?= form_open('charge', 'onsubmit="return confirm(\'¿Cobrar ' . h($inv['balance']) . ' a la tarjeta guardada?\')" style="margin-top:8px"') ?>
                    <button class="btn btn-primary btn-block"><i class="bi bi-credit-card"></i> Cobrar a la tarjeta <?= h(ucfirst((string) $payer['card_brand'])) ?> •••• <?= h($payer['card_last4']) ?></button></form>
                <?php endif; ?>
                <?= form_open('pay_link', 'style="margin-top:8px"') ?><button class="btn btn-default btn-block"><i class="bi bi-link-45deg"></i> Crear enlace de pago con Stripe</button></form>
            <?php else: ?>
                <p class="small sk-muted" style="margin-top:8px">Stripe no está configurado. Ver <a href="<?= url('settings') ?>">Reglas y Stripe</a>.</p>
            <?php endif; ?>
            <?php foreach ($links as $l): ?>
                <div class="small" style="margin-top:6px;word-break:break-all"><?= status_label($l['status'] === 'open' ? 'pending' : ($l['status'] === 'completed' ? 'succeeded' : 'void')) ?> <a href="<?= h($l['url']) ?>" target="_blank" rel="noopener">enlace del <?= fdate($l['created_at']) ?></a></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($inv['status'] === 'open' && $auth->can('billing.void')): ?>
        <div class="sk-card">
            <h4>Anular</h4>
            <?= form_open('void', 'onsubmit="return confirm(\'¿Anular la factura?\')"') ?>
            <input name="reason" class="form-control input-sm" placeholder="Motivo" required>
            <button class="btn btn-danger btn-sm" style="margin-top:6px">Anular factura</button>
            <p class="small sk-muted">Sólo se anulan facturas sin pagos aplicados. La factura queda en el historial.</p>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php sk_footer();

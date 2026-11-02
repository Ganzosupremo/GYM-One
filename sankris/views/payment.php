<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('billing.view') || deny();
$id = (int) ($_GET['id'] ?? 0);
$p = $app->db->one('SELECT p.*, f.name AS family_name, CONCAT(w.firstname, " ", w.lastname) AS worker_name FROM sk_payments p JOIN sk_families f ON f.id = p.family_id LEFT JOIN workers w ON w.userid = p.created_by WHERE p.id = ?', [$id]);
if (!$p) {
    flash('danger', 'Pago no encontrado.');
    redirect('payments');
}

on_post('refund', function (array $in) use ($app, $auth, $id) {
    $auth->can('billing.refund') || deny();
    if (trim($in['reason'] ?? '') === '') {
        throw new \Sankris\ValidationException('Indica el motivo del reembolso.');
    }
    $app->stripe()->refund($id, $in['amount'] ?? 0, (string) $in['reason'], (string) ($in['request_key'] ?? ''));
    flash('success', 'Reembolso registrado.');
    redirect('payment', ['id' => $id]);
});
on_post('allocate', function (array $in) use ($app, $auth, $id) {
    $auth->can('billing.edit') || deny();
    $app->billing()->allocate($id, (int) $in['invoice_id'], \Sankris\Money::toCents($in['amount'] ?? 0));
    flash('success', 'Pago aplicado a la factura.');
    redirect('payment', ['id' => $id]);
});

$allocs = $app->db->all('SELECT a.*, i.number FROM sk_payment_allocations a JOIN sk_invoices i ON i.id = a.invoice_id WHERE a.payment_id = ? ORDER BY a.id', [$id]);
$refunds = $app->db->all('SELECT r.*, CONCAT(w.firstname, " ", w.lastname) AS worker_name FROM sk_refunds r LEFT JOIN workers w ON w.userid = r.worker_id WHERE r.payment_id = ? ORDER BY r.id', [$id]);
$unallocated = $app->billing()->unallocatedCents($id);
$refundable = \Sankris\Money::toCents($p['amount']) - \Sankris\Money::toCents($p['refunded_amount']);
$open = $unallocated > 0 ? $app->billing()->invoices(['family_id' => $p['family_id'], 'status' => 'open'], 50) : [];
sk_header('Pago del ' . fdate($p['received_at']), 'payments');
?>
<p><a href="<?= url('family', ['id' => $p['family_id']]) ?>"><i class="bi bi-arrow-left"></i> <?= h($p['family_name']) ?></a></p>
<div class="row">
    <div class="col-md-7">
        <div class="sk-card">
            <h4><?= money($p['amount']) ?> · <?= h(\Sankris\Billing::METHODS[$p['method']] ?? $p['method']) ?></h4>
            <table class="table table-condensed">
                <tr><td>Recibido</td><td><?= fdate($p['received_at']) ?></td></tr>
                <tr><td>Referencia</td><td><?= h($p['reference'] ?? '—') ?></td></tr>
                <?php if ($p['stripe_payment_intent_id']): ?><tr><td>Stripe</td><td><code><?= h($p['stripe_payment_intent_id']) ?></code></td></tr><?php endif; ?>
                <tr><td>Registrado por</td><td><?= h($p['worker_name'] ?: ($p['method'] === 'stripe' ? 'Stripe (automático)' : '—')) ?> · <?= fdate($p['created_at']) ?></td></tr>
                <tr><td>Sin aplicar (saldo a favor)</td><td><?= money($unallocated / 100) ?></td></tr>
                <?php if ($p['notes']): ?><tr><td>Notas</td><td><?= h($p['notes']) ?></td></tr><?php endif; ?>
            </table>
            <h5>Aplicado a</h5>
            <table class="table table-condensed">
                <?php foreach ($allocs as $a): ?>
                    <tr><td><a href="<?= url('invoice', ['id' => $a['invoice_id']]) ?>"><?= h($a['number']) ?></a></td><td class="text-right"><?= money($a['amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$allocs): ?><tr><td class="sk-muted">No está aplicado a ninguna factura.</td></tr><?php endif; ?>
            </table>
            <?php if ($open && $auth->can('billing.edit')): ?>
                <?= form_open('allocate', 'class="form-inline"') ?>
                <select name="invoice_id" class="form-control input-sm"><?php foreach ($open as $i): ?><option value="<?= (int) $i['id'] ?>"><?= h($i['number']) ?> · saldo <?= money($i['balance']) ?></option><?php endforeach; ?></select>
                <input type="number" step="0.01" min="0.01" name="amount" class="form-control input-sm" value="<?= h(\Sankris\Money::fromCents($unallocated)) ?>">
                <button class="btn btn-default btn-sm">Aplicar saldo</button></form>
            <?php endif; ?>
            <?php if ($refunds): ?>
                <h5>Reembolsos</h5>
                <table class="table table-condensed">
                    <?php foreach ($refunds as $r): ?>
                        <tr><td><?= fdate($r['created_at']) ?></td><td><?= money($r['amount']) ?></td><td><?= h($r['reason']) ?></td><td class="small sk-muted"><?= h($r['worker_name'] ?: 'Stripe') ?><?= $r['stripe_refund_id'] ? ' · ' . h($r['stripe_refund_id']) : '' ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-5">
        <?php if ($refundable > 0 && $auth->can('billing.refund')): ?>
        <div class="sk-card">
            <h4>Reembolsar</h4>
            <?= form_open('refund', 'onsubmit="return confirm(\'¿Registrar el reembolso?' . ($p['method'] === 'stripe' ? ' Se devolverá el dinero a la tarjeta en Stripe.' : '') . '\')"') ?><?= request_key() ?>
            <div class="form-group"><label class="small">Monto (máximo <?= money($refundable / 100) ?>)</label><input type="number" step="0.01" min="0.01" max="<?= h(\Sankris\Money::fromCents($refundable)) ?>" name="amount" class="form-control input-sm" value="<?= h(\Sankris\Money::fromCents($refundable)) ?>"></div>
            <div class="form-group"><label class="small">Motivo</label><input name="reason" class="form-control input-sm" required></div>
            <button class="btn btn-danger btn-sm">Reembolsar</button>
            <p class="small sk-muted"><?= $p['method'] === 'stripe' ? 'El reembolso se hace en Stripe y la factura vuelve a quedar con saldo.' : 'Registra aquí la devolución en efectivo o transferencia. La factura vuelve a quedar con saldo.' ?></p>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php sk_footer();

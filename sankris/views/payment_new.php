<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('billing.edit') || deny();
$fid = (int) ($_GET['family_id'] ?? 0);
$invoiceId = (int) ($_GET['invoice_id'] ?? 0);

on_post('record_payment', function (array $in) use ($app) {
    $familyId = (int) ($in['family_id'] ?? 0);
    $app->families()->family($familyId) ?? throw new \Sankris\ValidationException('Elige una familia.');
    $method = (string) ($in['method'] ?? '');
    if ($method === 'stripe') {
        throw new \Sankris\ValidationException('Los pagos de Stripe se registran solos desde Stripe.');
    }
    $alloc = [];
    foreach ((array) ($in['alloc'] ?? []) as $invId => $amount) {
        if ($amount !== '' && (float) $amount > 0) {
            $alloc[(int) $invId] = $amount;
        }
    }
    $opts = [
        'reference' => $in['reference'] ?? '', 'notes' => $in['notes'] ?? '',
        'received_at' => ($in['received_at'] ?? '') !== '' ? str_replace('T', ' ', $in['received_at']) . (strlen($in['received_at']) === 16 ? ':00' : '') : date('Y-m-d H:i:s'),
        'idempotency_key' => 'manual:' . preg_replace('/[^a-f0-9]/', '', (string) ($in['request_key'] ?? '')),
    ];
    if ($alloc) {
        $opts['allocations'] = $alloc;
    }
    $id = $app->billing()->recordPayment($familyId, $method, $in['amount'] ?? 0, $opts);
    flash('success', 'Pago registrado.');
    redirect('payment', ['id' => $id]);
});

$families = $app->db->all("SELECT id, name FROM sk_families ORDER BY name");
$open = $fid ? $app->billing()->invoices(['family_id' => $fid, 'status' => 'open'], 100) : [];
usort($open, fn($a, $b) => [$a['due_date'], $a['id']] <=> [$b['due_date'], $b['id']]);
$sum = array_sum(array_map(fn($i) => (float) $i['balance'], $open));
$methods = \Sankris\Billing::METHODS;
unset($methods['stripe']);
sk_header('Registrar pago', 'payments');
?>
<div class="sk-card">
    <form method="get" class="form-inline" style="margin-bottom:14px">
        <input type="hidden" name="p" value="payment_new">
        <label>Familia</label>
        <select name="family_id" class="form-control" onchange="this.form.submit()"><?= opt(['' => 'Elige una familia'] + array_column($families, 'name', 'id'), $fid) ?></select>
    </form>
    <?php if ($fid): ?>
    <?= form_open('record_payment') ?><?= request_key() ?>
    <input type="hidden" name="family_id" value="<?= $fid ?>">
    <div class="row sk-form-row">
        <div class="col-sm-3 form-group"><label>Método</label><select class="form-control" name="method"><?= opt($methods, 'cash') ?></select></div>
        <div class="col-sm-3 form-group"><label>Monto recibido</label><input type="number" step="0.01" min="0.01" class="form-control" name="amount" id="amount" required value="<?= $invoiceId ? h(array_values(array_filter($open, fn($i) => (int) $i['id'] === $invoiceId))[0]['balance'] ?? '') : '' ?>"></div>
        <div class="col-sm-3 form-group"><label>Fecha</label><input type="datetime-local" class="form-control" name="received_at" value="<?= date('Y-m-d\TH:i') ?>"></div>
        <div class="col-sm-3 form-group"><label>Referencia</label><input class="form-control" name="reference" placeholder="No. de boleta, voucher..."></div>
    </div>
    <h5>Aplicar a facturas</h5>
    <p class="small sk-muted">Deja los montos vacíos para aplicar a las facturas más antiguas primero. Lo que sobre queda como saldo a favor de la familia.</p>
    <table class="table table-condensed">
        <thead><tr><th>Factura</th><th>Vence</th><th class="text-right">Saldo</th><th style="width:150px">Aplicar</th></tr></thead>
        <?php foreach ($open as $i): ?>
            <tr><td><?= h($i['number']) ?></td><td><?= fdate($i['due_date']) ?></td><td class="text-right"><?= money($i['balance']) ?></td>
                <td><input type="number" step="0.01" min="0" max="<?= h($i['balance']) ?>" class="form-control input-sm" name="alloc[<?= (int) $i['id'] ?>]" value="<?= (int) $i['id'] === $invoiceId ? h($i['balance']) : '' ?>"></td></tr>
        <?php endforeach; ?>
        <?php if (!$open): ?><tr><td colspan="4" class="sk-muted">La familia no tiene facturas pendientes. El pago quedará como saldo a favor.</td></tr><?php endif; ?>
        <tr><td colspan="2"></td><td class="text-right"><strong><?= money($sum) ?></strong></td><td></td></tr>
    </table>
    <div class="form-group"><label class="small">Notas</label><input class="form-control" name="notes"></div>
    <button class="btn btn-success">Registrar pago</button>
    </form>
    <?php endif; ?>
</div>
<?php sk_footer();

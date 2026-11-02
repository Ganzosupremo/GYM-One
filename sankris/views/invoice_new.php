<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('billing.edit') || deny();
$fid = (int) ($_GET['family_id'] ?? 0);

on_post('create_invoice', function (array $in) use ($app) {
    $familyId = (int) ($in['family_id'] ?? 0);
    $app->families()->family($familyId) ?? throw new \Sankris\ValidationException('Elige una familia.');
    $lines = [];
    foreach ((array) ($in['lines'] ?? []) as $l) {
        if (trim($l['description'] ?? '') === '' && ($l['amount'] ?? '') === '') {
            continue;
        }
        $lines[] = [
            'description' => $l['description'] ?? '', 'amount' => $l['amount'] === '' ? 0 : $l['amount'], 'quantity' => $l['quantity'] ?? 1,
            'discount' => ($l['discount'] ?? '') === '' ? 0 : $l['discount'], 'student_id' => ($l['student_id'] ?? '') === '' ? null : (int) $l['student_id'],
        ];
    }
    $opts = ['notes' => $in['notes'] ?? '', 'idempotency_key' => 'manual:' . preg_replace('/[^a-f0-9]/', '', (string) ($in['request_key'] ?? ''))];
    if (($in['due_date'] ?? '') !== '') {
        $opts['due_date'] = $in['due_date'];
    }
    $id = $app->billing()->createInvoice($familyId, $lines, $opts);
    flash('success', 'Factura creada.');
    redirect('invoice', ['id' => $id]);
});

$families = $app->db->all("SELECT id, name FROM sk_families WHERE status = 'active' ORDER BY name");
$students = $fid ? $app->families()->students($fid) : [];
$studentOpts = ['' => '—'];
foreach ($students as $s) {
    $studentOpts[$s['id']] = $s['first_name'] . ' ' . $s['last_name'];
}
$plans = $app->subscriptions()->plans(true);
sk_header('Factura manual', 'invoices');
?>
<div class="sk-card">
    <form method="get" class="form-inline" style="margin-bottom:14px">
        <input type="hidden" name="p" value="invoice_new">
        <label>Familia</label>
        <select name="family_id" class="form-control" onchange="this.form.submit()"><?= opt(['' => 'Elige una familia'] + array_column($families, 'name', 'id'), $fid) ?></select>
    </form>
    <?php if ($fid): ?>
    <?= form_open('create_invoice') ?><?= request_key() ?>
    <input type="hidden" name="family_id" value="<?= $fid ?>">
    <p class="sk-muted small">Úsala para inscripciones, campamentos, uniformes, competencias o reposiciones. Las mensualidades se facturan solas desde las suscripciones.</p>
    <datalist id="planNames"><?php foreach ($plans as $p): ?><option value="<?= h($p['name']) ?>"><?php endforeach; ?></datalist>
    <table class="table">
        <thead><tr><th>Concepto</th><th>Alumno</th><th style="width:90px">Cant.</th><th style="width:130px">Precio</th><th style="width:130px">Descuento</th></tr></thead>
        <?php for ($i = 0; $i < 4; $i++): ?>
            <tr>
                <td><input class="form-control input-sm" name="lines[<?= $i ?>][description]" list="planNames" <?= $i === 0 ? 'required' : '' ?>></td>
                <td><select class="form-control input-sm" name="lines[<?= $i ?>][student_id]"><?= opt($studentOpts, '') ?></select></td>
                <td><input type="number" min="1" class="form-control input-sm" name="lines[<?= $i ?>][quantity]" value="1"></td>
                <td><input type="number" step="0.01" min="0" class="form-control input-sm" name="lines[<?= $i ?>][amount]" <?= $i === 0 ? 'required' : '' ?>></td>
                <td><input type="number" step="0.01" min="0" class="form-control input-sm" name="lines[<?= $i ?>][discount]"></td>
            </tr>
        <?php endfor; ?>
    </table>
    <div class="row">
        <div class="col-sm-3 form-group"><label class="small">Vence</label><input type="date" class="form-control input-sm" name="due_date" placeholder="Según reglas"></div>
        <div class="col-sm-9 form-group"><label class="small">Notas</label><input class="form-control input-sm" name="notes"></div>
    </div>
    <button class="btn btn-success">Crear factura</button>
    </form>
    <?php endif; ?>
</div>
<?php sk_footer();

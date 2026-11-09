<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('billing.view') || deny();
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$method = (string) ($_GET['method'] ?? '');
$rows = $app->billing()->payments(['from' => $from, 'to' => $to, 'method' => $method], 500);
$totals = [];
foreach ($rows as $p) {
    $totals[$p['method']] = ($totals[$p['method']] ?? 0) + \Sankris\Money::toCents($p['amount']) - \Sankris\Money::toCents($p['refunded_amount']);
}
sk_header('Pagos', 'payments');
?>
<div class="sk-card">
    <form class="form-inline" method="get">
        <input type="hidden" name="p" value="payments">
        <label>Del</label> <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
        <label>al</label> <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
        <select class="form-control" name="method"><?= opt(['' => 'Todos los métodos'] + \Sankris\Billing::METHODS, $method) ?></select>
        <button class="btn btn-primary"><i class="bi bi-search"></i> Filtrar</button>
        <?php if ($auth->can('billing.edit')): ?><a class="btn btn-success pull-right" href="<?= url('payment_new') ?>"><i class="bi bi-plus-lg"></i> Registrar pago</a><?php endif; ?>
    </form>
</div>
<div class="row">
    <?php foreach (\Sankris\Billing::METHODS as $k => $label): ?>
        <div class="col-sm-3 col-xs-6"><div class="sk-stat <?= $k === 'stripe' ? 'blue' : '' ?>"><div class="v" style="font-size:19px"><?= money(($totals[$k] ?? 0) / 100) ?></div><div class="l"><?= h($label) ?> (neto)</div></div></div>
    <?php endforeach; ?>
</div>
<div class="sk-card">
    <table class="table table-hover table-condensed">
        <thead><tr><th>Fecha</th><th>Familia</th><th>Método</th><th>Referencia</th><th class="text-right">Monto</th><th class="text-right">Aplicado</th><th class="text-right">Reembolsado</th></tr></thead>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td><a href="<?= url('payment', ['id' => $p['id']]) ?>"><?= fdate($p['received_at']) ?></a></td>
                <td><a href="<?= url('family', ['id' => $p['family_id']]) ?>"><?= h($p['family_name']) ?></a></td>
                <td><?= h(\Sankris\Billing::METHODS[$p['method']] ?? $p['method']) ?></td>
                <td class="small"><?= h($p['reference'] ?: $p['stripe_payment_intent_id']) ?></td>
                <td class="text-right"><?= money($p['amount']) ?></td>
                <td class="text-right"><?= money($p['allocated']) ?></td>
                <td class="text-right"><?= (float) $p['refunded_amount'] ? money($p['refunded_amount']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="sk-muted">Sin pagos en ese período.</td></tr><?php endif; ?>
    </table>
</div>
<?php sk_footer();

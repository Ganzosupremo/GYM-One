<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('billing.view') || deny();
$today = date('Y-m-d');

on_post('generate', function () use ($app, $auth, $today) {
    $auth->can('billing.edit') || deny();
    $r = $app->billing()->generateRecurring($today);
    flash('success', 'Facturas generadas: ' . count($r) . '.');
    redirect('invoices');
});

$status = (string) ($_GET['status'] ?? 'open');
$q = trim((string) ($_GET['q'] ?? ''));
$rows = $app->billing()->invoices(['status' => $status, 'q' => $q, 'today' => $today], 300);
$aging = $app->billing()->aging($today);
sk_header('Facturas', 'invoices');
$b = $aging['buckets'];
?>
<div class="row">
    <?php foreach (['current' => ['Sin vencer', ''], 'd30' => ['1–30 días', 'warn'], 'd60' => ['31–60 días', 'warn'], 'd90' => ['61–90 días', 'pink'], 'd90plus' => ['Más de 90 días', 'pink']] as $k => [$label, $cls]): ?>
        <div class="col-sm-2 col-xs-6"><div class="sk-stat <?= $cls ?>"><div class="v" style="font-size:19px"><?= money($b[$k] / 100) ?></div><div class="l"><?= $label ?></div></div></div>
    <?php endforeach; ?>
    <div class="col-sm-2 col-xs-6"><div class="sk-stat blue"><div class="v" style="font-size:19px"><?= money(array_sum($b) / 100) ?></div><div class="l">Deuda total</div></div></div>
</div>
<div class="sk-card">
    <form class="form-inline" method="get">
        <input type="hidden" name="p" value="invoices">
        <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Número o familia">
        <select class="form-control" name="status"><?= opt(['open' => 'Pendientes', 'overdue' => 'Vencidas', 'paid' => 'Pagadas', 'void' => 'Anuladas', '' => 'Todas'], $status) ?></select>
        <button class="btn btn-primary"><i class="bi bi-search"></i> Filtrar</button>
        <?php if ($auth->can('billing.edit')): ?>
            <a class="btn btn-success pull-right" href="<?= url('invoice_new') ?>"><i class="bi bi-plus-lg"></i> Factura manual</a>
        <?php endif; ?>
    </form>
    <?php if ($auth->can('billing.edit')): ?>
        <?= form_open('generate', 'style="margin-top:10px"') ?><button class="btn btn-default btn-sm"><i class="bi bi-arrow-repeat"></i> Generar facturas pendientes de suscripciones</button>
        <small class="sk-muted">La tarea diaria lo hace sola; este botón no duplica facturas.</small></form>
    <?php endif; ?>
</div>
<div class="sk-card">
    <table class="table table-hover table-condensed">
        <thead><tr><th>Número</th><th>Familia</th><th>Emitida</th><th>Vence</th><th class="text-right">Total</th><th class="text-right">Pagado</th><th class="text-right">Saldo</th><th>Estado</th><th>Tarjeta</th></tr></thead>
        <?php foreach ($rows as $i): $overdue = $i['status'] === 'open' && $i['due_date'] < $today; ?>
            <tr>
                <td><a href="<?= url('invoice', ['id' => $i['id']]) ?>"><strong><?= h($i['number']) ?></strong></a></td>
                <td><a href="<?= url('family', ['id' => $i['family_id']]) ?>"><?= h($i['family_name']) ?></a></td>
                <td><?= fdate($i['issue_date']) ?></td>
                <td class="<?= $overdue ? 'text-danger' : '' ?>"><?= fdate($i['due_date']) ?></td>
                <td class="text-right"><?= money($i['total']) ?></td>
                <td class="text-right"><?= money($i['amount_paid']) ?></td>
                <td class="text-right"><strong><?= money($i['balance']) ?></strong></td>
                <td><?= status_label($i['status']) ?><?= $overdue ? ' <span class="label label-danger">Vencida</span>' : '' ?></td>
                <td><?= $i['last_attempt_status'] ? status_label($i['last_attempt_status']) : ((int) $i['autopay'] ? '<span class="sk-muted small">automático</span>' : '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="sk-muted">No hay facturas con ese filtro.</td></tr><?php endif; ?>
    </table>
</div>
<?php if ($aging['families']): ?>
<div class="sk-card">
    <h4>Antigüedad de la deuda por familia</h4>
    <table class="table table-condensed">
        <thead><tr><th>Familia</th><th class="text-right">Sin vencer</th><th class="text-right">1–30</th><th class="text-right">31–60</th><th class="text-right">61–90</th><th class="text-right">+90</th><th class="text-right">Total</th></tr></thead>
        <?php foreach ($aging['families'] as $f): ?>
            <tr><td><a href="<?= url('family', ['id' => $f['family_id']]) ?>"><?= h($f['family_name']) ?></a></td>
                <?php foreach (['current', 'd30', 'd60', 'd90', 'd90plus', 'total'] as $k): ?><td class="text-right"><?= $f[$k] ? money($f[$k] / 100) : '' ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>
<?php sk_footer();

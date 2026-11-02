<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('billing.refund') || deny();
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$run = isset($_GET['run']);
$rows = $run ? $app->stripe()->reconcile($from, $to) : [];
$issues = array_filter($rows, fn($r) => $r['issue'] !== null);
$events = $app->db->all('SELECT event_id, type, received_at, processed_at, error FROM sk_stripe_events WHERE error IS NOT NULL OR processed_at IS NULL ORDER BY received_at DESC LIMIT 20');
sk_header('Conciliación con Stripe', 'reconcile');
?>
<div class="sk-card">
    <p class="sk-muted">Compara cada pago de Stripe registrado aquí con lo que Stripe tiene (estado y monto). Úsalo al cierre de mes junto con el reporte de pagos de Stripe.</p>
    <form class="form-inline" method="get">
        <input type="hidden" name="p" value="reconcile"><input type="hidden" name="run" value="1">
        <label>Del</label> <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
        <label>al</label> <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
        <button class="btn btn-primary" <?= $app->stripe()->enabled() ? '' : 'disabled' ?>><i class="bi bi-arrow-left-right"></i> Conciliar</button>
        <?php if (!$app->stripe()->enabled()): ?><span class="sk-muted small">Stripe no está configurado.</span><?php endif; ?>
    </form>
</div>
<?php if ($run): ?>
<div class="sk-card">
    <h4><?= count($rows) ?> pagos revisados · <?= count($issues) ?> con diferencias</h4>
    <table class="table table-condensed">
        <thead><tr><th>Fecha</th><th>Familia</th><th>PaymentIntent</th><th class="text-right">Monto</th><th>Resultado</th></tr></thead>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['issue'] ? 'danger' : '' ?>">
                <td><a href="<?= url('payment', ['id' => $r['id']]) ?>"><?= fdate($r['received_at']) ?></a></td><td><?= h($r['family_name']) ?></td>
                <td><code><?= h($r['stripe_payment_intent_id']) ?></code></td><td class="text-right"><?= money($r['amount']) ?></td>
                <td><?= $r['issue'] ? h($r['issue']) : ($r['checked'] ? '<span class="text-success">Coincide</span>' : 'Sin verificar') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>
<div class="sk-card">
    <h4>Avisos de Stripe con error</h4>
    <table class="table table-condensed">
        <?php foreach ($events as $e): ?>
            <tr><td><?= fdate($e['received_at']) ?></td><td><code><?= h($e['type']) ?></code></td><td class="small"><?= h($e['event_id']) ?></td><td class="text-danger small"><?= h($e['error'] ?? 'Sin procesar') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$events): ?><tr><td class="sk-muted">Todos los avisos se procesaron bien.</td></tr><?php endif; ?>
    </table>
</div>
<?php sk_footer();

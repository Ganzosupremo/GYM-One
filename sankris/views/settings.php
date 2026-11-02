<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('settings.edit') || deny();

$fields = [
    'currency' => ['Moneda (código ISO, debe coincidir con la de Stripe)', 'text'],
    'invoice_prefix' => ['Prefijo de las facturas', 'text'],
    'invoice_due_days' => ['Días para pagar una factura', 'number'],
    'billing_lead_days' => ['Emitir la factura con estos días de anticipación', 'number'],
    'retry_schedule_days' => ['Reintentos de cobro con tarjeta (días después del fallo, separados por coma)', 'text'],
    'recurring_booking_horizon_days' => ['Crear reservas fijas con estos días de anticipación', 'number'],
    'public_base_url' => ['Dirección pública del sistema (para enlaces de pago y webhook)', 'url'],
];
on_post('save_settings', function (array $in) use ($app, $fields, $auth) {
    foreach ($fields as $key => [$label, $type]) {
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $value = trim((string) $in[$key]);
        if ($type === 'number' && (!ctype_digit($value))) {
            throw new \Sankris\ValidationException($label . ': debe ser un número entero.');
        }
        if ($key === 'currency' && !preg_match('/^[A-Za-z]{3}$/', $value)) {
            throw new \Sankris\ValidationException('La moneda debe ser un código de 3 letras, por ejemplo GTQ o USD.');
        }
        if ($key === 'retry_schedule_days' && !preg_match('/^\d+(\s*,\s*\d+)*$/', $value)) {
            throw new \Sankris\ValidationException('Los reintentos deben ser números separados por coma, por ejemplo 1,3,7.');
        }
        $before = $app->settings->get($key);
        if ($before !== $value) {
            $app->settings->set($key, $key === 'currency' ? strtoupper($value) : $value, $auth->id());
            $app->audit()->log('setting', null, 'update', [$key => $before], [$key => $value]);
        }
    }
    flash('success', 'Reglas guardadas.');
    redirect('settings');
});
on_post('migrate', function () use ($app) {
    $done = $app->migrator()->migrate();
    flash('success', $done ? 'Migraciones aplicadas: ' . implode(', ', $done) : 'La base de datos ya estaba al día.');
    redirect('settings');
});

$key = (string) ($app->env['STRIPE_SECRET_KEY'] ?? '');
$mode = $key === '' ? null : (str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_') ? 'live' : 'test');
$hasSecret = ($app->env['STRIPE_WEBHOOK_SECRET'] ?? '') !== '';
$base = $app->publicBaseUrl();
$lastEvent = $app->db->one('SELECT type, received_at FROM sk_stripe_events ORDER BY received_at DESC LIMIT 1');
$applied = $app->db->all('SELECT name, applied_at FROM sk_migrations ORDER BY name');
sk_header('Reglas y Stripe', 'settings');
?>
<div class="row">
    <div class="col-md-6">
        <div class="sk-card">
            <h4>Reglas de cobro y reservas</h4>
            <?= form_open('save_settings') ?>
            <?php foreach ($fields as $k => [$label, $type]): ?>
                <div class="form-group"><label class="small"><?= h($label) ?></label>
                    <input type="<?= $type === 'number' ? 'number' : 'text' ?>" <?= $type === 'number' ? 'min="0"' : '' ?> class="form-control input-sm" name="<?= $k ?>" value="<?= h($app->settings->get($k)) ?>"></div>
            <?php endforeach; ?>
            <p class="small sk-muted">Las ventanas de reserva, cancelación y reposiciones se configuran por programa en <a href="<?= url('schedule') ?>">Clases y asistencia</a>.</p>
            <button class="btn btn-primary btn-sm">Guardar</button>
            </form>
        </div>
    </div>
    <div class="col-md-6">
        <div class="sk-card">
            <h4>Stripe</h4>
            <table class="table table-condensed">
                <tr><td>Clave secreta</td><td><?= $mode === null ? '<span class="label label-danger">Falta</span>' : ($mode === 'live' ? '<span class="label label-success">Modo real</span>' : '<span class="label label-warning">Modo de prueba</span>') ?></td></tr>
                <tr><td>Secreto del webhook</td><td><?= $hasSecret ? '<span class="label label-success">Configurado</span>' : '<span class="label label-danger">Falta</span>' ?></td></tr>
                <tr><td>URL del webhook</td><td><code style="word-break:break-all"><?= h($base) ?>/stripe/webhook.php</code></td></tr>
                <tr><td>Último aviso recibido</td><td><?= $lastEvent ? h($lastEvent['type']) . ' · ' . fdate($lastEvent['received_at']) : 'Ninguno todavía' ?></td></tr>
                <tr><td>Moneda</td><td><?= h($app->currency()) ?></td></tr>
            </table>
            <p class="small"><strong>Cómo conectarlo:</strong></p>
            <ol class="small">
                <li>En Stripe, Desarrolladores → Claves de API: copia la clave secreta (o mejor, una clave restringida) en el archivo <code>.env</code> como <code>STRIPE_SECRET_KEY</code>.</li>
                <li>En Desarrolladores → Webhooks, agrega la URL de arriba con los eventos <code>checkout.session.completed</code>, <code>checkout.session.expired</code>, <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code> y <code>charge.refunded</code>.</li>
                <li>Copia el secreto de firma del webhook (<code>whsec_...</code>) en <code>.env</code> como <code>STRIPE_WEBHOOK_SECRET</code>.</li>
                <li>Programa la tarea diaria: <code>php sankris/bin/daily.php</code> (cron, una vez al día temprano).</li>
            </ol>
            <p class="small sk-muted">Las claves nunca se guardan en la base de datos ni se muestran aquí.</p>
        </div>
        <div class="sk-card">
            <h4>Base de datos</h4>
            <p class="small"><?= count($applied) ?> migraciones aplicadas. Pendientes: <?= count($app->migrator()->pending()) ?>.</p>
            <?= form_open('migrate') ?><button class="btn btn-default btn-sm">Aplicar migraciones pendientes</button></form>
        </div>
    </div>
</div>
<?php sk_footer();

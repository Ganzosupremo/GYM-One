<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('audit.view') || deny();
$entity = (string) ($_GET['entity'] ?? '');
$entityId = (string) ($_GET['entity_id'] ?? '');
$where = ['1=1'];
$params = [];
if ($entity !== '') {
    $where[] = 'a.entity = ?';
    $params[] = $entity;
}
if ($entityId !== '' && ctype_digit($entityId)) {
    $where[] = 'a.entity_id = ?';
    $params[] = (int) $entityId;
}
$rows = $app->db->all('SELECT a.*, CONCAT(w.firstname, " ", w.lastname) AS worker_name FROM sk_audit_log a LEFT JOIN workers w ON w.userid = a.worker_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT 300', $params);
$entities = array_column($app->db->all('SELECT DISTINCT entity FROM sk_audit_log ORDER BY entity'), 'entity', 'entity');
$labels = ['family' => 'Familia', 'guardian' => 'Responsable', 'student' => 'Alumno', 'plan' => 'Plan', 'subscription' => 'Suscripción', 'booking' => 'Reserva',
    'recurring_booking' => 'Reserva fija', 'session' => 'Clase', 'invoice' => 'Factura', 'payment' => 'Pago', 'credits' => 'Créditos', 'setting' => 'Regla',
    'staff_role' => 'Rol', 'program' => 'Programa', 'booking_alert' => 'Alerta'];
$diff = function (?string $before, ?string $after): string {
    $b = $before ? (json_decode($before, true) ?: []) : [];
    $a = $after ? (json_decode($after, true) ?: []) : [];
    $out = [];
    foreach ($a as $k => $v) {
        if (in_array($k, ['updated_at', 'created_at'], true)) {
            continue;
        }
        $old = $b[$k] ?? null;
        if ((string) json_encode($old) === (string) json_encode($v) && $b) {
            continue;
        }
        $fmt = fn($x) => is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : (string) ($x ?? '∅');
        $out[] = '<strong>' . h($k) . '</strong>: ' . ($b ? h($fmt($old)) . ' → ' : '') . h($fmt($v));
    }
    return implode('<br>', array_slice($out, 0, 12));
};
sk_header('Historial de cambios', 'audit');
?>
<div class="sk-card">
    <form class="form-inline" method="get">
        <input type="hidden" name="p" value="audit">
        <select name="entity" class="form-control"><?= opt(['' => 'Todo'] + array_map(fn($e) => $labels[$e] ?? $e, $entities), $entity) ?></select>
        <input name="entity_id" class="form-control" placeholder="Id" value="<?= h($entityId) ?>" style="width:90px">
        <button class="btn btn-primary">Filtrar</button>
    </form>
</div>
<div class="sk-card">
    <table class="table table-condensed">
        <thead><tr><th>Cuándo</th><th>Quién</th><th>Qué</th><th>Acción</th><th>Cambios</th></tr></thead>
        <?php foreach ($rows as $r): ?>
            <tr><td style="white-space:nowrap"><?= fdate($r['created_at']) ?></td>
                <td><?= h($r['worker_name'] ?: ($r['actor'] === 'stripe' ? 'Stripe' : ($r['actor'] === 'system' ? 'Sistema (tarea diaria)' : $r['actor']))) ?></td>
                <td><?= h($labels[$r['entity']] ?? $r['entity']) ?> <?= $r['entity_id'] !== null ? '#' . (int) $r['entity_id'] : '' ?></td>
                <td><?= h($r['action']) ?></td>
                <td class="small"><?= $diff($r['before_json'], $r['after_json']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="sk-muted">Sin cambios registrados.</td></tr><?php endif; ?>
    </table>
</div>
<?php sk_footer();

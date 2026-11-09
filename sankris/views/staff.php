<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('staff.edit') || deny();

on_post('save_roles', function (array $in) use ($app, $auth) {
    foreach ((array) ($in['role'] ?? []) as $workerId => $role) {
        $workerId = (int) $workerId;
        $trainer = ($in['trainer'][$workerId] ?? '') === '' ? null : (int) $in['trainer'][$workerId];
        $before = $app->db->one('SELECT role, trainer_id FROM sk_staff_roles WHERE worker_id = ?', [$workerId]);
        if ($role === '') {
            if ($before) {
                $app->db->run('DELETE FROM sk_staff_roles WHERE worker_id = ?', [$workerId]);
                $app->audit()->log('staff_role', $workerId, 'delete', $before, null);
            }
            continue;
        }
        if (!isset(\Sankris\Auth::ROLES[$role])) {
            continue;
        }
        if ($workerId === $auth->id() && $role !== 'admin') {
            throw new \Sankris\ValidationException('No puedes quitarte el rol de administración a ti mismo.');
        }
        if (!$before || $before['role'] !== $role || (string) $before['trainer_id'] !== (string) $trainer) {
            $app->db->run('INSERT INTO sk_staff_roles (worker_id, role, trainer_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role), trainer_id = VALUES(trainer_id)', [$workerId, $role, $trainer]);
            $app->audit()->log('staff_role', $workerId, 'update', $before, ['role' => $role, 'trainer_id' => $trainer]);
        }
    }
    flash('success', 'Roles guardados.');
    redirect('staff');
});

$workers = $app->db->all('SELECT w.userid, w.firstname, w.lastname, w.username, w.is_boss, r.role, r.trainer_id FROM workers w LEFT JOIN sk_staff_roles r ON r.worker_id = w.userid ORDER BY w.firstname');
$trainers = ['' => '—'] + array_column($app->db->all('SELECT id, name FROM trainers ORDER BY name'), 'name', 'id');
$matrix = [
    'Ver familias y alumnos' => 'families.view', 'Editar familias y alumnos' => 'families.edit', 'Ver notas médicas' => 'students.medical',
    'Suscripciones (pausar, cancelar)' => 'subscriptions.edit', 'Precios y descuentos' => 'subscriptions.price', 'Planes' => 'plans.edit',
    'Reservas' => 'bookings.edit', 'Asistencia' => 'attendance.mark', 'Cancelar clases y reglas' => 'schedule.edit',
    'Ver facturas y pagos' => 'billing.view', 'Registrar pagos y facturas' => 'billing.edit', 'Reembolsos y conciliación' => 'billing.refund',
    'Anular facturas' => 'billing.void', 'Reglas y Stripe' => 'settings.edit', 'Historial de cambios' => 'audit.view',
];
sk_header('Roles del personal', 'staff');
?>
<div class="row">
    <div class="col-md-6">
        <div class="sk-card">
            <h4>Personal</h4>
            <p class="small sk-muted">Las cuentas se crean en <a href="../boss/workers/">Empleados</a> de GYM One. Sin rol asignado, los jefes son Administración y el resto Recepción.</p>
            <?= form_open('save_roles') ?>
            <table class="table table-condensed">
                <thead><tr><th>Persona</th><th>Rol</th><th>Coach (ficha)</th></tr></thead>
                <?php foreach ($workers as $w): ?>
                    <tr><td><strong><?= h($w['firstname'] . ' ' . $w['lastname']) ?></strong><br><small class="sk-muted"><?= h($w['username']) ?></small></td>
                        <td><select name="role[<?= (int) $w['userid'] ?>]" class="form-control input-sm"><?= opt(['' => 'Automático (' . ((int) $w['is_boss'] ? 'Administración' : 'Recepción') . ')'] + \Sankris\Auth::ROLES, $w['role'] ?? '') ?></select></td>
                        <td><select name="trainer[<?= (int) $w['userid'] ?>]" class="form-control input-sm"><?= opt($trainers, $w['trainer_id'] ?? '') ?></select></td></tr>
                <?php endforeach; ?>
            </table>
            <button class="btn btn-primary btn-sm">Guardar roles</button>
            </form>
        </div>
    </div>
    <div class="col-md-6">
        <div class="sk-card">
            <h4>Qué puede hacer cada rol</h4>
            <table class="table table-condensed small">
                <thead><tr><th></th><?php foreach (\Sankris\Auth::ROLES as $label): ?><th class="text-center"><?= h($label) ?></th><?php endforeach; ?></tr></thead>
                <?php foreach ($matrix as $label => $perm): ?>
                    <tr><td><?= h($label) ?></td><?php foreach (array_keys(\Sankris\Auth::ROLES) as $r): ?><td class="text-center"><?= \Sankris\Auth::roleCan($r, $perm) ? '<i class="bi bi-check-lg text-success"></i>' : '' ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
<?php sk_footer();

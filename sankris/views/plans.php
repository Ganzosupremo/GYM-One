<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('plans.edit') || deny();

on_post('save_plan', function (array $in) use ($app) {
    $id = ($in['id'] ?? '') === '' ? null : (int) $in['id'];
    $data = array_intersect_key($in, array_flip(['name', 'kind', 'price', 'billing_period', 'credits', 'credit_period', 'validity_days', 'description']));
    foreach (['auto_renew', 'is_public', 'active'] as $k) {
        $data[$k] = empty($in[$k]) ? 0 : 1;
    }
    $app->subscriptions()->savePlan($id, $data, array_map('intval', (array) ($in['program_ids'] ?? [])));
    flash('success', $id ? 'Plan actualizado. Las suscripciones existentes conservan su precio y condiciones.' : 'Plan creado.');
    redirect('plans');
});

on_post('seed_plans', function () use ($app) {
    $n = \Sankris\Seed::plans2026($app->db);
    $app->audit()->log('plan', null, 'seed_2026', null, ['plans' => $n]);
    flash($n ? 'success' : 'info', $n ? "Se cargaron {$n} planes de la lista de precios 2026." : 'No se cargó nada: ya hay planes o faltan los programas.');
    redirect('plans');
});

$plans = $app->subscriptions()->plans();
$programs = $app->db->all('SELECT id, name FROM programs ORDER BY sort_order, name');
$programNames = array_column($programs, 'name', 'id');
$editId = (int) ($_GET['edit'] ?? 0);
$current = $editId ? $app->subscriptions()->plan($editId) : null;
$counts = [];
foreach ($app->db->all("SELECT plan_id, COUNT(*) AS n FROM sk_subscriptions WHERE status IN ('active','paused') GROUP BY plan_id") as $r) {
    $counts[(int) $r['plan_id']] = (int) $r['n'];
}
$v = $current ?? ['id' => '', 'name' => '', 'kind' => 'membership', 'price' => '', 'billing_period' => 'monthly', 'credits' => '', 'credit_period' => 'month', 'validity_days' => '', 'auto_renew' => 1, 'is_public' => 1, 'active' => 1, 'description' => '', 'program_ids' => []];
sk_header('Planes', 'plans');
?>
<div class="row">
    <div class="col-md-8">
        <div class="sk-card">
            <p class="sk-muted">El <strong>programa</strong> es la actividad (Baby Gym, Recreativo...). El <strong>plan</strong> es la oferta con precio y créditos. La <strong>suscripción</strong> guarda lo que contrató cada alumno.</p>
            <table class="table table-hover">
                <thead><tr><th>Plan</th><th>Tipo</th><th class="text-right">Precio</th><th>Créditos</th><th>Programas</th><th>Activas</th><th></th></tr></thead>
                <?php foreach ($plans as $p): ?>
                    <tr class="<?= (int) $p['active'] ? '' : 'text-muted' ?>">
                        <td><strong><?= h($p['name']) ?></strong><?= (int) $p['is_public'] ? '' : ' <span class="label label-default">Privado</span>' ?><?= (int) $p['active'] ? '' : ' <span class="label label-default">Inactivo</span>' ?></td>
                        <td><?= h(KIND_LABELS[$p['kind']] ?? $p['kind']) ?></td>
                        <td class="text-right"><?= money($p['price']) ?><br><small class="sk-muted"><?= h(PERIOD_LABELS[$p['billing_period']] ?? '') ?></small></td>
                        <td><?= $p['credits'] === null ? 'Ilimitados' : (int) $p['credits'] . ' ' . h(mb_strtolower(CREDIT_PERIOD_LABELS[$p['credit_period']] ?? '')) ?><?= $p['validity_days'] ? '<br><small class="sk-muted">vigencia ' . (int) $p['validity_days'] . ' días</small>' : '' ?></td>
                        <td><?php foreach ($p['program_ids'] as $pid): ?><span class="sk-chip"><?= h($programNames[$pid] ?? $pid) ?></span><?php endforeach; ?><?= $p['program_ids'] ? '' : '<span class="sk-muted">Todos</span>' ?></td>
                        <td><?= $counts[(int) $p['id']] ?? 0 ?></td>
                        <td><a class="btn btn-default btn-xs" href="<?= url('plans', ['edit' => $p['id']]) ?>">Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$plans): ?><tr><td colspan="7" class="sk-muted">Todavía no hay planes. Crea el primero a la derecha o
                    <?= form_open('seed_plans', 'class="sk-inline"') ?><button class="btn btn-default btn-xs">cargar la lista de precios 2026</button></form></td></tr><?php endif; ?>
            </table>
        </div>
    </div>
    <div class="col-md-4">
        <div class="sk-card">
            <h4><?= $current ? 'Editar plan' : 'Nuevo plan' ?></h4>
            <?= form_open('save_plan') ?>
            <input type="hidden" name="id" value="<?= h($v['id']) ?>">
            <div class="form-group"><label class="small">Nombre</label><input class="form-control input-sm" name="name" value="<?= h($v['name']) ?>" required></div>
            <div class="row">
                <div class="col-xs-6 form-group"><label class="small">Tipo</label><select class="form-control input-sm" name="kind"><?= opt(KIND_LABELS, $v['kind']) ?></select></div>
                <div class="col-xs-6 form-group"><label class="small">Precio</label><input type="number" step="0.01" min="0" class="form-control input-sm" name="price" value="<?= h($v['price']) ?>" required></div>
                <div class="col-xs-6 form-group"><label class="small">Cobro</label><select class="form-control input-sm" name="billing_period"><?= opt(PERIOD_LABELS, $v['billing_period']) ?></select></div>
                <div class="col-xs-6 form-group"><label class="small">Créditos (vacío = ilimitados)</label><input type="number" min="0" class="form-control input-sm" name="credits" value="<?= h($v['credits']) ?>"></div>
                <div class="col-xs-6 form-group"><label class="small">Los créditos se renuevan</label><select class="form-control input-sm" name="credit_period"><?= opt(CREDIT_PERIOD_LABELS, $v['credit_period']) ?></select></div>
                <div class="col-xs-6 form-group"><label class="small">Vigencia (días)</label><input type="number" min="1" class="form-control input-sm" name="validity_days" value="<?= h($v['validity_days']) ?>" placeholder="Sin límite"></div>
            </div>
            <div class="form-group"><label class="small">Programas permitidos (ninguno = todos)</label>
                <?php foreach ($programs as $pr): ?>
                    <div class="checkbox" style="margin:2px 0"><label><input type="checkbox" name="program_ids[]" value="<?= (int) $pr['id'] ?>" <?= in_array((int) $pr['id'], $v['program_ids'], true) ? 'checked' : '' ?>> <?= h($pr['name']) ?></label></div>
                <?php endforeach; ?>
            </div>
            <div class="form-group"><label class="small">Descripción</label><textarea class="form-control input-sm" name="description" rows="2"><?= h($v['description']) ?></textarea></div>
            <div class="checkbox"><label><input type="checkbox" name="auto_renew" value="1" <?= (int) $v['auto_renew'] ? 'checked' : '' ?>> Renovación automática</label></div>
            <div class="checkbox"><label><input type="checkbox" name="is_public" value="1" <?= (int) $v['is_public'] ? 'checked' : '' ?>> Público (aparece en la lista general)</label></div>
            <div class="checkbox"><label><input type="checkbox" name="active" value="1" <?= (int) $v['active'] ? 'checked' : '' ?>> Activo</label></div>
            <button class="btn btn-primary btn-sm">Guardar plan</button>
            <?php if ($current): ?><a class="btn btn-link btn-sm" href="<?= url('plans') ?>">Nuevo plan</a><?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php sk_footer();

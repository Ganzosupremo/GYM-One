<?php
/** @var \Sankris\App $app */
$auth = $app->auth();
$auth->can('families.view') || deny();

on_post('create_family', function (array $in) use ($app, $auth) {
    $auth->can('families.edit') || deny();
    $id = $app->db->transaction(function () use ($app, $in) {
        $fid = $app->families()->createFamily(['name' => $in['name'] ?? '', 'tags' => $in['tags'] ?? '', 'notes' => $in['notes'] ?? '']);
        if (trim($in['g_first_name'] ?? '') !== '') {
            $app->families()->addGuardian($fid, [
                'first_name' => $in['g_first_name'], 'last_name' => $in['g_last_name'] ?? '', 'relationship' => $in['g_relationship'] ?? '',
                'email' => $in['g_email'] ?? '', 'phone' => $in['g_phone'] ?? '', 'tax_id' => $in['g_tax_id'] ?? '', 'is_payer' => 1,
            ]);
        }
        return $fid;
    });
    flash('success', 'Familia creada.');
    redirect('family', ['id' => $id]);
});

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'active');
$rows = $app->families()->search($q, $status, 200);
sk_header('Familias y alumnos', 'families');
?>
<div class="sk-card">
    <form class="form-inline" method="get">
        <input type="hidden" name="p" value="families">
        <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Familia, responsable, alumno, correo o teléfono" style="min-width:320px">
        <select class="form-control" name="status"><?= opt(['active' => 'Activas', 'inactive' => 'Inactivas', '' => 'Todas'], $status) ?></select>
        <button class="btn btn-primary"><i class="bi bi-search"></i> Buscar</button>
        <?php if ($auth->can('families.edit')): ?>
            <button type="button" class="btn btn-success pull-right" data-toggle="collapse" data-target="#newFamily"><i class="bi bi-plus-lg"></i> Nueva familia</button>
        <?php endif; ?>
    </form>
    <div id="newFamily" class="collapse" style="margin-top:16px">
        <?= form_open('create_family') ?>
        <div class="row sk-form-row">
            <div class="col-sm-4 form-group"><label>Nombre de la familia *</label><input class="form-control" name="name" required placeholder="Familia López Pérez"></div>
            <div class="col-sm-4 form-group"><label>Etiquetas</label><input class="form-control" name="tags" placeholder="hermanos, beca"></div>
            <div class="col-sm-4 form-group"><label>Notas</label><input class="form-control" name="notes"></div>
        </div>
        <h5>Responsable que paga</h5>
        <div class="row sk-form-row">
            <div class="col-sm-2 form-group"><label>Nombre</label><input class="form-control" name="g_first_name"></div>
            <div class="col-sm-2 form-group"><label>Apellido</label><input class="form-control" name="g_last_name"></div>
            <div class="col-sm-2 form-group"><label>Parentesco</label><input class="form-control" name="g_relationship" placeholder="Madre"></div>
            <div class="col-sm-2 form-group"><label>Correo</label><input type="email" class="form-control" name="g_email"></div>
            <div class="col-sm-2 form-group"><label>Teléfono</label><input class="form-control" name="g_phone"></div>
            <div class="col-sm-2 form-group"><label>NIT</label><input class="form-control" name="g_tax_id" placeholder="CF"></div>
        </div>
        <button class="btn btn-success">Crear familia</button>
        </form>
    </div>
</div>
<div class="sk-card">
    <table class="table table-hover">
        <thead><tr><th>Familia</th><th>Responsable que paga</th><th>Alumnos</th><th>Etiquetas</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $f): ?>
            <tr>
                <td><a href="<?= url('family', ['id' => $f['id']]) ?>"><strong><?= h($f['name']) ?></strong></a></td>
                <td><?= h($f['payer_name'] ?? '—') ?></td>
                <td><?= (int) $f['students_count'] ?></td>
                <td><?php foreach (array_filter(array_map('trim', explode(',', (string) $f['tags']))) as $t): ?><span class="sk-chip"><?= h($t) ?></span><?php endforeach; ?></td>
                <td><?= status_label($f['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="sk-muted">No hay familias con ese filtro.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php sk_footer();

<?php
require_perm('settings');
$title = 'Concession Types';
if (is_post()) {
    try {
        $id = (int)post('id');
        if (post('act') === 'delete') {
            q('DELETE FROM concession_types WHERE id = ?', [$id]);
            flash('ok', 'Deleted. Concessions already given to students are kept.');
        } else {
            $d = ['name' => post('name'), 'kind' => post('kind') === 'fixed' ? 'fixed' : 'percent', 'value' => max(0, (float)post('value')),
                'fee_head_id' => nullif(post('fee_head_id')), 'active' => post('active') ? 1 : 0];
            if (!$d['name']) throw new RuntimeException('Enter a name.');
            if ($d['kind'] === 'percent' && $d['value'] > 100) throw new RuntimeException('Percentage cannot be more than 100.');
            if ($id) update('concession_types', $d, 'id = ?', [$id]); else $id = insert('concession_types', $d);
            audit('concession_type.save', 'concession_type', $id, $d);
            flash('ok', 'Saved.');
        }
    } catch (PDOException $e) { flash('err', 'That name already exists.');
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('settings/concession_types');
}
$heads = rows('SELECT id, name FROM fee_heads WHERE is_system = 0 ORDER BY sort_order');
$list = rows('SELECT ct.*, fh.name AS head_name FROM concession_types ct LEFT JOIN fee_heads fh ON fh.id = ct.fee_head_id ORDER BY ct.name');
$edit = get('edit') ? row('SELECT * FROM concession_types WHERE id = ?', [(int)get('edit')]) : null;
?>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body p-0"><table class="table mb-0 align-middle">
<thead><tr><th>Concession</th><th>Value</th><th>On fee head</th><th></th></tr></thead><tbody>
<?php foreach ($list as $c): ?>
<tr class="<?= $c['active'] ? '' : 'text-muted' ?>"><td><b><?= h($c['name']) ?></b></td>
<td><?= $c['kind'] === 'percent' ? h($c['value'] + 0) . '%' : money($c['value']) . ' per instalment' ?></td><td><?= h($c['head_name'] ?? 'All fee heads') ?></td>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="<?= url('settings/concession_types', ['edit' => $c['id']]) ?>"><i class="bi bi-pencil"></i></a>
<form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete?"><i class="bi bi-trash"></i></button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<p class="small text-muted mt-2">These are templates. Give a concession to a student from their profile; you can change the value for that student. RTE students are fully exempt automatically (see Due Date &amp; Late Fee settings).</p></div>
<div class="col-lg-5"><form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit' : 'Add' ?> concession type</div><div class="card-body">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
<div class="mb-2"><label class="form-label req">Name</label><input name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="Sibling Concession"></div>
<div class="row g-2 mb-2"><div class="col"><label class="form-label">Type</label><select name="kind" class="form-select"><option value="percent">Percentage</option><option value="fixed" <?= ($edit['kind'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed ₹ per instalment</option></select></div>
<div class="col"><label class="form-label">Value</label><input name="value" type="number" step="0.01" min="0" class="form-control" value="<?= h($edit['value'] ?? '') ?>"></div></div>
<div class="mb-2"><label class="form-label">Applies to fee head</label><select name="fee_head_id" class="form-select"><?= select_options($heads, $edit['fee_head_id'] ?? '', 'id', 'name', 'All fee heads') ?></select></div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active" value="1" id="ac" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>><label for="ac" class="form-check-label">Active</label></div>
<button class="btn btn-primary">Save</button>
</div></form></div>
</div>

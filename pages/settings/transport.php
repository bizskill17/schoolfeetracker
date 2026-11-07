<?php
require_perm('settings');
$title = 'Transport Routes';
$th = row('SELECT * FROM fee_heads WHERE is_transport = 1 AND active = 1 ORDER BY sort_order LIMIT 1');
if (is_post()) {
    try {
        $id = (int)post('id');
        if (post('act') === 'delete') {
            if (val('SELECT COUNT(*) FROM enrollments WHERE transport_route_id = ?', [$id])) throw new RuntimeException('Students use this route. Mark it inactive instead.');
            q('DELETE FROM transport_routes WHERE id = ?', [$id]);
            flash('ok', 'Route deleted.');
        } else {
            $d = ['name' => post('name'), 'stops' => post('stops'), 'amount' => max(0, (float)post('amount')), 'active' => post('active') ? 1 : 0];
            if (!$d['name']) throw new RuntimeException('Enter a route name.');
            if ($id) update('transport_routes', $d, 'id = ?', [$id]); else $id = insert('transport_routes', $d);
            audit('route.save', 'route', $id, $d);
            flash('ok', 'Route saved. If the fee changed, use Fees → Update Fee Schedules.');
        }
    } catch (PDOException $e) { flash('err', 'A route with that name already exists.');
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('settings/transport');
}
$list = rows("SELECT r.*, (SELECT COUNT(*) FROM enrollments e WHERE e.transport_route_id = r.id AND e.status = 'active') AS students FROM transport_routes r ORDER BY name");
$edit = get('edit') ? row('SELECT * FROM transport_routes WHERE id = ?', [(int)get('edit')]) : null;
$per = $th ? 'per instalment – ' . strtolower(frequency((int)$th['frequency_id'])['name'] ?? 'monthly') : 'per month';
?>
<?php if (!$th): ?><div class="alert alert-warning">No active fee head is marked as Transport. Create one in <a href="<?= url('settings/fee_heads') ?>">Fee Heads</a> so route fees are charged.</div><?php endif; ?>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="card-body p-0"><table class="table mb-0 align-middle">
<thead><tr><th>Route</th><th>Stops / area</th><th class="text-end">Fee (<?= h($per) ?>)</th><th>Students</th><th></th></tr></thead><tbody>
<?php foreach ($list as $r): ?>
<tr class="<?= $r['active'] ? '' : 'text-muted' ?>"><td><b><?= h($r['name']) ?></b></td><td class="small"><?= h($r['stops']) ?></td><td class="amount"><?= money($r['amount']) ?></td><td><?= (int)$r['students'] ?></td>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="<?= url('settings/transport', ['edit' => $r['id']]) ?>"><i class="bi bi-pencil"></i></a>
<form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete route?"><i class="bi bi-trash"></i></button></form></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="5" class="text-muted text-center py-4">No routes yet.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<div class="col-lg-4"><form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit route' : 'Add route' ?></div><div class="card-body">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
<div class="mb-2"><label class="form-label req">Route name</label><input name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="Route 1 – Kothrud"></div>
<div class="mb-2"><label class="form-label">Stops / area</label><textarea name="stops" class="form-control" rows="2"><?= h($edit['stops'] ?? '') ?></textarea></div>
<div class="mb-2"><label class="form-label req">Fee per instalment (<?= h($per) ?>)</label><input name="amount" type="number" step="0.01" min="0" class="form-control" required value="<?= h($edit['amount'] ?? '') ?>"></div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active" value="1" id="ac" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>><label for="ac" class="form-check-label">Active</label></div>
<button class="btn btn-primary">Save</button> <?php if ($edit): ?><a href="<?= url('settings/transport') ?>" class="btn btn-light">Cancel</a><?php endif; ?>
</div></form></div>
</div>

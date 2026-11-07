<?php
require_perm('settings');
$title = 'Fee Frequencies';
$year = working_year();
$startMonth = $year ? (int)date('n', strtotime($year['start_date'])) : 4;
$monthOrder = array_map(fn($i) => (($startMonth - 1 + $i) % 12) + 1, range(0, 11));
if (is_post()) {
    try {
        $id = (int)post('id');
        if (post('act') === 'delete') {
            if (val('SELECT COUNT(*) FROM fee_heads WHERE frequency_id = ?', [$id])) throw new RuntimeException('Fee heads use this frequency. Change them first, or mark it inactive.');
            q('DELETE FROM fee_frequencies WHERE id = ?', [$id]);
            audit('frequency.delete', 'frequency', $id);
            flash('ok', 'Deleted.');
        } else {
            $once = post('is_one_time') ? 1 : 0;
            $months = array_values(array_intersect($monthOrder, array_map('intval', (array)($_POST['months'] ?? []))));
            if (!post('name')) throw new RuntimeException('Enter a name.');
            if (!$once && !$months) throw new RuntimeException('Tick at least one month in which this fee is charged.');
            $d = ['name' => post('name'), 'months' => $once ? null : implode(',', $months), 'is_one_time' => $once, 'sort_order' => (int)post('sort_order'), 'active' => post('active') ? 1 : 0];
            $old = $id ? row('SELECT * FROM fee_frequencies WHERE id = ?', [$id]) : null;
            if ($id) update('fee_frequencies', $d, 'id = ?', [$id]); else $id = insert('fee_frequencies', $d);
            audit('frequency.save', 'frequency', $id, $d);
            $changed = $old && ($old['months'] !== $d['months'] || (int)$old['is_one_time'] !== $once);
            flash('ok', 'Saved.' . ($changed ? ' Months changed: run Fees → Update Fee Schedules so unpaid instalments follow the new months.' : ''));
        }
    } catch (PDOException $e) { flash('err', 'A frequency with that name already exists.');
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('masters/frequencies');
}
$list = rows('SELECT f.*, (SELECT GROUP_CONCAT(name ORDER BY sort_order SEPARATOR ", ") FROM fee_heads h WHERE h.frequency_id = f.id) AS heads FROM fee_frequencies f ORDER BY sort_order, name');
$edit = get('edit') ? row('SELECT * FROM fee_frequencies WHERE id = ?', [(int)get('edit')]) : null;
$em = $edit ? freq_months($edit) : [];
?>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="table-responsive"><table class="table mb-0 align-middle">
<thead><tr><th>Frequency</th><th>Charged in</th><th>Used by</th><th></th></tr></thead><tbody>
<?php foreach ($list as $f): ?>
<tr class="<?= $f['active'] ? '' : 'text-muted' ?>"><td><b><?= h($f['name']) ?></b> <?= $f['active'] ? '' : '<span class="badge text-bg-secondary">Inactive</span>' ?><div class="small text-muted"><?= freq_count($f) ?> instalment<?= freq_count($f) > 1 ? 's' : '' ?> a year</div></td>
<td class="small"><?= h(freq_describe($f)) ?></td><td class="small"><?= h($f['heads'] ?: '—') ?></td>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="<?= url('masters/frequencies', ['edit' => $f['id']]) ?>"><i class="bi bi-pencil"></i></a>
<?php if (!$f['heads']): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete <?= h($f['name']) ?>?"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<p class="small text-muted mt-2">Each instalment covers the months from its month until the next one. Example: a "3 Terms" frequency charged in Apr, Aug and Dec makes three instalments: Apr–Jul, Aug–Nov, Dec–Mar. A student joining mid-year pays from the instalment running when they join. Choose the frequency for each fee in <a href="<?= url('settings/fee_heads') ?>">Fee Heads</a>.</p></div>
<div class="col-lg-5"><form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit frequency' : 'Add frequency' ?></div><div class="card-body">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
<div class="mb-2"><label class="form-label req">Name</label><input name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. 3 Terms, Bi-monthly"></div>
<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_one_time" value="1" id="once" <?= !empty($edit['is_one_time']) ? 'checked' : '' ?>><label class="form-check-label" for="once">One time only (charged once, when the student joins)</label></div>
<div id="monthBox"><label class="form-label">Charged in these months</label>
<div class="d-flex flex-wrap gap-1 mb-1"><?php foreach ($monthOrder as $m): ?>
  <input type="checkbox" class="btn-check" name="months[]" value="<?= $m ?>" id="m<?= $m ?>" <?= in_array($m, $em, true) ? 'checked' : '' ?>><label class="btn btn-sm btn-outline-primary" style="width:56px" for="m<?= $m ?>"><?= MONTH_NAMES[$m] ?></label>
<?php endforeach; ?></div>
<div class="d-flex flex-wrap gap-1 mb-2 small"><span class="text-muted">Quick:</span>
<?php foreach (['Every month' => 1, 'Every 2 months' => 2, 'Every 3 months' => 3, 'Every 4 months' => 4, 'Every 6 months' => 6, 'Once a year' => 12] as $l => $n): ?><a href="#" class="qm" data-n="<?= $n ?>"><?= $l ?></a> ·<?php endforeach; ?></div></div>
<div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Display order</label><input type="number" name="sort_order" class="form-control" value="<?= h($edit['sort_order'] ?? 60) ?>"></div>
<div class="col-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" id="ac" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>><label for="ac" class="form-check-label">Active</label></div></div></div>
<button class="btn btn-primary">Save</button> <?php if ($edit): ?><a href="<?= url('masters/frequencies') ?>" class="btn btn-light">Cancel</a><?php endif; ?>
</div></form></div>
</div>
<script>
(function () {
  const once = document.getElementById('once'), box = document.getElementById('monthBox');
  const t = () => box.style.display = once.checked ? 'none' : ''; once.addEventListener('change', t); t();
  const order = <?= json_encode($monthOrder) ?>;
  document.querySelectorAll('.qm').forEach(a => a.addEventListener('click', e => { e.preventDefault(); const n = +a.dataset.n;
    order.forEach((m, i) => document.getElementById('m' + m).checked = i % n === 0); }));
})();
</script>

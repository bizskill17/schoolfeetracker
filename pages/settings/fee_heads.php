<?php
require_perm('settings');
$title = 'Fee Heads';
if (is_post()) {
    try {
        $id = (int)post('id');
        if ($id && val('SELECT is_system FROM fee_heads WHERE id = ?', [$id])) throw new RuntimeException('System fee heads cannot be edited.');
        if (post('act') === 'delete') {
            if (val('SELECT COUNT(*) FROM fee_demands WHERE fee_head_id = ?', [$id])) throw new RuntimeException('This fee head is already charged to students. Mark it inactive instead.');
            q('DELETE FROM fee_heads WHERE id = ?', [$id]);
            audit('fee_head.delete', 'fee_head', $id);
            flash('ok', 'Fee head deleted.');
        } else {
            $d = ['name' => post('name'), 'frequency_id' => (int)post('frequency_id'), 'late_fee_rule_id' => nullif(post('late_fee_rule_id')), 'applies_to' => post('applies_to'),
                'is_transport' => post('is_transport') ? 1 : 0, 'is_optional' => post('is_optional') ? 1 : 0, 'refundable' => post('refundable') ? 1 : 0,
                'late_fee_applies' => post('late_fee_applies') ? 1 : 0, 'sort_order' => (int)post('sort_order'), 'active' => post('active') ? 1 : 0];
            if (!$d['name']) throw new RuntimeException('Enter a name.');
            if (!frequency($d['frequency_id'])) throw new RuntimeException('Choose how often it is charged.');
            if (!in_array($d['applies_to'], ['all', 'new', 'old'], true)) $d['applies_to'] = 'all';
            if ($id) { update('fee_heads', $d, 'id = ?', [$id]); audit('fee_head.update', 'fee_head', $id, $d); }
            else { $id = insert('fee_heads', $d); audit('fee_head.create', 'fee_head', $id, $d); }
            flash('ok', 'Fee head saved. If amounts or frequency changed, run Fees → Update Fee Schedules.');
        }
    } catch (PDOException $e) {
        flash('err', strpos($e->getMessage(), 'Duplicate') !== false ? 'A fee head with that name exists.' : 'Could not save.');
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('settings/fee_heads');
}
$list = rows('SELECT fh.*, ff.name AS freq_name, lr.name AS rule_name FROM fee_heads fh LEFT JOIN fee_frequencies ff ON ff.id = fh.frequency_id
               LEFT JOIN late_fee_rules lr ON lr.id = fh.late_fee_rule_id ORDER BY fh.is_system, fh.sort_order, fh.name');
$rules = rows('SELECT id, name FROM late_fee_rules WHERE active = 1 ORDER BY name');
$defRule = (int)setting('late_fee_rule_id', '0');
$defRuleName = $defRule ? (string)val('SELECT name FROM late_fee_rules WHERE id = ?', [$defRule]) : 'no late fee';
$edit = get('edit') ? row('SELECT * FROM fee_heads WHERE id = ? AND is_system = 0', [(int)get('edit')]) : null;
$e = $edit ?? ['frequency_id' => (int)val("SELECT id FROM fee_frequencies WHERE name = 'Monthly'"), 'applies_to' => 'all', 'late_fee_applies' => 1, 'active' => 1, 'sort_order' => count($list) + 1];
$applies = ['all' => 'All students', 'new' => 'New admissions only', 'old' => 'Existing students only'];
?>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 align-middle">
<thead><tr><th>Fee head</th><th>Charged</th><th>Applies to</th><th>Flags</th><th></th></tr></thead><tbody>
<?php foreach ($list as $f): ?>
<tr class="<?= $f['active'] ? '' : 'text-muted' ?>"><td><b><?= h($f['name']) ?></b> <?= $f['active'] ? '' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
<td><?= h($f['freq_name'] ?? '—') ?></td><td class="small"><?= h($applies[$f['applies_to']]) ?></td>
<td class="small">
  <?= $f['is_system'] ? '<span class="badge text-bg-dark">System</span>' : '' ?>
  <?= $f['is_transport'] ? '<span class="badge text-bg-info">Transport (amount from route)</span>' : '' ?>
  <?= $f['is_optional'] ? '<span class="badge text-bg-warning">Optional – added per student</span>' : '' ?>
  <?= $f['refundable'] ? '<span class="badge text-bg-light border">Refundable</span>' : '' ?>
  <?= $f['late_fee_applies'] ? '<span class="badge text-bg-light border">Late fee: ' . h($f['rule_name'] ?? 'default') . '</span>' : '' ?>
</td>
<td class="text-end text-nowrap"><?php if (!$f['is_system']): ?>
  <a class="btn btn-sm btn-light" href="<?= url('settings/fee_heads', ['edit' => $f['id']]) ?>"><i class="bi bi-pencil"></i></a>
  <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete fee head <?= h($f['name']) ?>?"><i class="bi bi-trash"></i></button></form>
<?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div></div>
<div class="small text-muted mt-2">Amounts per class are set in <a href="<?= url('settings/fee_structure') ?>">Fee Structure</a>. Transport amounts come from <a href="<?= url('settings/transport') ?>">Transport Routes</a>. Optional heads (books, uniform, trips) are added to individual students from their profile.</div>
</div>
<div class="col-lg-4"><form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit fee head' : 'Add fee head' ?></div><div class="card-body">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
<div class="mb-2"><label class="form-label req">Name</label><input name="name" class="form-control" required value="<?= h($e['name'] ?? '') ?>" placeholder="Computer Fee"></div>
<div class="mb-2"><label class="form-label">How often is it charged?</label><select name="frequency_id" class="form-select"><?php foreach (frequencies(true) as $fq): ?><option value="<?= $fq['id'] ?>" <?= (int)$e['frequency_id'] === (int)$fq['id'] ? 'selected' : '' ?>><?= h($fq['name']) ?> – <?= h(freq_describe($fq)) ?></option><?php endforeach; ?></select>
<div class="form-text">Enter the amount per instalment in Fee Structure. Add your own frequencies (e.g. 3 terms) in <a href="<?= url('masters/frequencies') ?>">Fee Frequencies</a>. Changing it mid-year applies to students who have not paid this fee yet; students who already paid part of it keep their schedule until next year.</div></div>
<div class="mb-2"><label class="form-label">Late fee rule</label><select name="late_fee_rule_id" class="form-select"><option value="">Default rule (<?= h($defRuleName) ?>)</option><?php foreach ($rules as $rl): ?><option value="<?= $rl['id'] ?>" <?= (string)($e['late_fee_rule_id'] ?? '') === (string)$rl['id'] ? 'selected' : '' ?>><?= h($rl['name']) ?></option><?php endforeach; ?></select>
<div class="form-text">Used when "Late fee applies" is ticked below. Rules are in <a href="<?= url('masters/late_rules') ?>">Late Fee Rules</a>.</div></div>
<div class="mb-2"><label class="form-label">Applies to</label><select name="applies_to" class="form-select"><?php foreach ($applies as $k => $l): ?><option value="<?= $k ?>" <?= $e['applies_to'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
<div class="mb-2"><label class="form-label">Display order</label><input type="number" name="sort_order" class="form-control" value="<?= h($e['sort_order']) ?>"></div>
<?php foreach (['is_transport' => 'Transport fee (amount comes from student\'s route)', 'is_optional' => 'Optional (not charged automatically)', 'refundable' => 'Refundable (e.g. caution deposit)', 'late_fee_applies' => 'Late fee applies if paid late', 'active' => 'Active'] as $k => $l): ?>
<div class="form-check"><input class="form-check-input" type="checkbox" name="<?= $k ?>" value="1" id="<?= $k ?>" <?= !empty($e[$k]) ? 'checked' : '' ?>><label class="form-check-label" for="<?= $k ?>"><?= h($l) ?></label></div>
<?php endforeach; ?>
<button class="btn btn-primary mt-3">Save</button> <?php if ($edit): ?><a href="<?= url('settings/fee_heads') ?>" class="btn btn-light mt-3">Cancel</a><?php endif; ?>
</div></form></div>
</div>

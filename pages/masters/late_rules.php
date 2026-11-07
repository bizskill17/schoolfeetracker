<?php
require_perm('settings');
$title = 'Late Fee Rules';
$calcLabels = ['once' => 'Fixed amount', 'per_day' => '× every day late', 'per_month' => '× every month late'];
if (is_post()) {
    $pdo = db();
    try {
        $id = (int)post('id');
        if (post('act') === 'delete') {
            if (val('SELECT COUNT(*) FROM fee_heads WHERE late_fee_rule_id = ?', [$id])) throw new RuntimeException('Fee heads use this rule. Change them first, or mark it inactive.');
            if ((int)setting('late_fee_rule_id', '0') === $id) throw new RuntimeException('This is the default rule. Choose another default first.');
            q('DELETE FROM late_fee_rules WHERE id = ?', [$id]);
            audit('late_rule.delete', 'late_rule', $id);
            flash('ok', 'Rule deleted.');
        } elseif (post('act') === 'default') {
            set_setting('late_fee_rule_id', $id);
            audit('late_rule.default', 'late_rule', $id);
            flash('ok', $id ? 'Default rule changed.' : 'Late fee turned off for fee heads that use the default rule.');
        } else {
            if (!post('name')) throw new RuntimeException('Enter a name for the rule.');
            $slabs = [];
            foreach ((array)($_POST['slab'] ?? []) as $sl) {
                if (($sl['amount'] ?? '') === '' && ($sl['from'] ?? '') === '') continue;
                $from = max(1, (int)($sl['from'] ?? 1)); $to = ($sl['to'] ?? '') === '' ? null : (int)$sl['to'];
                if ($to !== null && $to < $from) throw new RuntimeException("A slab ends before it starts ($from to $to days).");
                $calc = isset($calcLabels[$sl['calc'] ?? '']) ? $sl['calc'] : 'once';
                $slabs[] = ['from_day' => $from, 'to_day' => $to, 'amount' => max(0, (float)($sl['amount'] ?? 0)), 'calc' => $calc];
            }
            if (!$slabs) throw new RuntimeException('Add at least one slab (e.g. from day 1, amount 100).');
            usort($slabs, fn($a, $b) => $a['from_day'] <=> $b['from_day']);
            for ($i = 1; $i < count($slabs); $i++) {
                $prevTo = $slabs[$i - 1]['to_day'];
                if ($prevTo === null || $slabs[$i]['from_day'] <= $prevTo) throw new RuntimeException('Slabs overlap. Each slab must start after the previous one ends.');
            }
            $pdo->beginTransaction();
            $d = ['name' => post('name'), 'grace_days' => max(0, (int)post('grace_days')), 'max_amount' => max(0, (float)post('max_amount')), 'active' => post('active') ? 1 : 0];
            if ($id) update('late_fee_rules', $d, 'id = ?', [$id]); else $id = insert('late_fee_rules', $d);
            q('DELETE FROM late_fee_slabs WHERE rule_id = ?', [$id]);
            foreach ($slabs as $sl) insert('late_fee_slabs', $sl + ['rule_id' => $id]);
            $pdo->commit();
            audit('late_rule.save', 'late_rule', $id, $d + ['slabs' => $slabs]);
            flash('ok', 'Rule saved. Late fees are worked out with it from now on.');
        }
    } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('err', 'A rule with that name already exists.');
    } catch (RuntimeException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('err', $e->getMessage()); }
    redirect('masters/late_rules', post('act') === 'save' || !post('act') ? ['edit' => $id] : []);
}
$rules = late_rules();
$default = (int)setting('late_fee_rule_id', '0');
$usedBy = [];
foreach (rows('SELECT name, late_fee_rule_id FROM fee_heads WHERE late_fee_applies = 1 AND active = 1') as $h) $usedBy[(int)($h['late_fee_rule_id'] ?: $default)][] = $h['name'];
$edit = get('edit') !== '' && isset($rules[(int)get('edit')]) ? $rules[(int)get('edit')] : null;
$slabs = $edit ? $edit['slabs'] : [['from_day' => 1, 'to_day' => null, 'amount' => '', 'calc' => 'once']];
while (count($slabs) < 4) $slabs[] = ['from_day' => '', 'to_day' => null, 'amount' => '', 'calc' => 'once'];
$describe = function (array $r) use ($calcLabels): string {
    $p = [];
    foreach ($r['slabs'] as $sl) {
        $range = $sl['to_day'] === null ? 'from day ' . $sl['from_day'] : 'day ' . $sl['from_day'] . '–' . $sl['to_day'];
        $p[] = $range . ': ' . money($sl['amount']) . ($sl['calc'] === 'once' ? '' : ' ' . $calcLabels[$sl['calc']]);
    }
    return implode('; ', $p) . ($r['grace_days'] ? ' · ' . $r['grace_days'] . ' grace days' : '') . ((float)$r['max_amount'] > 0 ? ' · max ' . money($r['max_amount']) : '');
};
$asOf = get('try_days') !== '' ? (int)get('try_days') : null;
?>
<div class="row g-3">
<div class="col-lg-6">
  <div class="card mb-2"><div class="list-group list-group-flush">
  <?php foreach ($rules as $r): ?>
    <div class="list-group-item <?= $r['active'] ? '' : 'text-muted' ?>">
      <div class="d-flex justify-content-between gap-2"><a href="<?= url('masters/late_rules', ['edit' => $r['id']]) ?>" class="fw-semibold text-decoration-none"><?= h($r['name']) ?></a>
        <?= $default === (int)$r['id'] ? '<span class="badge text-bg-success">Default</span>' : '' ?></div>
      <div class="small"><?= h($describe($r)) ?></div>
      <div class="small text-muted">Used by: <?= h(implode(', ', $usedBy[(int)$r['id']] ?? ['—'])) ?></div>
      <?php if ($default !== (int)$r['id'] && $r['active']): ?><form method="post" class="mt-1"><?= csrf_field() ?><input type="hidden" name="act" value="default"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-link p-0">Make default</button></form><?php endif; ?>
    </div>
  <?php endforeach; ?>
    <div class="list-group-item small"><?php if ($default): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="default"><input type="hidden" name="id" value="0"><button class="btn btn-sm btn-link p-0 text-danger" data-confirm="Turn off late fee for all fee heads using the default rule?">Turn off default late fee</button></form><?php else: ?><span class="text-danger">No default rule – only fee heads with their own rule charge late fee.</span><?php endif; ?></div>
  </div></div>
  <a href="<?= url('masters/late_rules') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> New rule</a>
  <p class="small text-muted mt-2">The default rule applies to every fee head with "Late fee applies" ticked, unless the fee head has its own rule (<a href="<?= url('settings/fee_heads') ?>">Fee Heads</a>). Whether late fee is charged once per due date or on every fee item is set in <a href="<?= url('settings/fee_rules') ?>">Due Date &amp; Late Fee</a>.</p>
</div>
<div class="col-lg-6">
<form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit rule' : 'New rule' ?></div><div class="card-body">
<?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
<div class="mb-2"><label class="form-label req">Rule name</label><input name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. Tuition late fee"></div>
<div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Grace days after due date</label><input type="number" min="0" name="grace_days" class="form-control" value="<?= h($edit['grace_days'] ?? 0) ?>"></div>
<div class="col-6"><label class="form-label">Maximum per due date (₹)</label><input type="number" min="0" step="0.01" name="max_amount" class="form-control" value="<?= h(isset($edit['max_amount']) ? $edit['max_amount'] + 0 : 0) ?>"><div class="form-text">0 = no limit</div></div></div>
<label class="form-label">Slabs <span class="fw-normal text-muted">(days late counted after the grace days)</span></label>
<div class="table-responsive"><table class="table table-sm align-middle mb-1"><thead><tr><th>From day</th><th>To day</th><th>Amount ₹</th><th>How</th></tr></thead><tbody>
<?php foreach ($slabs as $i => $sl): ?>
<tr><td><input type="number" min="1" name="slab[<?= $i ?>][from]" class="form-control form-control-sm" value="<?= h($sl['from_day']) ?>" style="width:75px"></td>
<td><input type="number" min="1" name="slab[<?= $i ?>][to]" class="form-control form-control-sm" value="<?= h($sl['to_day']) ?>" placeholder="no end" style="width:85px"></td>
<td><input type="number" min="0" step="0.01" name="slab[<?= $i ?>][amount]" class="form-control form-control-sm" value="<?= $sl['amount'] === '' ? '' : h($sl['amount'] + 0) ?>" style="width:90px"></td>
<td><select name="slab[<?= $i ?>][calc]" class="form-select form-select-sm"><?php foreach ($calcLabels as $k => $l): ?><option value="<?= $k ?>" <?= $sl['calc'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="small text-muted mb-3">The slab that contains the number of days late decides the fee. Examples:
<ul class="mb-0"><li>₹10 a day: from 1, no end, 10, × every day late</li><li>₹100 a month: from 1, no end, 100, × every month late</li><li>₹50 up to 15 days, then ₹150: (1–15, 50, fixed) and (16, no end, 150, fixed)</li></ul></div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active" value="1" id="ac" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>><label for="ac" class="form-check-label">Active</label></div>
<button class="btn btn-primary">Save rule</button>
</div></form>
<?php if ($edit): ?>
<div class="card mt-3"><div class="card-body small">
  <b>What this rule charges</b>
  <table class="table table-sm mt-2 mb-0"><tbody>
  <?php foreach ([1, 5, 10, 15, 20, 30, 45, 60, 90, 180] as $dl): $due = date('Y-m-d', strtotime('-' . ($dl + (int)$edit['grace_days']) . ' days')); ?>
    <tr><td><?= $dl ?> day<?= $dl > 1 ? 's' : '' ?> late</td><td class="amount"><?= money(late_fee_gross($due, today(), (int)$edit['id'])) ?></td></tr>
  <?php endforeach; ?></tbody></table>
</div></div>
<form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $edit['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this rule?">Delete rule</button></form>
<?php endif; ?>
</div>
</div>

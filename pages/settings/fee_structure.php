<?php
require_perm('settings');
$title = 'Fee Structure';
$year = working_year();
if (!$year) { echo '<div class="alert alert-warning">Add an academic year first.</div>'; return; }
$classes = rows('SELECT * FROM classes ORDER BY sort_order, name');
$heads = rows('SELECT * FROM fee_heads WHERE is_system = 0 AND is_transport = 0 AND active = 1 ORDER BY sort_order, name');

if (is_post()) {
    if (post('act') === 'copy') {
        $from = (int)post('from_year');
        $pct = (float)post('increase');
        $round = (int)post('round_to') ?: 1;
        $n = 0;
        foreach (rows('SELECT * FROM fee_structure WHERE academic_year_id = ?', [$from]) as $r) {
            $amt = round(((float)$r['amount'] * (1 + $pct / 100)) / $round) * $round;
            q('INSERT INTO fee_structure (academic_year_id, class_id, fee_head_id, amount) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)',
                [$year['id'], $r['class_id'], $r['fee_head_id'], $amt]);
            $n++;
        }
        audit('fee_structure.copy', 'year', (int)$year['id'], ['from' => $from, 'pct' => $pct]);
        flash('ok', "Copied $n amounts" . ($pct ? " with {$pct}% increase" : '') . '. Review and save.');
    } else {
        $amt = $_POST['amt'] ?? [];
        foreach ($classes as $c) foreach ($heads as $hd) {
            $v = $amt[$c['id']][$hd['id']] ?? '';
            $v = $v === '' ? 0 : max(0, (float)$v);
            q('INSERT INTO fee_structure (academic_year_id, class_id, fee_head_id, amount) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)',
                [$year['id'], $c['id'], $hd['id'], $v]);
        }
        audit('fee_structure.save', 'year', (int)$year['id']);
        if (post('apply')) {
            $n = 0;
            foreach (rows("SELECT id FROM enrollments WHERE academic_year_id = ? AND status <> 'left'", [$year['id']]) as $en) $n += generate_demands((int)$en['id']);
            flash('ok', "Fee structure saved and student fee schedules updated ($n instalments changed). Instalments already paid were not touched.");
        } else {
            flash('ok', 'Fee structure saved. Tick "update students" or use Fees → Update Fee Schedules to apply it to students already admitted.');
        }
    }
    redirect('settings/fee_structure');
}
$cur = [];
foreach (rows('SELECT * FROM fee_structure WHERE academic_year_id = ?', [$year['id']]) as $r) $cur[$r['class_id']][$r['fee_head_id']] = (float)$r['amount'];
$otherYears = rows('SELECT * FROM academic_years WHERE id <> ? ORDER BY start_date DESC', [$year['id']]);
?>
<p class="text-muted">Fees for <b><?= h($year['name']) ?></b>. Enter the amount <b>per instalment</b> (a monthly fee = one month's amount). Leave blank or 0 if a fee does not apply to a class. Switch year from the top bar.</p>
<?php if (!$heads): ?><div class="alert alert-warning">Add fee heads first in <a href="<?= url('settings/fee_heads') ?>">Fee Heads</a>.</div><?php return; endif; ?>
<form method="post" class="card mb-3"><?= csrf_field() ?>
<div class="table-responsive"><table class="table table-sm mb-0 align-middle">
<thead><tr><th>Class</th><?php foreach ($heads as $hd): ?><th class="text-center"><?= h($hd['name']) ?><div class="fw-normal text-lowercase small"><?= h(frequency((int)$hd['frequency_id'])['name'] ?? '') ?><?= $hd['applies_to'] !== 'all' ? ' · ' . ($hd['applies_to'] === 'new' ? 'new only' : 'old only') : '' ?><?= $hd['is_optional'] ? ' · optional' : '' ?></div></th><?php endforeach; ?><th class="text-end">Year total*</th></tr></thead>
<tbody>
<?php foreach ($classes as $c): $tot = 0; ?>
<tr><td class="fw-semibold text-nowrap"><?= h($c['name']) ?></td>
<?php foreach ($heads as $hd): $a = $cur[$c['id']][$hd['id']] ?? 0; if ($hd['applies_to'] !== 'new' && !$hd['is_optional']) $tot += $a * freq_count(frequency((int)$hd['frequency_id'])); ?>
<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" style="min-width:90px" name="amt[<?= $c['id'] ?>][<?= $hd['id'] ?>]" value="<?= $a ? h($a + 0) : '' ?>"></td>
<?php endforeach; ?>
<td class="amount fw-semibold"><?= money($tot) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="card-body border-top d-flex flex-wrap gap-3 align-items-center">
  <button class="btn btn-primary">Save fee structure</button>
  <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="apply" value="1" checked> Also update fee schedules of students already admitted this year</label>
  <span class="small text-muted ms-auto">* For an existing student without transport. Excludes new-admission-only and optional fees.</span>
</div>
</form>
<?php if ($otherYears): ?>
<form method="post" class="card"><div class="card-header">Copy from another year</div><div class="card-body row g-2 align-items-end">
<?= csrf_field() ?><input type="hidden" name="act" value="copy">
<div class="col-md-3"><label class="form-label">From year</label><select name="from_year" class="form-select"><?= select_options($otherYears, '') ?></select></div>
<div class="col-md-3"><label class="form-label">Increase %</label><input name="increase" type="number" step="0.1" class="form-control" value="0"></div>
<div class="col-md-3"><label class="form-label">Round to nearest ₹</label><select name="round_to" class="form-select"><option>1</option><option>10</option><option>50</option><option>100</option></select></div>
<div class="col-md-3"><button class="btn btn-outline-primary w-100" data-confirm="This replaces amounts for <?= h($year['name']) ?>. Continue?">Copy</button></div>
</div></form>
<?php endif; ?>

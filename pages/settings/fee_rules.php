<?php
require_perm('settings');
$title = 'Due Date & Late Fee';
if (is_post()) {
    $vals = ['fee_due_day' => max(1, min(28, (int)post('fee_due_day'))), 'late_fee_rule_id' => (int)post('late_fee_rule_id'),
        'late_fee_scope' => post('late_fee_scope') === 'per_item' ? 'per_item' : 'per_date', 'rte_full_waiver' => post('rte_full_waiver') ? '1' : '0'];
    $oldDay = setting('fee_due_day', '10');
    foreach ($vals as $k => $v) set_setting($k, $v);
    audit('settings.fee_rules', 'settings', null, $vals);
    flash('ok', 'Saved.' . ((string)$oldDay !== (string)$vals['fee_due_day'] ? ' Due day changed: run Fees → Update Fee Schedules so unpaid instalments get the new due date.' : ''));
    redirect('settings/fee_rules');
}
$rules = rows('SELECT id, name FROM late_fee_rules WHERE active = 1 ORDER BY name');
?>
<form method="post" class="card" style="max-width:720px"><div class="card-body">
<?= csrf_field() ?>
<div class="form-section mt-0">Due date</div>
<div class="mb-3"><label class="form-label">Fees are due by day</label>
<div class="input-group" style="max-width:260px"><input type="number" name="fee_due_day" min="1" max="28" class="form-control" value="<?= h(setting('fee_due_day', '10')) ?>"><span class="input-group-text">of the month</span></div>
<div class="form-text">Each instalment is due on this day of its first month (e.g. April tuition due 10 April).</div></div>

<div class="form-section">Late fee</div>
<div class="mb-3"><label class="form-label">Default late fee rule</label>
<select name="late_fee_rule_id" class="form-select" style="max-width:520px"><option value="0">No late fee</option><?= select_options($rules, setting('late_fee_rule_id', '0')) ?></select>
<div class="form-text">Used by every fee head with "Late fee applies" ticked, unless the fee head has its own rule. Create and edit rules (slabs, per day, per month, grace days, maximum) in <a href="<?= url('masters/late_rules') ?>">Masters → Late Fee Rules</a>.</div></div>
<div class="mb-3"><label class="form-label">Charge late fee</label>
<select name="late_fee_scope" class="form-select" style="max-width:520px">
<option value="per_date">Once per due date (e.g. April tuition + transport = one late fee)</option>
<option value="per_item" <?= setting('late_fee_scope', 'per_date') === 'per_item' ? 'selected' : '' ?>>Separately on every fee item</option>
</select></div>
<div class="alert alert-light border small">Late fee is shown at the fee counter for each unpaid instalment. Users allowed to reduce late fee can lower it; the reduction is recorded as a waiver with their name.</div>

<div class="form-section">RTE students</div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="rte_full_waiver" value="1" id="rte" <?= setting('rte_full_waiver', '1') === '1' ? 'checked' : '' ?>>
<label class="form-check-label" for="rte">Students marked RTE get 100% concession on all fees automatically</label></div>
<button class="btn btn-primary">Save</button>
</div></form>

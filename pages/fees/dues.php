<?php
require_perm('fees.view');
$title = 'Dues & Reminders';
$year = working_year();
if (!$year) { echo '<div class="alert alert-warning">Add an academic year first.</div>'; return; }
$asOf = get('as_of', today());
$classes = rows('SELECT * FROM classes ORDER BY sort_order, name');
$list = dues_rows((int)$year['id'], $asOf, ['class_id' => (int)get('class_id'), 'section_id' => (int)get('section_id')]);
$show = get('show', 'overdue');
$min = (float)get('min', 0);
$list = array_values(array_filter($list, function ($r) use ($show, $min) {
    if ($show === 'overdue' && $r['payable_now'] <= 0) return false;
    if ($show === 'balance' && $r['balance'] <= 0) return false;
    return $r['payable_now'] >= $min;
}));
usort($list, fn($a, $b) => get('sort') === 'amount' ? $b['payable_now'] <=> $a['payable_now'] : 0);
$tpl = setting('reminder_text', 'Dear Parent, fee of {amount} is due for {student} ({class}). – {school}');
if (get('export') === 'csv') {
    $out = array_map(fn($r) => [$r['admission_no'], student_name($r), class_label($r), $r['roll_no'], $r['father_name'], $r['father_phone'], $r['total'], $r['paid'], $r['due_now'], $r['late_fee'], $r['payable_now'], $r['balance'], dmy($r['oldest_due'])], $list);
    csv_download('dues-' . $asOf . '.csv', ['Adm No', 'Student', 'Class', 'Roll', 'Father', 'Mobile', 'Year Fee', 'Paid', 'Overdue Fee', 'Late Fee', 'Payable Now', 'Year Balance', 'Oldest Due Date'], $out);
}
$tot = ['due' => array_sum(array_column($list, 'due_now')), 'late' => array_sum(array_column($list, 'late_fee')), 'pay' => array_sum(array_column($list, 'payable_now')), 'bal' => array_sum(array_column($list, 'balance'))];
$qs = $_GET;
?>
<script>window.SECTIONS = <?= sections_json() ?>;</script>
<form class="card card-body mb-3 no-print"><input type="hidden" name="p" value="fees/dues">
<div class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label">Class</label><select name="class_id" id="dcls" class="form-select"><?= select_options($classes, get('class_id'), 'id', 'name', 'All classes') ?></select></div>
  <div class="col-6 col-md-1"><label class="form-label">Section</label><select name="section_id" class="form-select" data-sections-for="dcls" data-selected="<?= h(get('section_id')) ?>" data-empty="All"></select></div>
  <div class="col-6 col-md-2"><label class="form-label">Dues as of</label><input type="date" name="as_of" class="form-control" value="<?= h($asOf) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label">Show</label><select name="show" class="form-select"><option value="overdue">Overdue now</option><option value="balance" <?= $show === 'balance' ? 'selected' : '' ?>>Any balance for year</option><option value="all" <?= $show === 'all' ? 'selected' : '' ?>>All students</option></select></div>
  <div class="col-6 col-md-1"><label class="form-label">Min ₹</label><input type="number" name="min" class="form-control" value="<?= h(get('min')) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label">Sort</label><select name="sort" class="form-select"><option value="">Class & name</option><option value="amount" <?= get('sort') === 'amount' ? 'selected' : '' ?>>Highest due first</option></select></div>
  <div class="col-md-2 d-flex gap-1"><button class="btn btn-primary flex-fill">Show</button><a class="btn btn-outline-secondary" title="CSV" href="index.php?<?= h(http_build_query($qs + ['export' => 'csv'])) ?>"><i class="bi bi-download"></i></a><button type="button" onclick="print()" class="btn btn-outline-secondary" title="Print"><i class="bi bi-printer"></i></button></div>
</div></form>
<div class="row g-3 mb-3">
<?= card_stat('Students with dues', (string)count(array_filter($list, fn($r) => $r['payable_now'] > 0)), 'people', 'danger') ?>
<?= card_stat('Overdue fees', money($tot['due']), 'exclamation-triangle', 'danger') ?>
<?= card_stat('Late fee', money($tot['late']), 'alarm', 'warning') ?>
<?= card_stat('Total payable now', money($tot['pay']), 'cash', 'primary', 'Year balance ' . money($tot['bal'])) ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
<thead><tr><th>Adm No</th><th>Student</th><th>Class</th><th>Father / Mobile</th><th class="text-end">Paid</th><th class="text-end">Overdue fee</th><th class="text-end">Late fee</th><th class="text-end">Payable now</th><th>Due since</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($list as $r): $phone = $r['whatsapp'] ?: $r['father_phone'];
  $msg = strtr($tpl, ['{student}' => student_name($r), '{class}' => class_label($r), '{amount}' => money($r['payable_now']), '{school}' => setting('school_name'), '{admission_no}' => $r['admission_no']]); ?>
<tr><td><?= h($r['admission_no']) ?></td><td><a href="<?= url('students/view', ['id' => $r['student_id']]) ?>"><?= h(student_name($r)) ?></a></td><td><?= h(class_label($r)) ?></td>
<td class="small"><?= h($r['father_name']) ?> <a href="tel:<?= h($r['father_phone']) ?>"><?= h($r['father_phone']) ?></a></td>
<td class="amount"><?= money($r['paid']) ?></td><td class="amount"><?= money($r['due_now']) ?></td><td class="amount"><?= $r['late_fee'] > 0 ? money($r['late_fee']) : '' ?></td>
<td class="amount fw-bold <?= $r['payable_now'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($r['payable_now']) ?></td>
<td class="small text-nowrap"><?= $r['oldest_due'] ? dmy($r['oldest_due']) . ' <span class="text-muted">(' . (int)((strtotime($asOf) - strtotime($r['oldest_due'])) / 86400) . 'd)</span>' : '' ?></td>
<td class="text-end text-nowrap no-print">
  <?php if ($phone && $r['payable_now'] > 0): ?><a class="btn btn-sm btn-outline-success" target="_blank" href="<?= h(wa_link($phone, $msg)) ?>" title="WhatsApp reminder"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
  <?php if (can('fees.collect') && $r['payable_now'] > 0): ?><a class="btn btn-sm btn-success" href="<?= url('fees/collect', ['enrollment' => $r['enrollment_id']]) ?>" title="Collect"><i class="bi bi-cash-stack"></i></a><?php endif; ?></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="10" class="text-center text-muted py-4">No dues. 🎉</td></tr><?php endif; ?>
</tbody>
<?php if ($list): ?><tfoot><tr class="fw-bold"><td colspan="5" class="text-end">Total (<?= count($list) ?>)</td><td class="amount"><?= money($tot['due']) ?></td><td class="amount"><?= money($tot['late']) ?></td><td class="amount"><?= money($tot['pay']) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
</table></div></div>
<p class="small text-muted mt-2 no-print">The WhatsApp button opens a ready message to the parent; you press send. Edit the message text in <a href="<?= url('settings/school') ?>">School Profile</a>.</p>

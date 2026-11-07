<?php
require_perm('fees.collect');
$title = 'Collect Fee';
$year = working_year();
$enrId = (int)get('enrollment');

if (is_post()) {
    $enrId = (int)post('enrollment');
    require_enrollment_scope(enrollment_full($enrId));
    $lines = [];
    foreach ((array)($_POST['pick'] ?? []) as $did => $on) {
        $lines[(int)$did] = ['pay' => (float)($_POST['pay'][$did] ?? 0), 'late' => (float)($_POST['late'][$did] ?? 0)];
    }
    $date = post('receipt_date') ?: today();
    if ($date > today()) { flash('err', 'Receipt date cannot be in the future.'); redirect('fees/collect', ['enrollment' => $enrId]); }
    if ($date < today() && !can('fees.cancel')) $date = today();   // only admin can back-date
    $mode = post('mode');
    if (!in_array($mode, payment_modes(), true)) { flash('err', 'Choose a payment mode.'); redirect('fees/collect', ['enrollment' => $enrId]); }
    if (post('reference') === '' && lookup_flag('payment_mode', $mode, 'ref')) {
        flash('err', 'Enter the ' . (lookup_flag('payment_mode', $mode, 'cheque') ? 'cheque / DD number' : 'transaction / reference number') . ' for ' . $mode . '.');
        redirect('fees/collect', ['enrollment' => $enrId]);
    }
    try {
        $rid = record_receipt($enrId, $lines, ['date' => $date, 'mode' => $mode, 'reference' => post('reference'), 'bank_name' => post('bank_name'),
            'paid_by' => post('paid_by'), 'remarks' => post('remarks')]);
        flash('ok', 'Fee collected. Receipt ' . val('SELECT receipt_no FROM receipts WHERE id = ?', [$rid]) . ' created.');
        redirect('fees/receipt', ['id' => $rid, 'new' => 1]);
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        redirect('fees/collect', ['enrollment' => $enrId]);
    }
}

$e = $enrId ? enrollment_full($enrId) : null;
require_enrollment_scope($e);
$results = [];
if (!$e && get('q') !== '' && $year) {
    $qq = '%' . get('q') . '%';
    $results = rows("SELECT e.id AS enrollment_id, s.id, s.admission_no, s.first_name, s.last_name, s.father_name, s.father_phone, c.name AS class_name, sec.name AS section_name, e.status
                     FROM enrollments e JOIN students s ON s.id = e.student_id JOIN classes c ON c.id = e.class_id LEFT JOIN sections sec ON sec.id = e.section_id
                     WHERE e.academic_year_id = ? " . scope_sql('e') . " AND (s.admission_no LIKE ? OR CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) LIKE ? OR s.father_name LIKE ? OR s.father_phone LIKE ? OR s.mother_phone LIKE ? OR e.roll_no = ?)
                     ORDER BY s.admission_no = ? DESC, c.sort_order, s.first_name LIMIT 30", [$year['id'], $qq, $qq, $qq, $qq, $qq, get('q'), get('q')]);
    if (count($results) === 1) redirect('fees/collect', ['enrollment' => $results[0]['enrollment_id']]);
}
?>
<?php if (!$e): ?>
<form class="card card-body mb-3" style="max-width:720px"><input type="hidden" name="p" value="fees/collect">
<label class="form-label">Find student</label>
<div class="input-group input-group-lg"><input name="q" class="form-control" autofocus value="<?= h(get('q')) ?>" placeholder="Admission no, name, father's name or mobile"><button class="btn btn-primary"><i class="bi bi-search"></i></button></div>
</form>
<?php if (get('q') !== ''): ?>
<div class="card" style="max-width:720px"><div class="list-group list-group-flush">
<?php foreach ($results as $r): ?><a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= url('fees/collect', ['enrollment' => $r['enrollment_id']]) ?>">
<span><b><?= h(student_name($r)) ?></b> <span class="text-muted">· <?= h($r['admission_no']) ?> · <?= h(class_label($r)) ?></span><br><span class="small">Father: <?= h($r['father_name']) ?> <?= h($r['father_phone']) ?></span></span>
<span><?= $r['status'] === 'left' ? status_badge('left') : '<i class="bi bi-chevron-right"></i>' ?></span></a><?php endforeach; ?>
<?php if (!$results): ?><div class="list-group-item text-muted">No student found in <?= h($year['name'] ?? '') ?>.</div><?php endif; ?>
</div></div>
<?php endif; ?>
<?php return; endif;

$open = open_demands($enrId);
$sum = fee_summary($enrId);
$recent = rows('SELECT * FROM receipts WHERE enrollment_id = ? AND cancelled = 0 ORDER BY id DESC LIMIT 5', [$enrId]);
$modes = payment_modes();
?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
  <div class="flex-fill"><h5 class="mb-0"><a href="<?= url('students/view', ['id' => $e['student_id']]) ?>" class="text-decoration-none"><?= h(student_name($e)) ?></a> <?= $e['status'] === 'left' ? status_badge('left') : '' ?></h5>
  <div class="text-muted small">Adm <?= h($e['admission_no']) ?> · <?= h(class_label($e)) ?> · <?= h($e['year_name']) ?> · Father: <?= h($e['father_name']) ?> <?= h($e['father_phone']) ?></div></div>
  <div class="text-end"><div class="small text-muted">Due now (with late fee)</div><div class="fs-4 fw-bold <?= $sum['payable_now'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($sum['payable_now']) ?></div>
  <div class="small text-muted">Year balance <?= money($sum['balance']) ?></div></div>
  <a href="<?= url('fees/collect') ?>" class="btn btn-light">Another student</a>
</div></div>

<?php if (!$open): ?>
<div class="alert alert-success">No pending fees for this year. 🎉</div>
<?php else: ?>
<form method="post" id="collectForm" class="card">
<?= csrf_field() ?><input type="hidden" name="enrollment" value="<?= $enrId ?>">
<div class="card-body border-bottom d-flex flex-wrap gap-2 align-items-center">
  <button type="button" id="selectDue" class="btn btn-sm btn-outline-primary">Select all due now</button>
  <div class="input-group input-group-sm" style="max-width:300px"><span class="input-group-text">Parent is paying ₹</span><input type="number" step="0.01" min="0" id="lumpAmount" class="form-control"><button type="button" id="lumpApply" class="btn btn-outline-primary">Apply</button></div>
  <span class="small text-muted">An amount is adjusted against the oldest fees first (late fee, then fee).</span>
</div>
<div class="table-responsive"><table class="table table-sm mb-0 align-middle">
<thead><tr><th style="width:30px"></th><th>Due date</th><th>Fee</th><th class="text-end">Balance</th><th style="width:140px">Paying now</th><th style="width:130px">Late fee</th></tr></thead><tbody>
<?php foreach ($open as $d): $isDue = $d['is_due']; ?>
<tr class="pay-row" data-due="<?= $isDue ? 1 : 0 ?>">
  <td><input type="checkbox" class="form-check-input pick" name="pick[<?= $d['id'] ?>]" value="1" <?= $isDue ? 'checked' : '' ?>></td>
  <td class="text-nowrap <?= $isDue ? 'text-danger' : 'text-muted' ?>"><?= dmy($d['due_date']) ?></td>
  <td><?= h($d['head_name']) ?> <span class="text-muted">– <?= h($d['period_label']) ?></span></td>
  <td class="amount"><?= money($d['balance']) ?></td>
  <td><input type="number" step="0.01" min="0" max="<?= $d['balance'] ?>" name="pay[<?= $d['id'] ?>]" class="form-control form-control-sm text-end pay" value="<?= $d['balance'] ?>" data-balance="<?= $d['balance'] ?>"></td>
  <td><?php if ($d['late_fee'] > 0): ?><input type="number" step="0.01" min="0" max="<?= $d['late_fee'] ?>" name="late[<?= $d['id'] ?>]" class="form-control form-control-sm text-end late" value="<?= $d['late_fee'] ?>" data-late="<?= $d['late_fee'] ?>" <?= can('fees.waive_late') ? '' : 'readonly' ?> title="Calculated late fee <?= money($d['late_fee']) ?>"><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="card-body border-top"><div class="row g-2">
  <div class="col-md-2"><label class="form-label">Date</label><input type="date" name="receipt_date" class="form-control" value="<?= today() ?>" max="<?= today() ?>" <?= can('fees.cancel') ? '' : 'readonly' ?>></div>
  <div class="col-md-2"><label class="form-label req">Mode</label><select name="mode" class="form-select"><?php foreach ($modes as $m): ?><option data-cash="<?= lookup_flag('payment_mode', $m, 'cash') ? 1 : 0 ?>" data-ref="<?= lookup_flag('payment_mode', $m, 'ref') ? 1 : 0 ?>"><?= h($m) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3 ref-field"><label class="form-label">Txn ID / Cheque No</label><input name="reference" class="form-control"></div>
  <div class="col-md-2 ref-field"><label class="form-label">Bank</label><input name="bank_name" class="form-control" list="dl_bank" autocomplete="off"><?= lookup_datalist('bank') ?></div>
  <div class="col-md-3"><label class="form-label">Paid by</label><input name="paid_by" class="form-control" placeholder="<?= h($e['father_name']) ?>"></div>
  <div class="col-12"><label class="form-label">Remarks</label><input name="remarks" class="form-control"></div>
</div></div>
<div class="sticky-total d-flex flex-wrap gap-3 align-items-center">
  <div>Fee <b id="feeTotal">₹0</b></div><div>Late fee <b id="lateTotal">₹0</b></div>
  <div class="fs-5">Total <b id="grandTotal">₹0</b></div>
  <button id="submitBtn" class="btn btn-success btn-lg ms-auto" data-confirm="Save this payment and create the receipt?"><i class="bi bi-check2-circle"></i> Collect &amp; print receipt</button>
</div>
</form>
<?php if (!can('fees.waive_late')): ?><div class="small text-muted mt-2">Late fee can only be reduced by an administrator or accountant.</div><?php endif; ?>
<?php endif; ?>

<?php if ($recent): ?>
<h6 class="mt-4">Recent receipts</h6>
<div class="card"><table class="table table-sm mb-0"><tbody>
<?php foreach ($recent as $r): ?><tr><td><?= dmy($r['receipt_date']) ?></td><td><?= h($r['receipt_no']) ?></td><td><?= h($r['mode']) ?></td><td class="amount"><?= money($r['total_amount']) ?></td><td class="text-end"><a target="_blank" href="<?= url('fees/receipt', ['id' => $r['id']]) ?>"><i class="bi bi-printer"></i></a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

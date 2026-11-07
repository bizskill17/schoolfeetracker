<?php
require_perm('students.view');
$id = (int)get('id');
$s = row('SELECT * FROM students WHERE id = ?', [$id]);
if (!$s) { flash('err', 'Student not found.'); redirect('students/list'); }
$year = working_year();
$enr = $year ? student_enrollment($id, (int)$year['id']) : null;
if (section_scope() !== null && !$enr) { flash('err', 'This student is not in your class this year.'); redirect('students/list'); }
require_enrollment_scope($enr);
$title = student_name($s);

if (is_post()) {
    $act = post('act');
    try {
        switch ($act) {
            case 'leave':
                require_perm('students.edit');
                $date = post('leaving_date') ?: today();
                update('students', ['status' => post('leave_type') === 'passed_out' ? 'passed_out' : 'left', 'leaving_date' => $date,
                    'leaving_reason' => post('leaving_reason'), 'tc_issued_no' => post('tc_issued_no')], 'id = ?', [$id]);
                $removed = 0;
                if ($enr) {
                    update('enrollments', ['status' => 'left'], 'id = ?', [$enr['id']]);
                    if (post('stop_fees')) $removed = close_demands_after((int)$enr['id'], $date);
                }
                audit('student.leave', 'student', $id, ['date' => $date, 'reason' => post('leaving_reason'), 'removed_instalments' => $removed]);
                flash('ok', 'Marked as left.' . ($removed ? " $removed future unpaid instalments removed." : '') . ' Any unpaid dues remain on record.');
                break;
            case 'readmit':
                require_perm('students.edit');
                update('students', ['status' => 'active', 'leaving_date' => null], 'id = ?', [$id]);
                if ($enr) { update('enrollments', ['status' => 'active'], 'id = ?', [$enr['id']]); generate_demands((int)$enr['id']); }
                audit('student.readmit', 'student', $id);
                flash('ok', 'Student is active again. Fee schedule refreshed.');
                break;
            case 'add_concession':
                require_perm('fees.concession');
                if (!$enr) throw new RuntimeException('Student is not enrolled in this year.');
                $ct = post('concession_type_id') ? row('SELECT * FROM concession_types WHERE id = ?', [(int)post('concession_type_id')]) : null;
                $kind = post('kind') ?: ($ct['kind'] ?? 'percent');
                $value = post('value') !== '' ? (float)post('value') : (float)($ct['value'] ?? 0);
                if ($value <= 0) throw new RuntimeException('Enter the concession value.');
                if ($kind === 'percent' && $value > 100) throw new RuntimeException('Percentage cannot be more than 100.');
                $reason = post('reason') ?: ($ct['name'] ?? '');
                if (!$reason) throw new RuntimeException('Enter a reason for the concession.');
                $cid = insert('student_concessions', ['enrollment_id' => $enr['id'], 'concession_type_id' => $ct['id'] ?? null, 'kind' => $kind === 'fixed' ? 'fixed' : 'percent',
                    'value' => $value, 'fee_head_id' => nullif(post('fee_head_id')) ?? ($ct['fee_head_id'] ?? null), 'reason' => $reason, 'approved_by' => $_SESSION['uid']]);
                $n = generate_demands((int)$enr['id']);
                audit('concession.add', 'student', $id, ['concession_id' => $cid, 'kind' => $kind, 'value' => $value, 'reason' => $reason]);
                flash('ok', "Concession added. $n unpaid instalments updated (instalments already paid are not changed).");
                break;
            case 'del_concession':
                require_perm('fees.concession');
                q('DELETE FROM student_concessions WHERE id = ? AND enrollment_id = ?', [(int)post('cid'), $enr['id'] ?? 0]);
                $n = $enr ? generate_demands((int)$enr['id']) : 0;
                audit('concession.remove', 'student', $id, ['concession_id' => (int)post('cid')]);
                flash('ok', "Concession removed. $n unpaid instalments updated.");
                break;
            case 'add_charge':
                require_perm('fees.adjust');
                if (!$enr) throw new RuntimeException('Student is not enrolled in this year.');
                $head = (int)post('fee_head_id');
                $amount = (float)post('amount');
                if (!$head || $amount <= 0) throw new RuntimeException('Choose a fee head and amount.');
                $label = post('label') ?: 'Charge ' . dmy(today());
                $did = add_charge((int)$enr['id'], $head, $label, $amount, post('due_date') ?: today(), post('notes'));
                audit('demand.add', 'student', $id, ['demand' => $did, 'head' => $head, 'amount' => $amount, 'label' => $label]);
                flash('ok', 'Charge added.');
                break;
            case 'waive':
                require_perm('fees.adjust');
                $d = row('SELECT * FROM fee_demands WHERE id = ? AND enrollment_id = ?', [(int)post('demand_id'), $enr['id'] ?? 0]);
                if (!$d) throw new RuntimeException('Instalment not found.');
                $amt = (float)post('amount');
                $bal = demand_balance($d);
                if ($amt <= 0 || $amt > $bal + 0.001) throw new RuntimeException('Waiver must be between ₹1 and the balance ' . money($bal) . '.');
                if (!post('reason')) throw new RuntimeException('Enter a reason for the waiver.');
                update('fee_demands', ['waived' => round((float)$d['waived'] + $amt, 2), 'notes' => mb_substr(trim(($d['notes'] ? $d['notes'] . '; ' : '') . 'Waived ' . money($amt) . ': ' . post('reason')), 0, 200)], 'id = ?', [$d['id']]);
                audit('demand.waive', 'student', $id, ['demand' => $d['id'], 'amount' => $amt, 'reason' => post('reason')]);
                flash('ok', 'Waiver recorded.');
                break;
            case 'delete_demand':
                require_perm('fees.adjust');
                $d = row('SELECT * FROM fee_demands WHERE id = ? AND enrollment_id = ?', [(int)post('demand_id'), $enr['id'] ?? 0]);
                if (!$d || (float)$d['paid'] > 0 || (float)$d['late_fee_paid'] > 0) throw new RuntimeException('Only unpaid charges can be removed.');
                q('DELETE FROM fee_demands WHERE id = ?', [$d['id']]);
                audit('demand.delete', 'student', $id, $d);
                flash('ok', 'Charge removed.');
                break;
            case 'regen':
                require_perm('fees.adjust');
                $n = $enr ? generate_demands((int)$enr['id']) : 0;
                flash('ok', "Fee schedule refreshed ($n changes).");
                break;
            case 'upload_doc':
                require_perm('students.edit');
                $f = save_upload('doc', 'docs', ['pdf', 'jpg', 'jpeg', 'png'], 5242880);
                if (!$f) throw new RuntimeException('Choose a file.');
                insert('student_documents', ['student_id' => $id, 'title' => post('doc_title') ?: 'Document', 'file_name' => $f]);
                audit('student.doc_upload', 'student', $id, post('doc_title'));
                flash('ok', 'Document uploaded.');
                break;
            case 'del_doc':
                require_perm('students.edit');
                $doc = row('SELECT * FROM student_documents WHERE id = ? AND student_id = ?', [(int)post('doc_id'), $id]);
                if ($doc) { @unlink(APP_ROOT . '/uploads/' . $doc['file_name']); q('DELETE FROM student_documents WHERE id = ?', [$doc['id']]); }
                flash('ok', 'Document deleted.');
                break;
            case 'delete_student':
                require_perm('students.delete');
                if (val('SELECT COUNT(*) FROM receipts WHERE student_id = ?', [$id])) throw new RuntimeException('This student has fee receipts and cannot be deleted. Mark as left instead.');
                audit('student.delete', 'student', $id, ['admission_no' => $s['admission_no'], 'name' => student_name($s)]);
                q('DELETE FROM students WHERE id = ?', [$id]);
                flash('ok', 'Student deleted.');
                redirect('students/list');
        }
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('students/view', ['id' => $id, 'tab' => post('tab', 'fees')]);
}

$tab = get('tab', 'fees');
$sum = $enr ? fee_summary((int)$enr['id']) : null;
$demands = $enr ? rows('SELECT d.*, fh.name AS head_name, fh.late_fee_applies, fh.late_fee_rule_id FROM fee_demands d JOIN fee_heads fh ON fh.id = d.fee_head_id
                        WHERE d.enrollment_id = ? ORDER BY d.due_date, fh.sort_order, d.id', [$enr['id']]) : [];
$concs = $enr ? rows('SELECT sc.*, fh.name AS head_name, u.name AS by_name FROM student_concessions sc LEFT JOIN fee_heads fh ON fh.id = sc.fee_head_id
                      LEFT JOIN users u ON u.id = sc.approved_by WHERE sc.enrollment_id = ?', [$enr['id']]) : [];
$receipts = rows('SELECT r.*, y.name AS year_name, u.name AS by_name FROM receipts r JOIN academic_years y ON y.id = r.academic_year_id
                  LEFT JOIN users u ON u.id = r.created_by WHERE r.student_id = ? ORDER BY r.receipt_date DESC, r.id DESC', [$id]);
$history = rows('SELECT e.*, y.name AS year_name, c.name AS class_name, sec.name AS section_name FROM enrollments e
                 JOIN academic_years y ON y.id = e.academic_year_id JOIN classes c ON c.id = e.class_id LEFT JOIN sections sec ON sec.id = e.section_id
                 WHERE e.student_id = ? ORDER BY y.start_date DESC', [$id]);
$docs = rows('SELECT * FROM student_documents WHERE student_id = ? ORDER BY id DESC', [$id]);
$refunds = rows('SELECT * FROM refunds WHERE student_id = ? ORDER BY refund_date DESC', [$id]);
$heads = rows("SELECT * FROM fee_heads WHERE active = 1 AND (is_system = 0 OR name = 'Previous Year Dues') ORDER BY is_system DESC, sort_order");
$ctypes = rows('SELECT * FROM concession_types WHERE active = 1 ORDER BY name');
$optFees = $enr ? rows('SELECT fh.id, fh.name, fs.amount FROM fee_structure fs JOIN fee_heads fh ON fh.id = fs.fee_head_id
                        WHERE fs.academic_year_id = ? AND fs.class_id = ? AND fh.is_optional = 1 AND fs.amount > 0', [$year['id'], $enr['class_id']]) : [];
$lateMap = $demands ? late_fees_map(array_filter($demands, fn($d) => !$d['carried_forward']), today()) : [];
$phone = $s['whatsapp'] ?: ($s['father_phone'] ?: $s['mother_phone']);
?>
<?= lookup_datalist('waiver_reason') ?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-start">
  <?php if ($s['photo']): ?><img src="file.php?f=<?= h($s['photo']) ?>" class="student-photo"><?php else: ?><div class="photo-ph"><i class="bi bi-person"></i></div><?php endif; ?>
  <div class="flex-fill">
    <h4 class="mb-1"><?= h(student_name($s)) ?> <?= status_badge($s['status']) ?> <?= $s['is_rte'] ? '<span class="badge text-bg-info">RTE</span>' : '' ?></h4>
    <div class="text-muted">Adm No <b><?= h($s['admission_no']) ?></b>
      <?php if ($enr): ?> · <?= h(class_label($enr)) ?><?= $enr['roll_no'] ? ' · Roll ' . h($enr['roll_no']) : '' ?> · <?= h($year['name']) ?><?php else: ?> · <span class="text-danger">Not enrolled in <?= h($year['name'] ?? '') ?></span><?php endif; ?></div>
    <div class="small mt-1">Father: <?= h($s['father_name']) ?> <?= $s['father_phone'] ? '· <a href="tel:' . h($s['father_phone']) . '">' . h($s['father_phone']) . '</a>' : '' ?>
      <?= $s['mother_name'] ? ' · Mother: ' . h($s['mother_name']) : '' ?><?= $enr && $enr['route_name'] ? ' · <i class="bi bi-bus-front"></i> ' . h($enr['route_name']) : '' ?></div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($enr && can('fees.collect') && $enr['status'] !== 'left'): ?><a class="btn btn-success" href="<?= url('fees/collect', ['enrollment' => $enr['id']]) ?>"><i class="bi bi-cash-stack"></i> Collect fee</a><?php endif; ?>
    <?php if (can('students.edit')): ?><a class="btn btn-outline-primary" href="<?= url('students/form', ['id' => $id]) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= url('students/print', ['id' => $id]) ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
  </div>
</div></div>

<?php if ($sum): ?>
<div class="row g-3 mb-3">
<?= card_stat('Total fee (' . $year['name'] . ')', money($sum['total'] - $sum['concession'] - $sum['waived']), 'journal-text', 'primary', $sum['concession'] + $sum['waived'] > 0 ? 'after ' . money($sum['concession'] + $sum['waived']) . ' concession/waiver' : '') ?>
<?= card_stat('Paid', money($sum['paid']), 'check2-circle', 'success', $sum['late_paid'] > 0 ? '+ ' . money($sum['late_paid']) . ' late fee' : '') ?>
<?= card_stat('Due now', money($sum['payable_now']), 'exclamation-triangle', $sum['payable_now'] > 0 ? 'danger' : 'success', $sum['late_fee'] > 0 ? 'incl. ' . money($sum['late_fee']) . ' late fee' : 'as of today') ?>
<?= card_stat('Balance for year', money($sum['balance']), 'hourglass-split', 'warning', 'including future months') ?>
</div>
<?php if ($sum['payable_now'] > 0 && $phone): ?>
<div class="mb-3"><a class="btn btn-sm btn-outline-success" target="_blank" href="<?= h(wa_link($phone, strtr(setting('reminder_text'), ['{student}' => student_name($s), '{class}' => class_label($enr), '{amount}' => money($sum['payable_now']), '{school}' => setting('school_name'), '{admission_no}' => $s['admission_no']]))) ?>"><i class="bi bi-whatsapp"></i> Send fee reminder on WhatsApp</a></div>
<?php endif; endif; ?>

<ul class="nav nav-tabs mb-3">
<?php foreach (['fees' => 'Fee schedule', 'receipts' => 'Receipts (' . count($receipts) . ')', 'details' => 'Details', 'docs' => 'Documents (' . count($docs) . ')', 'history' => 'History & actions'] as $k => $l): ?>
<li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= url('students/view', ['id' => $id, 'tab' => $k]) ?>"><?= h($l) ?></a></li>
<?php endforeach; ?>
</ul>

<?php if ($tab === 'fees'): ?>
  <?php if (!$enr): ?><div class="alert alert-info">Not enrolled in <?= h($year['name'] ?? '') ?>. <?= can('students.edit') ? '<a href="' . url('students/form', ['id' => $id]) . '">Enroll in a class</a>' : '' ?></div>
  <?php else: ?>
  <div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
  <thead><tr><th>Due date</th><th>Fee</th><th>Period</th><th class="text-end">Amount</th><th class="text-end">Concession / waiver</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th class="text-end">Late fee</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($demands as $d):
      $bal = demand_balance($d); $lf = $lateMap[$d['id']] ?? 0;
      $st = $d['carried_forward'] ? 'Carried forward' : ($bal <= 0 ? 'Paid' : ($d['due_date'] <= today() ? 'Overdue' : 'Upcoming')); ?>
  <tr class="<?= $d['carried_forward'] ? 'text-muted text-decoration-line-through' : '' ?>">
    <td class="text-nowrap"><?= dmy($d['due_date']) ?></td><td><?= h($d['head_name']) ?><?= $d['notes'] ? '<div class="small text-muted">' . h($d['notes']) . '</div>' : '' ?></td><td><?= h($d['period_label']) ?></td>
    <td class="amount"><?= money($d['amount']) ?></td><td class="amount"><?= (float)$d['concession'] + (float)$d['waived'] > 0 ? money((float)$d['concession'] + (float)$d['waived']) : '' ?></td>
    <td class="amount"><?= (float)$d['paid'] > 0 ? money($d['paid']) : '' ?></td><td class="amount fw-semibold"><?= $bal > 0 ? money($bal) : '' ?></td>
    <td class="amount"><?= $lf > 0 ? '<span class="text-danger">' . money($lf) . '</span>' : ((float)$d['late_fee_paid'] > 0 ? '<span class="small text-muted">paid ' . money($d['late_fee_paid']) . '</span>' : '') ?></td>
    <td><span class="badge text-bg-<?= ['Paid' => 'success', 'Overdue' => 'danger', 'Upcoming' => 'light border', 'Carried forward' => 'secondary'][$st] ?>"><?= $st ?></span></td>
    <td class="text-end text-nowrap">
      <?php if (can('fees.adjust') && $bal > 0 && !$d['carried_forward']): ?>
        <button class="btn btn-sm btn-link p-0" data-bs-toggle="modal" data-bs-target="#waiveModal" data-id="<?= $d['id'] ?>" data-bal="<?= $bal ?>" data-label="<?= h($d['head_name'] . ' – ' . $d['period_label']) ?>" title="Waive"><i class="bi bi-scissors"></i></button>
        <?php if (preg_match('/^X/', $d['period_key']) && (float)$d['paid'] == 0): ?>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete_demand"><input type="hidden" name="demand_id" value="<?= $d['id'] ?>"><button class="btn btn-sm btn-link p-0 text-danger" data-confirm="Remove this charge?"><i class="bi bi-trash"></i></button></form>
        <?php endif; ?>
      <?php endif; ?>
    </td></tr>
  <?php endforeach; if (!$demands): ?><tr><td colspan="10" class="text-center text-muted py-3">No fees set up. Check the <a href="<?= url('settings/fee_structure') ?>">fee structure</a> for <?= h($enr['class_name']) ?>.</td></tr><?php endif; ?>
  </tbody></table></div></div>

  <div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Concessions</div><div class="card-body">
    <?php foreach ($concs as $c): ?>
      <div class="d-flex justify-content-between border-bottom py-1"><div><b><?= $c['kind'] === 'percent' ? h($c['value'] + 0) . '%' : money($c['value']) . '/instalment' ?></b> on <?= h($c['head_name'] ?? 'all fees') ?>
        <div class="small text-muted"><?= h($c['reason']) ?> · by <?= h($c['by_name'] ?? '') ?> on <?= dmy($c['created_at']) ?></div></div>
        <?php if (can('fees.concession')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="del_concession"><input type="hidden" name="cid" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-link text-danger" data-confirm="Remove this concession? Unpaid instalments will go back to full amount."><i class="bi bi-x-lg"></i></button></form><?php endif; ?></div>
    <?php endforeach; if (!$concs): ?><div class="text-muted small mb-2"><?= $s['is_rte'] && setting('rte_full_waiver', '1') === '1' ? 'RTE student – all fees exempt.' : 'No concession.' ?></div><?php endif; ?>
    <?php if (can('fees.concession')): ?>
    <form method="post" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="act" value="add_concession">
      <div class="col-12"><select name="concession_type_id" class="form-select form-select-sm"><?= select_options($ctypes, '', 'id', 'name', '— Type (or fill your own below) —') ?></select></div>
      <div class="col-4"><select name="kind" class="form-select form-select-sm"><option value="">Type default</option><option value="percent">%</option><option value="fixed">₹ / instalment</option></select></div>
      <div class="col-3"><input name="value" type="number" step="0.01" class="form-control form-control-sm" placeholder="Value"></div>
      <div class="col-5"><select name="fee_head_id" class="form-select form-select-sm"><?= select_options($heads, '', 'id', 'name', 'Type default / all fees') ?></select></div>
      <div class="col-9"><input name="reason" class="form-control form-control-sm" placeholder="Reason / approved by" list="dl_waiver_reason"></div>
      <div class="col-3"><button class="btn btn-sm btn-primary w-100">Add</button></div>
    </form>
    <div class="form-text">Applies to unpaid instalments of this year. If you choose a type, its fee head is used unless you pick one.</div>
    <?php endif; ?>
  </div></div></div>
  <?php if (can('fees.adjust')): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Add a charge / optional fee</div><div class="card-body">
    <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="act" value="add_charge">
      <?php if ($optFees): ?><div class="col-12"><select class="form-select form-select-sm" onchange="const o=this.selectedOptions[0];if(o.value){this.form.fee_head_id.value=o.dataset.head;this.form.amount.value=o.dataset.amt;this.form.label.value=o.text.split(' – ')[0];}">
        <option value="">Quick pick an optional fee…</option><?php foreach ($optFees as $o): ?><option value="1" data-head="<?= $o['id'] ?>" data-amt="<?= h($o['amount']) ?>"><?= h($o['name']) ?> – <?= money($o['amount']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="col-6"><select name="fee_head_id" class="form-select form-select-sm" required><?= select_options($heads, '', 'id', 'name', 'Fee head') ?></select></div>
      <div class="col-6"><input name="amount" type="number" step="0.01" min="1" class="form-control form-control-sm" placeholder="Amount ₹" required></div>
      <div class="col-6"><input name="label" class="form-control form-control-sm" placeholder="Description (e.g. Science trip)"></div>
      <div class="col-6"><input name="due_date" type="date" class="form-control form-control-sm" value="<?= today() ?>"></div>
      <div class="col-9"><input name="notes" class="form-control form-control-sm" placeholder="Note (optional)"></div>
      <div class="col-3"><button class="btn btn-sm btn-primary w-100">Add</button></div>
    </form>
    <hr><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="regen"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Refresh fee schedule from fee structure</button></form>
  </div></div></div>
  <?php endif; ?>
  </div>

  <div class="modal fade" id="waiveModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="act" value="waive"><input type="hidden" name="demand_id" id="wId">
    <div class="modal-header"><h5 class="modal-title">Waive fee</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><p id="wLabel" class="fw-semibold"></p>
      <label class="form-label">Amount to waive</label><input type="number" step="0.01" min="1" name="amount" id="wAmt" class="form-control mb-2" required>
      <label class="form-label">Reason</label><input name="reason" class="form-control" required list="dl_waiver_reason" placeholder="e.g. Approved by Principal – financial hardship"></div>
    <div class="modal-footer"><button class="btn btn-primary">Waive</button></div></form></div></div>
  <script>document.getElementById('waiveModal').addEventListener('show.bs.modal', e => { const b = e.relatedTarget; wId.value = b.dataset.id; wAmt.value = b.dataset.bal; wAmt.max = b.dataset.bal; wLabel.textContent = b.dataset.label + ' (balance ₹' + b.dataset.bal + ')'; });</script>
  <?php endif; ?>

<?php elseif ($tab === 'receipts'): ?>
  <div class="card"><div class="table-responsive"><table class="table mb-0 align-middle">
  <thead><tr><th>Date</th><th>Receipt No</th><th>Year</th><th>Mode</th><th class="text-end">Fee</th><th class="text-end">Late fee</th><th class="text-end">Total</th><th>By</th><th></th></tr></thead><tbody>
  <?php foreach ($receipts as $r): ?>
  <tr class="<?= $r['cancelled'] ? 'text-muted' : '' ?>"><td><?= dmy($r['receipt_date']) ?></td><td><?= h($r['receipt_no']) ?> <?= $r['cancelled'] ? '<span class="badge text-bg-danger">Cancelled</span>' : '' ?></td><td><?= h($r['year_name']) ?></td>
  <td><?= h($r['mode']) ?> <span class="small text-muted"><?= h($r['reference']) ?></span></td><td class="amount"><?= money($r['fee_amount']) ?></td><td class="amount"><?= (float)$r['late_fee'] ? money($r['late_fee']) : '' ?></td>
  <td class="amount fw-semibold"><?= money($r['total_amount']) ?></td><td class="small"><?= h($r['by_name']) ?></td>
  <td><a class="btn btn-sm btn-light" target="_blank" href="<?= url('fees/receipt', ['id' => $r['id']]) ?>"><i class="bi bi-printer"></i></a></td></tr>
  <?php endforeach; if (!$receipts): ?><tr><td colspan="9" class="text-center text-muted py-3">No receipts yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <?php if ($refunds): ?><h6 class="mt-3">Refunds</h6><div class="card"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>No</th><th>Reason</th><th class="text-end">Amount</th></tr></thead><tbody>
  <?php foreach ($refunds as $rf): ?><tr><td><?= dmy($rf['refund_date']) ?></td><td><?= h($rf['refund_no']) ?></td><td><?= h($rf['reason']) ?></td><td class="amount"><?= money($rf['amount']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

<?php elseif ($tab === 'details'): ?>
  <div class="card"><div class="card-body">
  <?php foreach (STUDENT_GROUPS as $g => $gl): ?>
    <div class="form-section <?= $g === 'basic' ? 'mt-0' : '' ?>"><?= h($gl) ?></div>
    <div class="row g-2">
    <?php foreach (STUDENT_FIELDS as $f => $def) if ($def[1] === $g): $v = $s[$f]; if ($def[2] === 'date') $v = dmy($v); ?>
      <div class="col-6 col-md-4 col-lg-3"><div class="small text-muted"><?= h($def[0]) ?></div><div><?= $v !== null && $v !== '' ? nl2br(h($v)) : '—' ?></div></div>
    <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div></div>

<?php elseif ($tab === 'docs'): ?>
  <div class="card"><div class="card-body">
  <?php if (can('students.edit')): ?>
  <form method="post" enctype="multipart/form-data" class="row g-2 mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="upload_doc"><input type="hidden" name="tab" value="docs">
    <div class="col-md-4"><select name="doc_title" class="form-select"><?= lookup_options('document_type', '', '') ?></select></div>
    <div class="col-md-5"><input type="file" name="doc" class="form-control" accept=".pdf,image/*" required></div>
    <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-upload"></i> Upload (max 5 MB)</button></div>
  </form>
  <?php endif; ?>
  <ul class="list-group">
  <?php foreach ($docs as $d): ?><li class="list-group-item d-flex justify-content-between align-items-center"><span><i class="bi bi-file-earmark"></i> <a target="_blank" href="file.php?f=<?= h($d['file_name']) ?>"><?= h($d['title']) ?></a> <span class="small text-muted"><?= dmy($d['uploaded_at']) ?></span></span>
    <?php if (can('students.edit')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="del_doc"><input type="hidden" name="tab" value="docs"><input type="hidden" name="doc_id" value="<?= $d['id'] ?>"><button class="btn btn-sm btn-link text-danger" data-confirm="Delete document?"><i class="bi bi-trash"></i></button></form><?php endif; ?></li>
  <?php endforeach; if (!$docs): ?><li class="list-group-item text-muted">No documents uploaded.</li><?php endif; ?>
  </ul></div></div>

<?php else: ?>
  <div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="card-header">Class history</div><table class="table table-sm mb-0"><thead><tr><th>Year</th><th>Class</th><th>Joined</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($history as $hst): ?><tr><td><?= h($hst['year_name']) ?></td><td><?= h(class_label($hst)) ?></td><td><?= dmy($hst['join_date']) ?></td><td><?= status_badge($hst['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($s['status'] !== 'active'): ?><div class="alert alert-secondary mt-3 small">Left on <?= dmy($s['leaving_date']) ?><?= $s['leaving_reason'] ? ' – ' . h($s['leaving_reason']) : '' ?><?= $s['tc_issued_no'] ? ' · TC No ' . h($s['tc_issued_no']) : '' ?></div><?php endif; ?>
  </div>
  <div class="col-lg-6">
  <?php if (can('students.edit')): ?>
    <?php if ($s['status'] === 'active'): ?>
    <form method="post" class="card"><div class="card-header">Student leaving school / TC</div><div class="card-body row g-2"><?= csrf_field() ?><input type="hidden" name="act" value="leave"><input type="hidden" name="tab" value="history">
      <div class="col-6"><label class="form-label">Type</label><select name="leave_type" class="form-select"><option value="left">Left / TC issued</option><option value="passed_out">Passed out (final class)</option></select></div>
      <div class="col-6"><label class="form-label">Leaving date</label><input type="date" name="leaving_date" class="form-control" value="<?= today() ?>"></div>
      <div class="col-6"><label class="form-label">TC number issued</label><input name="tc_issued_no" class="form-control"></div>
      <div class="col-6"><label class="form-label">Reason</label><select name="leaving_reason" class="form-select"><?= lookup_options('leaving_reason', '') ?></select></div>
      <div class="col-12 form-check ms-1"><input type="checkbox" class="form-check-input" name="stop_fees" value="1" id="sf" checked><label for="sf" class="form-check-label">Remove unpaid instalments due after the leaving date</label></div>
      <?php if ($sum && $sum['due_now'] > 0): ?><div class="col-12"><div class="alert alert-warning py-2 small mb-0">This student still owes <?= money($sum['payable_now']) ?>. Collect dues before issuing the TC.</div></div><?php endif; ?>
      <div class="col-12"><button class="btn btn-warning" data-confirm="Mark this student as left?">Mark as left</button></div></div></form>
    <?php else: ?>
    <form method="post" class="card card-body"><?= csrf_field() ?><input type="hidden" name="act" value="readmit"><input type="hidden" name="tab" value="history"><button class="btn btn-outline-success" data-confirm="Make this student active again?">Re-admit (make active)</button></form>
    <?php endif; ?>
  <?php endif; ?>
  <?php if (can('students.delete')): ?>
    <form method="post" class="card card-body mt-3 border-danger"><?= csrf_field() ?><input type="hidden" name="act" value="delete_student"><div class="small text-muted mb-2">Delete permanently. Only possible if no fee receipts exist (for wrong entries).</div><button class="btn btn-outline-danger btn-sm" data-confirm="Permanently delete this student and all their records?">Delete student</button></form>
  <?php endif; ?>
  </div></div>
<?php endif; ?>

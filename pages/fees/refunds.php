<?php
require_perm('fees.refund');
$title = 'Refunds';
if (is_post()) {
    require_perm('fees.refund');
    try {
        $sid = (int)val('SELECT id FROM students WHERE admission_no = ?', [post('admission_no')]);
        if (!$sid) throw new RuntimeException('No student with that admission number.');
        $amt = (float)post('amount');
        if ($amt <= 0) throw new RuntimeException('Enter the refund amount.');
        if (!post('reason')) throw new RuntimeException('Enter the reason.');
        $rid = null;
        if (post('receipt_no') !== '') {
            $rc = row('SELECT * FROM receipts WHERE receipt_no = ? AND student_id = ? AND cancelled = 0', [post('receipt_no'), $sid]);
            if (!$rc) throw new RuntimeException('Receipt not found for this student.');
            $already = (float)val('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE receipt_id = ?', [$rc['id']]);
            if ($amt + $already > (float)$rc['total_amount'] + 0.001) throw new RuntimeException('Refund is more than the receipt amount (' . money((float)$rc['total_amount'] - $already) . ' left).');
            $rid = (int)$rc['id'];
        }
        db()->beginTransaction();
        $yr = current_year();
        $no = 'RF/' . ($yr['name'] ?? date('Y')) . '/' . str_pad((string)next_number('refund'), 4, '0', STR_PAD_LEFT);
        $id = insert('refunds', ['refund_no' => $no, 'student_id' => $sid, 'receipt_id' => $rid, 'refund_date' => post('refund_date') ?: today(),
            'amount' => $amt, 'mode' => post('mode'), 'reference' => nullif(post('reference')), 'reason' => post('reason'), 'created_by' => $_SESSION['uid']]);
        db()->commit();
        audit('refund.create', 'refund', $id, ['no' => $no, 'amount' => $amt, 'reason' => post('reason')]);
        flash('ok', "Refund $no recorded.");
    } catch (RuntimeException $e) { if (db()->inTransaction()) db()->rollBack(); flash('err', $e->getMessage()); }
    redirect('fees/refunds');
}
$list = rows('SELECT rf.*, s.admission_no, s.first_name, s.last_name, r.receipt_no, u.name AS by_name FROM refunds rf JOIN students s ON s.id = rf.student_id
              LEFT JOIN receipts r ON r.id = rf.receipt_id LEFT JOIN users u ON u.id = rf.created_by ORDER BY rf.id DESC LIMIT 200');
?>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Date</th><th>Refund No</th><th>Student</th><th>Against receipt</th><th>Reason</th><th>Mode</th><th class="text-end">Amount</th><th>By</th></tr></thead><tbody>
<?php foreach ($list as $r): ?><tr><td><?= dmy($r['refund_date']) ?></td><td><?= h($r['refund_no']) ?></td><td><a href="<?= url('students/view', ['id' => $r['student_id'], 'tab' => 'receipts']) ?>"><?= h(student_name($r)) ?></a> <span class="small text-muted"><?= h($r['admission_no']) ?></span></td>
<td><?= h($r['receipt_no']) ?></td><td class="small"><?= h($r['reason']) ?></td><td><?= h($r['mode']) ?></td><td class="amount"><?= money($r['amount']) ?></td><td class="small"><?= h($r['by_name']) ?></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="8" class="text-center text-muted py-4">No refunds.</td></tr><?php endif; ?>
</tbody></table></div></div>
<p class="small text-muted mt-2">Use refunds for money paid back to parents (caution deposit, excess payment, withdrawn admission). To undo a wrong receipt, cancel the receipt instead.</p></div>
<?php if (can('fees.refund')): ?>
<div class="col-lg-4"><form method="post" class="card"><div class="card-header">Record a refund</div><div class="card-body">
<?= csrf_field() ?>
<div class="mb-2"><label class="form-label req">Admission No</label><input name="admission_no" class="form-control" required></div>
<div class="mb-2"><label class="form-label">Against receipt no (optional)</label><input name="receipt_no" class="form-control"></div>
<div class="row g-2 mb-2"><div class="col"><label class="form-label req">Amount ₹</label><input name="amount" type="number" step="0.01" min="1" class="form-control" required></div>
<div class="col"><label class="form-label">Date</label><input name="refund_date" type="date" class="form-control" value="<?= today() ?>"></div></div>
<div class="row g-2 mb-2"><div class="col"><label class="form-label">Mode</label><select name="mode" class="form-select"><?php foreach (payment_modes() as $m): ?><option><?= h($m) ?></option><?php endforeach; ?></select></div>
<div class="col"><label class="form-label">Reference</label><input name="reference" class="form-control"></div></div>
<div class="mb-3"><label class="form-label req">Reason</label><input name="reason" class="form-control" required placeholder="Caution deposit returned on TC"></div>
<button class="btn btn-primary" data-confirm="Record this refund?">Save refund</button>
</div></form></div>
<?php endif; ?>
</div>

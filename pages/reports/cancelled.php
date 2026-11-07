<?php
require_perm('reports');
$title = 'Cancelled Receipts';
$from = get('from', date('Y-m-d', strtotime('-90 days'))); $to = get('to', today());
$list = rows('SELECT r.*, s.admission_no, s.first_name, s.last_name, u.name AS by_name, cu.name AS cancelled_name FROM receipts r JOIN students s ON s.id = r.student_id JOIN enrollments e ON e.id = r.enrollment_id
              LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users cu ON cu.id = r.cancelled_by
              WHERE r.cancelled = 1 AND DATE(r.cancelled_at) BETWEEN ? AND ?' . scope_sql('e') . ' ORDER BY r.cancelled_at DESC', [$from, $to]);
?>
<form class="card card-body mb-3"><input type="hidden" name="p" value="reports/cancelled"><div class="row g-2 align-items-end">
<div class="col-6 col-md-3"><label class="form-label">Cancelled from</label><input type="date" name="from" class="form-control" value="<?= h($from) ?>"></div>
<div class="col-6 col-md-3"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($to) ?>"></div>
<div class="col-md-2"><button class="btn btn-primary">Show</button></div></div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Receipt</th><th>Receipt date</th><th>Student</th><th class="text-end">Amount</th><th>Collected by</th><th>Cancelled by</th><th>When</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($list as $r): ?><tr><td><a target="_blank" href="<?= url('fees/receipt', ['id' => $r['id']]) ?>"><?= h($r['receipt_no']) ?></a></td><td><?= dmy($r['receipt_date']) ?></td><td><?= h(student_name($r)) ?> <span class="small text-muted"><?= h($r['admission_no']) ?></span></td>
<td class="amount"><?= money($r['total_amount']) ?></td><td><?= h($r['by_name']) ?></td><td><?= h($r['cancelled_name']) ?></td><td class="small"><?= h(date('d-m-Y H:i', strtotime($r['cancelled_at']))) ?></td><td class="small"><?= h($r['cancel_reason']) ?></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="8" class="text-center text-muted py-4">No cancelled receipts.</td></tr><?php endif; ?>
</tbody></table></div></div>

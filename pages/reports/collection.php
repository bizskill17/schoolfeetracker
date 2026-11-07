<?php
require_perm('reports');
$title = 'Collection Report';
$from = get('from', today()); $to = get('to', today());
$view = get('view', 'detail');
$p = [$from, $to];
$modes = payment_modes();
$byMode = rows('SELECT mode, COUNT(*) AS n, SUM(fee_amount) AS fee, SUM(late_fee) AS late, SUM(total_amount) AS amt FROM ' . receipts_src() . ' WHERE cancelled = 0 AND receipt_date BETWEEN ? AND ? GROUP BY mode ORDER BY amt DESC', $p);
$cashModes = array_values(array_filter(array_column(lookup_values('payment_mode', false), 'value'), fn($m) => lookup_flag('payment_mode', $m, 'cash'))) ?: ['__none__'];
$cin = implode(',', array_fill(0, count($cashModes), '?'));
$byUser = rows("SELECT u.name, COUNT(*) AS n, SUM(r.total_amount) AS amt, SUM(CASE WHEN r.mode IN ($cin) THEN r.total_amount ELSE 0 END) AS cash
                FROM " . receipts_src('r') . " LEFT JOIN users u ON u.id = r.created_by WHERE r.cancelled = 0 AND r.receipt_date BETWEEN ? AND ? GROUP BY r.created_by ORDER BY amt DESC", array_merge($cashModes, $p));
$refunds = section_scope() === null ? (float)val('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE refund_date BETWEEN ? AND ?', $p) : 0.0;
$cancelled = row('SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS amt FROM ' . receipts_src() . ' WHERE cancelled = 1 AND receipt_date BETWEEN ? AND ?', $p);
$gross = array_sum(array_column($byMode, 'amt'));
$data = [];
if ($view === 'daily') {
    $data = rows("SELECT receipt_date, COUNT(*) AS n, " . implode(', ', array_map(fn($i) => "SUM(CASE WHEN mode = ? THEN total_amount ELSE 0 END) AS m$i", array_keys($modes))) .
        ", SUM(late_fee) AS late, SUM(total_amount) AS amt FROM " . receipts_src() . " WHERE cancelled = 0 AND receipt_date BETWEEN ? AND ? GROUP BY receipt_date ORDER BY receipt_date", array_merge($modes, $p));
} else {
    $data = rows('SELECT r.*, s.admission_no, s.first_name, s.last_name, c.name AS class_name, sec.name AS section_name, u.name AS by_name
                  FROM receipts r JOIN students s ON s.id = r.student_id JOIN enrollments e ON e.id = r.enrollment_id JOIN classes c ON c.id = e.class_id
                  LEFT JOIN sections sec ON sec.id = e.section_id LEFT JOIN users u ON u.id = r.created_by
                  WHERE r.cancelled = 0 AND r.receipt_date BETWEEN ? AND ?' . scope_sql('e') . ' ORDER BY r.receipt_date, r.id', $p);
}
if (get('export') === 'csv') {
    if ($view === 'daily') {
        csv_download("collection-daily-$from-$to.csv", array_merge(['Date', 'Receipts'], $modes, ['Late Fee', 'Total']),
            array_map(fn($r) => array_merge([dmy($r['receipt_date']), $r['n']], array_map(fn($i) => $r["m$i"], array_keys($modes)), [$r['late'], $r['amt']]), $data));
    }
    csv_download("collection-$from-$to.csv", ['Date', 'Receipt No', 'Adm No', 'Student', 'Class', 'Mode', 'Reference', 'Fee', 'Late Fee', 'Total', 'Received By'],
        array_map(fn($r) => [dmy($r['receipt_date']), $r['receipt_no'], $r['admission_no'], student_name($r), class_label($r), $r['mode'], $r['reference'], $r['fee_amount'], $r['late_fee'], $r['total_amount'], $r['by_name']], $data));
}
$qs = $_GET;
?>
<form class="card card-body mb-3 no-print"><input type="hidden" name="p" value="reports/collection">
<div class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= h($from) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($to) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label">View</label><select name="view" class="form-select"><option value="detail">Receipt list</option><option value="daily" <?= $view === 'daily' ? 'selected' : '' ?>>Day-wise totals</option></select></div>
  <div class="col-6 col-md-6 d-flex gap-1 flex-wrap">
    <button class="btn btn-primary">Show</button>
    <a class="btn btn-light" href="<?= url('reports/collection') ?>">Today</a>
    <a class="btn btn-light" href="<?= url('reports/collection', ['from' => date('Y-m-01'), 'to' => today(), 'view' => 'daily']) ?>">This month</a>
    <a class="btn btn-outline-secondary" href="index.php?<?= h(http_build_query($qs + ['export' => 'csv'])) ?>"><i class="bi bi-download"></i> CSV</a>
    <button type="button" class="btn btn-outline-secondary" onclick="print()"><i class="bi bi-printer"></i></button></div>
</div></form>
<h6 class="d-none d-print-block"><?= h(setting('school_name')) ?> – Collection <?= dmy($from) ?> to <?= dmy($to) ?></h6>
<div class="row g-3 mb-3">
<?= card_stat('Collected', money($gross), 'cash-coin', 'success', array_sum(array_column($byMode, 'n')) . ' receipts') ?>
<?= card_stat('Refunds paid', money($refunds), 'arrow-counterclockwise', 'warning') ?>
<?= card_stat('Net', money($gross - $refunds), 'wallet2', 'primary') ?>
<?= card_stat('Cancelled', money($cancelled['amt']), 'x-octagon', 'secondary', $cancelled['n'] . ' receipts (not counted)') ?>
</div>
<div class="row g-3 mb-3">
<div class="col-md-6"><div class="card h-100"><div class="card-header">By payment mode</div><table class="table table-sm mb-0"><thead><tr><th>Mode</th><th>Receipts</th><th class="text-end">Fee</th><th class="text-end">Late fee</th><th class="text-end">Total</th></tr></thead><tbody>
<?php foreach ($byMode as $m): ?><tr><td><?= h($m['mode']) ?></td><td><?= (int)$m['n'] ?></td><td class="amount"><?= money($m['fee']) ?></td><td class="amount"><?= money($m['late']) ?></td><td class="amount fw-semibold"><?= money($m['amt']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="col-md-6"><div class="card h-100"><div class="card-header">By staff (cash to hand over)</div><table class="table table-sm mb-0"><thead><tr><th>Received by</th><th>Receipts</th><th class="text-end">Cash</th><th class="text-end">Total</th></tr></thead><tbody>
<?php foreach ($byUser as $u): ?><tr><td><?= h($u['name']) ?></td><td><?= (int)$u['n'] ?></td><td class="amount"><?= money($u['cash']) ?></td><td class="amount fw-semibold"><?= money($u['amt']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
</div>
<div class="card"><div class="table-responsive">
<?php if ($view === 'daily'): ?>
<table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Receipts</th><?php foreach ($modes as $m): ?><th class="text-end"><?= h($m) ?></th><?php endforeach; ?><th class="text-end">Late fee</th><th class="text-end">Total</th></tr></thead><tbody>
<?php foreach ($data as $r): ?><tr><td><a href="<?= url('reports/collection', ['from' => $r['receipt_date'], 'to' => $r['receipt_date']]) ?>"><?= dmy($r['receipt_date']) ?></a></td><td><?= (int)$r['n'] ?></td>
<?php foreach ($modes as $i => $m): ?><td class="amount"><?= (float)$r["m$i"] ? money($r["m$i"]) : '' ?></td><?php endforeach; ?><td class="amount"><?= money($r['late']) ?></td><td class="amount fw-semibold"><?= money($r['amt']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php else: ?>
<table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Receipt</th><th>Student</th><th>Class</th><th>Mode</th><th class="text-end">Fee</th><th class="text-end">Late</th><th class="text-end">Total</th><th>By</th></tr></thead><tbody>
<?php foreach ($data as $r): ?><tr><td><?= dmy($r['receipt_date']) ?></td><td><a href="<?= url('fees/receipt', ['id' => $r['id']]) ?>" target="_blank"><?= h($r['receipt_no']) ?></a></td><td><?= h(student_name($r)) ?> <span class="small text-muted"><?= h($r['admission_no']) ?></span></td>
<td><?= h(class_label($r)) ?></td><td><?= h($r['mode']) ?> <span class="small text-muted"><?= h($r['reference']) ?></span></td><td class="amount"><?= money($r['fee_amount']) ?></td><td class="amount"><?= (float)$r['late_fee'] ? money($r['late_fee']) : '' ?></td><td class="amount fw-semibold"><?= money($r['total_amount']) ?></td><td class="small"><?= h($r['by_name']) ?></td></tr><?php endforeach; ?>
<?php if (!$data): ?><tr><td colspan="9" class="text-center text-muted py-4">No collection in this period.</td></tr><?php endif; ?>
</tbody></table>
<?php endif; ?>
</div></div>

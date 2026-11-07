<?php
require_perm('reports');
$title = 'Fee Head-wise Collection';
$from = get('from', date('Y-m-01')); $to = get('to', today());
$list = rows("SELECT COALESCE(fh.name, 'Other') AS head, ri.item_type, COUNT(DISTINCT r.id) AS n, SUM(ri.amount) AS amt, SUM(ri.late_fee_waived) AS waived
              FROM receipt_items ri JOIN receipts r ON r.id = ri.receipt_id JOIN enrollments e ON e.id = r.enrollment_id LEFT JOIN fee_heads fh ON fh.id = ri.fee_head_id
              WHERE r.cancelled = 0 AND r.receipt_date BETWEEN ? AND ?" . scope_sql('e') . " GROUP BY head, ri.item_type ORDER BY ri.item_type, MIN(fh.sort_order)", [$from, $to]);
$fee = array_filter($list, fn($r) => $r['item_type'] === 'fee');
$late = array_filter($list, fn($r) => $r['item_type'] === 'late_fee');
$tot = array_sum(array_column($list, 'amt'));
if (get('export') === 'csv') csv_download("headwise-$from-$to.csv", ['Fee Head', 'Type', 'Receipts', 'Amount', 'Late fee waived'], array_map(fn($r) => [$r['head'], $r['item_type'] === 'fee' ? 'Fee' : 'Late fee', $r['n'], $r['amt'], $r['waived']], $list));
?>
<form class="card card-body mb-3 no-print"><input type="hidden" name="p" value="reports/headwise"><div class="row g-2 align-items-end">
<div class="col-6 col-md-3"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= h($from) ?>"></div>
<div class="col-6 col-md-3"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($to) ?>"></div>
<div class="col-md-6 d-flex gap-1"><button class="btn btn-primary">Show</button><a class="btn btn-outline-secondary" href="index.php?<?= h(http_build_query($_GET + ['export' => 'csv'])) ?>"><i class="bi bi-download"></i> CSV</a><button type="button" class="btn btn-outline-secondary" onclick="print()"><i class="bi bi-printer"></i></button></div>
</div></form>
<div class="card" style="max-width:760px"><table class="table mb-0"><thead><tr><th>Fee head</th><th>Receipts</th><th class="text-end">Collected</th><th class="text-end">Share</th></tr></thead><tbody>
<?php foreach ($fee as $r): ?><tr><td><?= h($r['head']) ?></td><td><?= (int)$r['n'] ?></td><td class="amount"><?= money($r['amt']) ?></td><td class="amount text-muted"><?= $tot ? round($r['amt'] / $tot * 100, 1) : 0 ?>%</td></tr><?php endforeach; ?>
<?php $lateAmt = array_sum(array_column($late, 'amt')); $lateW = array_sum(array_column($late, 'waived')); if ($lateAmt || $lateW): ?>
<tr><td>Late fee <?= $lateW ? '<span class="small text-muted">(' . money($lateW) . ' waived)</span>' : '' ?></td><td><?= array_sum(array_column($late, 'n')) ?></td><td class="amount"><?= money($lateAmt) ?></td><td class="amount text-muted"><?= $tot ? round($lateAmt / $tot * 100, 1) : 0 ?>%</td></tr><?php endif; ?>
</tbody><tfoot><tr class="fw-bold"><td colspan="2">Total</td><td class="amount"><?= money($tot) ?></td><td></td></tr></tfoot></table></div>

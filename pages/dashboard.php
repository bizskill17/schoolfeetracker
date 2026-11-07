<?php
require_perm('students.view');
$title = 'Dashboard';
$year = working_year();
if (!$year) {
    echo '<div class="alert alert-info">Welcome! Start by adding an academic year in <a href="' . url('settings/years') . '">Settings → Academic Years</a>.</div>';
    return;
}
$yid = (int)$year['id'];
$students = (int)val("SELECT COUNT(*) FROM enrollments e JOIN students s ON s.id = e.student_id WHERE e.academic_year_id = ? AND e.status <> 'left' AND s.status = 'active'" . scope_sql('e'), [$yid]);
$newAdm = (int)val("SELECT COUNT(*) FROM enrollments e WHERE e.academic_year_id = ? AND e.is_new = 1 AND e.status <> 'left'" . scope_sql('e'), [$yid]);
$today = row('SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS amt FROM ' . receipts_src() . ' WHERE cancelled = 0 AND receipt_date = CURDATE()');
$month = (float)val("SELECT COALESCE(SUM(total_amount),0) FROM " . receipts_src() . " WHERE cancelled = 0 AND receipt_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$yearColl = (float)val('SELECT COALESCE(SUM(total_amount),0) FROM ' . receipts_src() . ' WHERE cancelled = 0 AND academic_year_id = ?', [$yid]);
$showFees = can('fees.view');
if ($showFees) {
    $dues = dues_rows($yid, today());
    $dueTotal = array_sum(array_column($dues, 'payable_now'));
    $dueCount = count(array_filter($dues, fn($r) => $r['payable_now'] > 0));
    $yearFee = array_sum(array_column($dues, 'total'));
    $byClass = [];
    foreach ($dues as $d) {
        $k = $d['class_name'];
        $byClass[$k] = $byClass[$k] ?? ['due' => 0, 'n' => 0, 'sort' => $d['sort_order']];
        $byClass[$k]['due'] += $d['payable_now'];
        if ($d['payable_now'] > 0) $byClass[$k]['n']++;
    }
    uasort($byClass, fn($a, $b) => $a['sort'] <=> $b['sort']);
    $modesToday = rows('SELECT mode, COUNT(*) AS n, SUM(total_amount) AS amt FROM ' . receipts_src() . ' WHERE cancelled = 0 AND receipt_date = CURDATE() GROUP BY mode ORDER BY amt DESC');
    $months = rows("SELECT DATE_FORMAT(receipt_date, '%Y-%m') AS ym, SUM(total_amount) AS amt FROM " . receipts_src() . " WHERE cancelled = 0 AND academic_year_id = ? GROUP BY ym ORDER BY ym", [$yid]);
    $maxM = max(array_merge([1], array_map(fn($m) => (float)$m['amt'], $months)));
    $recent = rows('SELECT r.*, s.first_name, s.last_name FROM ' . receipts_src('r') . ' JOIN students s ON s.id = r.student_id ORDER BY r.id DESC LIMIT 8');
}
?>
<div class="row g-3 mb-3">
<?= card_stat('Students (' . $year['name'] . ')', (string)$students, 'people', 'primary', $newAdm . ' new admissions') ?>
<?php if ($showFees): ?>
<?= card_stat("Today's collection", money($today['amt']), 'cash-coin', 'success', $today['n'] . ' receipts') ?>
<?= card_stat('This month', money($month), 'calendar-month', 'info', 'Year so far ' . money($yearColl)) ?>
<?= card_stat('Overdue now', money($dueTotal), 'exclamation-triangle', 'danger', $dueCount . ' students') ?>
<?php endif; ?>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
<?php if (can('fees.collect')): ?><a href="<?= url('fees/collect') ?>" class="btn btn-success"><i class="bi bi-cash-stack"></i> Collect fee</a><?php endif; ?>
<?php if (can('students.edit')): ?><a href="<?= url('students/form') ?>" class="btn btn-outline-primary"><i class="bi bi-person-plus"></i> New admission</a><?php endif; ?>
<?php if ($showFees): ?><a href="<?= url('fees/dues') ?>" class="btn btn-outline-danger"><i class="bi bi-exclamation-diamond"></i> Dues list</a><?php endif; ?>
<?php if (can('reports')): ?><a href="<?= url('reports/collection') ?>" class="btn btn-outline-secondary"><i class="bi bi-calendar-check"></i> Today's report</a><?php endif; ?>
</div>

<?php if ($showFees): ?>
<div class="row g-3">
<div class="col-lg-7">
  <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Collection by month</span><span class="small text-muted">Year fee <?= money($yearFee) ?> · collected <?= $yearFee > 0 ? round($yearColl / $yearFee * 100) : 0 ?>%</span></div><div class="card-body">
  <?php foreach ($months as $m): ?>
    <div class="d-flex align-items-center gap-2 mb-1"><div class="small text-muted" style="width:70px"><?= h(date('M Y', strtotime($m['ym'] . '-01'))) ?></div>
    <div class="flex-fill"><div class="bg-primary rounded" style="height:16px;width:<?= max(1, round((float)$m['amt'] / $maxM * 100)) ?>%"></div></div><div class="small amount" style="width:100px"><?= money($m['amt']) ?></div></div>
  <?php endforeach; if (!$months): ?><div class="text-muted small">No collections yet this year.</div><?php endif; ?>
  </div></div>
  <div class="card"><div class="card-header">Recent receipts</div><div class="table-responsive"><table class="table table-sm mb-0"><tbody>
  <?php foreach ($recent as $r): ?><tr class="<?= $r['cancelled'] ? 'text-muted text-decoration-line-through' : '' ?>"><td class="small"><?= dmy($r['receipt_date']) ?></td><td><a target="_blank" href="<?= url('fees/receipt', ['id' => $r['id']]) ?>"><?= h($r['receipt_no']) ?></a></td><td><?= h(student_name($r)) ?></td><td class="small"><?= h($r['mode']) ?></td><td class="amount"><?= money($r['total_amount']) ?></td></tr><?php endforeach; ?>
  <?php if (!$recent): ?><tr><td class="text-muted small">No receipts yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<div class="col-lg-5">
  <div class="card mb-3"><div class="card-header">Today by payment mode</div><table class="table table-sm mb-0"><tbody>
  <?php foreach ($modesToday as $m): ?><tr><td><?= h($m['mode']) ?></td><td class="small text-muted"><?= (int)$m['n'] ?> receipts</td><td class="amount"><?= money($m['amt']) ?></td></tr><?php endforeach; ?>
  <?php if (!$modesToday): ?><tr><td class="text-muted small">Nothing collected today yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card"><div class="card-header">Overdue by class</div><table class="table table-sm mb-0"><thead><tr><th>Class</th><th>Students</th><th class="text-end">Overdue</th></tr></thead><tbody>
  <?php foreach ($byClass as $c => $v): if ($v['due'] <= 0) continue; ?><tr><td><a href="<?= url('fees/dues', ['class_id' => val('SELECT id FROM classes WHERE name = ?', [$c])]) ?>"><?= h($c) ?></a></td><td><?= $v['n'] ?></td><td class="amount text-danger"><?= money($v['due']) ?></td></tr><?php endforeach; ?>
  <?php if (!$dueTotal): ?><tr><td colspan="3" class="text-muted small">No overdue fees.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
</div>
<?php endif; ?>

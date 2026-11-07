<?php
require_perm('reports');
$title = 'Class-wise Fee Summary';
$year = working_year();
$asOf = get('as_of', today());
$rows = dues_rows((int)$year['id'], $asOf);
$by = [];
foreach ($rows as $r) {
    $k = class_label($r);
    $by[$k] = $by[$k] ?? ['sort' => $r['sort_order'] * 100 + ord((string)($r['section_name'] ?? ' ')), 'n' => 0, 'total' => 0, 'conc' => 0, 'paid' => 0, 'due' => 0, 'late' => 0, 'bal' => 0, 'def' => 0];
    $b = &$by[$k];
    $b['n']++; $b['total'] += $r['total']; $b['conc'] += $r['concession']; $b['paid'] += $r['paid']; $b['due'] += $r['due_now']; $b['late'] += $r['late_fee']; $b['bal'] += $r['balance'];
    if ($r['payable_now'] > 0) $b['def']++;
    unset($b);
}
uasort($by, fn($a, $b) => $a['sort'] <=> $b['sort']);
$T = ['n' => 0, 'total' => 0, 'conc' => 0, 'paid' => 0, 'due' => 0, 'late' => 0, 'bal' => 0, 'def' => 0];
foreach ($by as $b) foreach ($T as $k => $v) $T[$k] += $b[$k];
if (get('export') === 'csv') csv_download('classwise-' . $year['name'] . '.csv', ['Class', 'Students', 'Year Fee (net)', 'Concession', 'Collected', 'Overdue', 'Late Fee', 'Year Balance', 'Students with dues'],
    array_map(fn($k, $b) => [$k, $b['n'], $b['total'], $b['conc'], $b['paid'], $b['due'], $b['late'], $b['bal'], $b['def']], array_keys($by), $by));
?>
<form class="card card-body mb-3 no-print"><input type="hidden" name="p" value="reports/classwise"><div class="row g-2 align-items-end">
<div class="col-md-3"><label class="form-label">Dues as of</label><input type="date" name="as_of" class="form-control" value="<?= h($asOf) ?>"></div>
<div class="col-md-9 d-flex gap-1"><button class="btn btn-primary">Show</button><a class="btn btn-outline-secondary" href="index.php?<?= h(http_build_query($_GET + ['export' => 'csv'])) ?>"><i class="bi bi-download"></i> CSV</a><button type="button" class="btn btn-outline-secondary" onclick="print()"><i class="bi bi-printer"></i></button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
<thead><tr><th>Class</th><th>Students</th><th class="text-end">Year fee (net)</th><th class="text-end">Concession</th><th class="text-end">Collected</th><th>% of year</th><th class="text-end">Overdue</th><th class="text-end">Late fee</th><th class="text-end">Year balance</th><th>With dues</th></tr></thead><tbody>
<?php
$classTot = []; $lastClass = null;
foreach ($rows as $r) { $c = $r['class_name']; $classTot[$c] = $classTot[$c] ?? ['n' => 0, 'total' => 0, 'conc' => 0, 'paid' => 0, 'due' => 0, 'late' => 0, 'bal' => 0, 'def' => 0, 'secs' => 0];
  $t = &$classTot[$c]; $t['n']++; $t['total'] += $r['total']; $t['conc'] += $r['concession']; $t['paid'] += $r['paid']; $t['due'] += $r['due_now']; $t['late'] += $r['late_fee']; $t['bal'] += $r['balance']; if ($r['payable_now'] > 0) $t['def']++; unset($t); }
foreach ($by as $k => $b):
  $cName = explode(' - ', $k)[0];
  if ($cName !== $lastClass && isset($classTot[$cName]) && count(array_filter(array_keys($by), fn($x) => explode(' - ', $x)[0] === $cName)) > 1):
    $t = $classTot[$cName]; $tp = $t['total'] > 0 ? round($t['paid'] / $t['total'] * 100) : 0; ?>
<tr class="table-light fw-bold"><td><?= h($cName) ?> (all sections)</td><td><?= $t['n'] ?></td><td class="amount"><?= money($t['total']) ?></td><td class="amount"><?= money($t['conc']) ?></td><td class="amount"><?= money($t['paid']) ?></td><td><?= $tp ?>%</td><td class="amount text-danger"><?= money($t['due']) ?></td><td class="amount"><?= money($t['late']) ?></td><td class="amount"><?= money($t['bal']) ?></td><td><?= $t['def'] ?></td></tr>
<?php endif; $lastClass = $cName; $pct = $b['total'] > 0 ? round($b['paid'] / $b['total'] * 100) : 0; ?>
<tr><td class="ps-3"><?= h($k) ?></td><td><?= $b['n'] ?></td><td class="amount"><?= money($b['total']) ?></td><td class="amount text-muted"><?= money($b['conc']) ?></td><td class="amount"><?= money($b['paid']) ?></td>
<td style="min-width:110px"><div class="progress" style="height:8px"><div class="progress-bar bg-success" style="width:<?= min(100, $pct) ?>%"></div></div><span class="small"><?= $pct ?>%</span></td>
<td class="amount text-danger"><?= money($b['due']) ?></td><td class="amount"><?= money($b['late']) ?></td><td class="amount"><?= money($b['bal']) ?></td><td><?= $b['def'] ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr class="fw-bold"><td>Total</td><td><?= $T['n'] ?></td><td class="amount"><?= money($T['total']) ?></td><td class="amount"><?= money($T['conc']) ?></td><td class="amount"><?= money($T['paid']) ?></td><td><?= $T['total'] > 0 ? round($T['paid'] / $T['total'] * 100) : 0 ?>%</td><td class="amount"><?= money($T['due']) ?></td><td class="amount"><?= money($T['late']) ?></td><td class="amount"><?= money($T['bal']) ?></td><td><?= $T['def'] ?></td></tr></tfoot>
</table></div></div>
<p class="small text-muted mt-2">Year fee is after concessions and waivers. Collected excludes late fees. Previous year dues carried forward are included in the year in which they are now payable.</p>

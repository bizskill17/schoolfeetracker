<?php
require_perm('reports');
$title = 'Student Strength';
$year = working_year();
// Gender and category columns come from Masters → Dropdown Lists, so any new value shows up here.
$genders = lookup_names('gender'); $cats = lookup_names('category');
$dyn = []; $p = [];
foreach ($genders as $i => $g) { $dyn[] = "SUM(s.gender = ?) AS g$i"; $p[] = $g; }
foreach ($cats as $i => $c) { $dyn[] = "SUM(s.category = ?) AS c$i"; $p[] = $c; }
$p[] = $year['id'];
$list = rows("SELECT c.name AS class_name, c.sort_order, sec.name AS section_name, COUNT(*) AS total, SUM(e.is_new = 1) AS new_adm, SUM(s.is_rte = 1) AS rte,
                     SUM(e.transport_route_id IS NOT NULL) AS transport" . ($dyn ? ', ' . implode(', ', $dyn) : '') . "
              FROM enrollments e JOIN students s ON s.id = e.student_id JOIN classes c ON c.id = e.class_id LEFT JOIN sections sec ON sec.id = e.section_id
              WHERE e.academic_year_id = ? AND e.status <> 'left' AND s.status = 'active'" . scope_sql('e') . "
              GROUP BY c.id, sec.id ORDER BY c.sort_order, sec.name", $p);
$cols = ['total' => 'Total'];
foreach ($genders as $i => $g) $cols["g$i"] = $g;
$cols += ['new_adm' => 'New', 'rte' => 'RTE', 'transport' => 'Transport'];
foreach ($cats as $i => $c) $cols["c$i"] = $c;
$T = array_fill_keys(array_keys($cols), 0);
foreach ($list as $r) foreach ($T as $k => $v) $T[$k] += (int)$r[$k];
$left = (int)val("SELECT COUNT(*) FROM enrollments WHERE academic_year_id = ? AND status = 'left'", [$year['id']]);
if (get('export') === 'csv') csv_download('strength-' . $year['name'] . '.csv', array_merge(['Class'], array_values($cols)), array_map(fn($r) => array_merge([class_label($r)], array_map(fn($k) => $r[$k], array_keys($cols))), $list));
?>
<div class="d-flex gap-2 mb-3 no-print"><a class="btn btn-outline-secondary btn-sm" href="index.php?<?= h(http_build_query($_GET + ['export' => 'csv'])) ?>"><i class="bi bi-download"></i> CSV</a><button class="btn btn-outline-secondary btn-sm" onclick="print()"><i class="bi bi-printer"></i> Print</button><span class="small text-muted ms-auto">Left during <?= h($year['name']) ?>: <?= $left ?></span></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Class</th><?php foreach ($cols as $l): ?><th class="text-end"><?= $l ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($list as $r): ?><tr><td><?= h(class_label($r)) ?></td><?php foreach ($cols as $k => $l): ?><td class="text-end <?= $k === 'total' ? 'fw-semibold' : '' ?>"><?= (int)$r[$k] ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody><tfoot><tr class="fw-bold"><td>Total</td><?php foreach ($T as $v): ?><td class="text-end"><?= $v ?></td><?php endforeach; ?></tr></tfoot></table></div></div>

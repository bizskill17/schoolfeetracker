<?php
require_perm('reports');
$title = 'Concessions & Waivers';
$year = working_year();
$list = rows("SELECT e.id, s.id AS student_id, s.admission_no, s.first_name, s.last_name, s.is_rte, c.name AS class_name, sec.name AS section_name, c.sort_order,
                     SUM(d.concession) AS conc, SUM(d.waived) AS waived, SUM(d.late_fee_waived) AS late_waived,
                     (SELECT GROUP_CONCAT(CONCAT(sc.reason, ' (', IF(sc.kind='percent', CONCAT(sc.value+0,'%'), CONCAT('₹', sc.value+0)), ')') SEPARATOR '; ') FROM student_concessions sc WHERE sc.enrollment_id = e.id) AS reasons
              FROM enrollments e JOIN students s ON s.id = e.student_id JOIN classes c ON c.id = e.class_id LEFT JOIN sections sec ON sec.id = e.section_id
              JOIN fee_demands d ON d.enrollment_id = e.id
              WHERE e.academic_year_id = ?" . scope_sql('e') . " GROUP BY e.id HAVING conc > 0 OR waived > 0 OR late_waived > 0 ORDER BY c.sort_order, s.first_name", [$year['id']]);
$byType = rows('SELECT COALESCE(ct.name, sc.reason) AS name, COUNT(DISTINCT sc.enrollment_id) AS n FROM student_concessions sc JOIN enrollments e ON e.id = sc.enrollment_id
                LEFT JOIN concession_types ct ON ct.id = sc.concession_type_id WHERE e.academic_year_id = ? GROUP BY name ORDER BY n DESC', [$year['id']]);
$T = ['conc' => array_sum(array_column($list, 'conc')), 'waived' => array_sum(array_column($list, 'waived')), 'late' => array_sum(array_column($list, 'late_waived'))];
if (get('export') === 'csv') csv_download('concessions-' . $year['name'] . '.csv', ['Adm No', 'Student', 'Class', 'RTE', 'Concession', 'Fee Waived', 'Late Fee Waived', 'Reasons'],
    array_map(fn($r) => [$r['admission_no'], student_name($r), class_label($r), $r['is_rte'] ? 'Yes' : '', $r['conc'], $r['waived'], $r['late_waived'], $r['reasons']], $list));
?>
<div class="d-flex gap-2 mb-3 no-print"><a class="btn btn-outline-secondary btn-sm" href="index.php?<?= h(http_build_query($_GET + ['export' => 'csv'])) ?>"><i class="bi bi-download"></i> CSV</a><button class="btn btn-outline-secondary btn-sm" onclick="print()"><i class="bi bi-printer"></i> Print</button></div>
<div class="row g-3 mb-3">
<?= card_stat('Concessions given', money($T['conc']), 'percent', 'primary', count($list) . ' students') ?>
<?= card_stat('Fees waived', money($T['waived']), 'scissors', 'warning') ?>
<?= card_stat('Late fee waived', money($T['late']), 'alarm', 'secondary') ?>
<?= card_stat('Total given up', money($T['conc'] + $T['waived'] + $T['late']), 'graph-down', 'danger', $year['name']) ?>
</div>
<?php if ($byType): ?><div class="mb-3 small"><?php foreach ($byType as $t): ?><span class="badge text-bg-light border me-1"><?= h($t['name']) ?>: <?= (int)$t['n'] ?></span><?php endforeach; ?></div><?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>Adm No</th><th>Student</th><th>Class</th><th class="text-end">Concession</th><th class="text-end">Fee waived</th><th class="text-end">Late fee waived</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($list as $r): ?><tr><td><?= h($r['admission_no']) ?></td><td><a href="<?= url('students/view', ['id' => $r['student_id']]) ?>"><?= h(student_name($r)) ?></a> <?= $r['is_rte'] ? '<span class="badge text-bg-info">RTE</span>' : '' ?></td><td><?= h(class_label($r)) ?></td>
<td class="amount"><?= money($r['conc']) ?></td><td class="amount"><?= (float)$r['waived'] ? money($r['waived']) : '' ?></td><td class="amount"><?= (float)$r['late_waived'] ? money($r['late_waived']) : '' ?></td><td class="small"><?= h($r['reasons'] ?? ($r['is_rte'] ? 'RTE' : '')) ?></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-4">No concessions or waivers this year.</td></tr><?php endif; ?>
</tbody></table></div></div>
<p class="small text-muted mt-2">Each waiver is also in the <a href="<?= url('settings/audit', ['action' => 'demand']) ?>">Activity Log</a> with the user who approved it.</p>

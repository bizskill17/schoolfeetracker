<?php
require_perm('students.view');
$title = 'Students';
$year = working_year();
if (!$year) { echo '<div class="alert alert-warning">Add an academic year in Settings first.</div>'; return; }
$classes = rows('SELECT * FROM classes ORDER BY sort_order, name');
$routes = rows('SELECT * FROM transport_routes ORDER BY name');
$status = get('status', 'active');
$where = ['e.academic_year_id = ?']; $p = [$year['id']];
if ($status === 'active') $where[] = "e.status <> 'left' AND s.status = 'active'";
elseif ($status === 'left') $where[] = "(e.status = 'left' OR s.status <> 'active')";
if (get('class_id')) { $where[] = 'e.class_id = ?'; $p[] = (int)get('class_id'); }
if (get('section_id')) { $where[] = 'e.section_id = ?'; $p[] = (int)get('section_id'); }
if (get('route')) { if (get('route') === 'none') $where[] = 'e.transport_route_id IS NULL'; else { $where[] = 'e.transport_route_id = ?'; $p[] = (int)get('route'); } }
if (get('gender')) { $where[] = 's.gender = ?'; $p[] = get('gender'); }
if (get('rte')) $where[] = 's.is_rte = 1';
if (get('q') !== '') {
    $qq = '%' . get('q') . '%';
    $where[] = '(s.admission_no LIKE ? OR CONCAT(s.first_name, " ", COALESCE(s.last_name, "")) LIKE ? OR s.father_name LIKE ? OR s.mother_name LIKE ? OR s.father_phone LIKE ? OR s.mother_phone LIKE ? OR s.whatsapp LIKE ? OR s.aadhaar LIKE ?)';
    array_push($p, $qq, $qq, $qq, $qq, $qq, $qq, $qq, $qq);
}
$w = implode(' AND ', $where) . scope_sql('e');
$base = "FROM enrollments e JOIN students s ON s.id = e.student_id JOIN classes c ON c.id = e.class_id
         LEFT JOIN sections sec ON sec.id = e.section_id LEFT JOIN transport_routes tr ON tr.id = e.transport_route_id WHERE $w";
$order = 'ORDER BY c.sort_order, sec.name, CAST(e.roll_no AS UNSIGNED), s.first_name';

if (get('export') === 'csv') {
    require_perm('reports');
    $fields = array_keys(STUDENT_FIELDS);
    $data = rows("SELECT s.*, c.name AS class_name, sec.name AS section_name, e.roll_no, tr.name AS route_name, e.status AS enr_status $base $order", $p);
    $out = [];
    foreach ($data as $r) {
        $line = [];
        foreach ($fields as $f) $line[] = $r[$f];
        array_push($line, $r['class_name'], $r['section_name'], $r['roll_no'], $r['route_name'], $r['is_rte'] ? 'Yes' : 'No', $r['enr_status']);
        $out[] = $line;
    }
    audit('student.export', 'student', null, ['count' => count($out)]);
    csv_download('students-' . $year['name'] . '.csv', array_merge(array_map(fn($f) => STUDENT_FIELDS[$f][0], $fields), ['Class', 'Section', 'Roll No', 'Transport Route', 'RTE', 'Status']), $out);
}

$total = (int)val("SELECT COUNT(*) $base", $p);
$pg = max(1, (int)get('pg', 1)); $per = 50;
$list = rows("SELECT s.id, s.admission_no, s.first_name, s.last_name, s.father_name, s.father_phone, s.gender, s.photo, s.is_rte, s.status AS s_status,
                     e.id AS enrollment_id, e.status, e.roll_no, c.name AS class_name, sec.name AS section_name, tr.name AS route_name
              $base $order LIMIT $per OFFSET " . (($pg - 1) * $per), $p);
$dues = [];
if ($list) {
    $in = implode(',', array_map('intval', array_column($list, 'enrollment_id')));
    foreach (rows("SELECT enrollment_id, SUM(CASE WHEN due_date <= CURDATE() THEN amount - concession - paid - waived ELSE 0 END) AS due_now
                   FROM fee_demands WHERE enrollment_id IN ($in) AND carried_forward = 0 GROUP BY enrollment_id") as $r) $dues[$r['enrollment_id']] = (float)$r['due_now'];
}
$qs = $_GET; unset($qs['pg']);
?>
<script>window.SECTIONS = <?= sections_json() ?>;</script>
<form class="card card-body mb-3">
<input type="hidden" name="p" value="students/list">
<div class="row g-2 align-items-end">
  <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?= h(get('q')) ?>" placeholder="Name, admission no, parent, mobile, Aadhaar"></div>
  <div class="col-6 col-md-2"><label class="form-label">Class</label><select name="class_id" id="fcls" class="form-select"><?= select_options($classes, get('class_id'), 'id', 'name', 'All') ?></select></div>
  <div class="col-6 col-md-1"><label class="form-label">Section</label><select name="section_id" class="form-select" data-sections-for="fcls" data-selected="<?= h(get('section_id')) ?>" data-empty="All"></select></div>
  <div class="col-6 col-md-2"><label class="form-label">Transport</label><select name="route" class="form-select"><option value="">Any</option><option value="none" <?= get('route') === 'none' ? 'selected' : '' ?>>No transport</option><?= select_options($routes, get('route')) ?></select></div>
  <div class="col-6 col-md-1"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach (['active' => 'Active', 'left' => 'Left', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 d-flex gap-1"><button class="btn btn-primary flex-fill"><i class="bi bi-search"></i> Find</button>
  <?php if (can('reports')): ?><a class="btn btn-outline-secondary" title="Download CSV" href="index.php?<?= h(http_build_query($qs + ['export' => 'csv'])) ?>"><i class="bi bi-download"></i></a><?php endif; ?></div>
</div></form>

<div class="d-flex justify-content-between align-items-center mb-2"><div class="text-muted small"><?= $total ?> student<?= $total === 1 ? '' : 's' ?> in <?= h($year['name']) ?></div>
<?php if (can('students.edit')): ?><a href="<?= url('students/form') ?>" class="btn btn-sm btn-success"><i class="bi bi-person-plus"></i> New admission</a><?php endif; ?></div>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle">
<thead><tr><th>Adm No</th><th>Student</th><th>Class</th><th>Roll</th><th>Father / Mobile</th><th>Transport</th><th class="text-end">Due now</th><th></th></tr></thead><tbody>
<?php foreach ($list as $r): $due = $dues[$r['enrollment_id']] ?? 0; ?>
<tr><td><?= h($r['admission_no']) ?></td>
<td><a href="<?= url('students/view', ['id' => $r['id']]) ?>" class="fw-semibold text-decoration-none"><?= h(student_name($r)) ?></a>
  <?= $r['is_rte'] ? '<span class="badge text-bg-info">RTE</span>' : '' ?> <?= $r['status'] === 'left' || $r['s_status'] !== 'active' ? status_badge('left') : '' ?></td>
<td><?= h(class_label($r)) ?></td><td><?= h($r['roll_no']) ?></td>
<td class="small"><?= h($r['father_name']) ?><br><a href="tel:<?= h($r['father_phone']) ?>"><?= h($r['father_phone']) ?></a></td>
<td class="small"><?= h($r['route_name'] ?? '—') ?></td>
<td class="amount <?= $due > 0 ? 'text-danger fw-semibold' : 'text-success' ?>"><?= $due > 0 ? money($due) : 'Nil' ?></td>
<td class="text-end text-nowrap">
  <?php if (can('fees.collect') && $r['status'] !== 'left'): ?><a class="btn btn-sm btn-outline-success" href="<?= url('fees/collect', ['enrollment' => $r['enrollment_id']]) ?>" title="Collect fee"><i class="bi bi-cash-stack"></i></a><?php endif; ?>
  <a class="btn btn-sm btn-light" href="<?= url('students/view', ['id' => $r['id']]) ?>" title="Open"><i class="bi bi-chevron-right"></i></a></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="8" class="text-center text-muted py-4">No students found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="mt-2"><?= paginate($total, $per, $pg, $qs) ?></div>

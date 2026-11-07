<?php
require_perm('students.promote');
$title = 'Promote Students / New Year';
$years = rows('SELECT * FROM academic_years ORDER BY start_date');
$classes = rows('SELECT * FROM classes ORDER BY sort_order, name');
$classById = array_column($classes, null, 'id');
$fromId = (int)get('from', 0);
$toId = (int)get('to', 0);
if (!$fromId || !$toId) {
    // default: current year -> the year after it
    $cur = current_year();
    foreach ($years as $i => $y) if ($cur && $y['id'] == $cur['id']) { $fromId = $fromId ?: (int)$y['id']; $toId = $toId ?: (int)($years[$i + 1]['id'] ?? 0); }
}
$classId = (int)get('class_id');

if (is_post()) {
    $from = (int)post('from'); $to = (int)post('to');
    $done = 0; $errs = [];
    $carryLate = (bool)post('carry_late');
    foreach ($_POST['act'] ?? [] as $enrId => $act) {
        $enrId = (int)$enrId;
        try {
            $e = row('SELECT * FROM enrollments WHERE id = ? AND academic_year_id = ?', [$enrId, $from]);
            if (!$e || $act === 'skip') continue;
            if ($act === 'passed_out' || $act === 'left') {
                update('enrollments', ['status' => $act === 'left' ? 'left' : 'promoted'], 'id = ?', [$enrId]);
                update('students', ['status' => $act === 'left' ? 'left' : 'passed_out', 'leaving_date' => row('SELECT end_date FROM academic_years WHERE id = ?', [$from])['end_date']], 'id = ?', [$e['student_id']]);
                audit('student.' . $act, 'student', (int)$e['student_id']);
                $done++;
                continue;
            }
            $target = $act === 'detain' ? (int)$e['class_id'] : (int)($_POST['to_class'][$enrId] ?? 0);
            if (!$target) throw new RuntimeException('no target class');
            $sec = nullif($_POST['to_section'][$enrId] ?? '');
            if (!$sec || !val('SELECT id FROM sections WHERE id = ? AND class_id = ?', [(int)$sec, $target])) {
                $secName = val('SELECT name FROM sections WHERE id = ?', [$e['section_id']]);
                $sec = $secName ? val('SELECT id FROM sections WHERE class_id = ? AND name = ?', [$target, $secName]) : null;
            }
            promote_enrollment($enrId, $to, $target, $sec ? (int)$sec : null, $carryLate, $act === 'detain' ? 'detained' : 'promoted');
            $done++;
        } catch (Throwable $ex) {
            $name = val("SELECT CONCAT(first_name, ' ', COALESCE(last_name,'')) FROM students s JOIN enrollments e ON e.student_id = s.id WHERE e.id = ?", [$enrId]);
            $errs[] = trim((string)$name) . ': ' . $ex->getMessage();
        }
    }
    flash($errs ? 'warn' : 'ok', "$done students processed." . ($errs ? ' Problems: ' . implode('; ', array_slice($errs, 0, 5)) : ''));
    redirect('students/promote', ['from' => $from, 'to' => $to, 'class_id' => post('class_id')]);
}

$list = [];
if ($fromId && $toId && $classId) {
    $list = rows("SELECT e.*, s.admission_no, s.first_name, s.last_name, sec.name AS section_name,
                 (SELECT id FROM enrollments e2 WHERE e2.student_id = e.student_id AND e2.academic_year_id = ?) AS already
                 FROM enrollments e JOIN students s ON s.id = e.student_id LEFT JOIN sections sec ON sec.id = e.section_id
                 WHERE e.academic_year_id = ? AND e.class_id = ? AND e.status = 'active' AND s.status = 'active'
                 ORDER BY sec.name, CAST(e.roll_no AS UNSIGNED), s.first_name", [$toId, $fromId, $classId]);
}
$nextClass = $classId ? ($classById[$classId]['next_class_id'] ?? null) : null;
$secMap = json_decode(sections_json(), true);
?>
<div class="alert alert-light border small mb-3">
<b>How it works:</b> pick the year you are moving from and to, then a class. Each student can be promoted to the next class, kept in the same class (detained), marked passed out, or marked as left.
Any unpaid balance from the old year is carried into the new year as <b>Previous Year Dues</b>, and the new year's fee schedule is created automatically. <b>Take a <a href="<?= url('settings/backup') ?>">backup</a> first.</b>
</div>
<?php if (count($years) < 2): ?><div class="alert alert-warning">Add the next academic year in <a href="<?= url('settings/years') ?>">Academic Years</a> and set its <a href="<?= url('settings/fee_structure') ?>">fee structure</a> first.</div><?php endif; ?>
<form class="card card-body mb-3"><input type="hidden" name="p" value="students/promote">
<div class="row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label">From year</label><select name="from" class="form-select"><?= select_options($years, $fromId) ?></select></div>
  <div class="col-md-3"><label class="form-label">To year</label><select name="to" class="form-select"><?= select_options($years, $toId, 'id', 'name', '— Choose —') ?></select></div>
  <div class="col-md-3"><label class="form-label">Class</label><select name="class_id" class="form-select"><?= select_options($classes, $classId, 'id', 'name', '— Choose —') ?></select></div>
  <div class="col-md-3"><button class="btn btn-primary w-100">Show students</button></div>
</div></form>

<?php if ($classId && $fromId === $toId): ?><div class="alert alert-danger">From and To year must be different.</div>
<?php elseif ($list): ?>
<form method="post" class="card">
<?= csrf_field() ?><input type="hidden" name="from" value="<?= $fromId ?>"><input type="hidden" name="to" value="<?= $toId ?>"><input type="hidden" name="class_id" value="<?= $classId ?>">
<div class="card-body border-bottom d-flex flex-wrap gap-2 align-items-center">
  <span class="small">Set all:</span>
  <?php foreach (['promote' => 'Promote', 'detain' => 'Detain', 'passed_out' => 'Passed out', 'skip' => 'Skip'] as $k => $l): ?><button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.actsel').forEach(s=>{if(!s.disabled)s.value='<?= $k ?>'})"><?= $l ?></button><?php endforeach; ?>
  <span class="ms-auto small">Promote to: <b><?= h($nextClass ? $classById[$nextClass]['name'] : 'no next class set (final class)') ?></b></span>
</div>
<div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>Adm No</th><th>Student</th><th>Section</th><th class="text-end">Unpaid balance</th><th>Action</th><th>New class</th><th>New section</th></tr></thead><tbody>
<?php foreach ($list as $r): $bal = fee_summary((int)$r['id'])['balance']; ?>
<tr><td><?= h($r['admission_no']) ?></td><td><?= h(student_name($r)) ?></td><td><?= h($r['section_name']) ?></td>
<td class="amount <?= $bal > 0 ? 'text-danger' : '' ?>"><?= money($bal) ?></td>
<?php if ($r['already']): ?><td colspan="3"><span class="badge text-bg-success">Already in new year</span></td>
<?php else: ?>
<td><select name="act[<?= $r['id'] ?>]" class="form-select form-select-sm actsel"><option value="promote" <?= $nextClass ? 'selected' : '' ?>>Promote</option><option value="detain">Detain (same class)</option><option value="passed_out" <?= $nextClass ? '' : 'selected' ?>>Passed out</option><option value="left">Left school</option><option value="skip">Skip for now</option></select></td>
<td><select name="to_class[<?= $r['id'] ?>]" class="form-select form-select-sm"><?= select_options($classes, $nextClass ?: '', 'id', 'name', '—') ?></select></td>
<td><select name="to_section[<?= $r['id'] ?>]" class="form-select form-select-sm"><option value="">Same as now</option><?php foreach ($secMap[$nextClass] ?? [] as $sc): ?><option value="<?= $sc['id'] ?>" <?= $sc['name'] === $r['section_name'] ? 'selected' : '' ?>><?= h($sc['name']) ?></option><?php endforeach; ?></select></td>
<?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="card-body border-top d-flex flex-wrap gap-3 align-items-center">
  <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="carry_late" value="1"> Also carry forward unpaid late fees</label>
  <button class="btn btn-primary ms-auto" data-confirm="Process these students into the new year?">Process</button>
</div>
</form>
<?php elseif ($classId): ?><div class="alert alert-info">No active students in this class for the selected year.</div><?php endif; ?>

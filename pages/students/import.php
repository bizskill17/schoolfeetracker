<?php
require_perm('students.edit');
if (section_scope() !== null) { flash('err', 'Importing students is done by the office.'); redirect('students/list'); }
$title = 'Import Students';
$year = working_year();
if (!$year) { echo '<div class="alert alert-warning">Add an academic year first.</div>'; return; }
$extra = ['class' => 'Class', 'section' => 'Section', 'roll_no' => 'Roll No', 'transport_route' => 'Transport Route', 'is_new' => 'New Admission (Yes/No)', 'is_rte' => 'RTE (Yes/No)'];
$cols = array_merge(array_map(fn($d) => $d[0], STUDENT_FIELDS), $extra);

if (get('template')) {
    $sample = [];
    foreach (array_keys($cols) as $k) $sample[] = ['admission_no' => 'ADM0001', 'admission_date' => date('d-m-Y'), 'first_name' => 'Aarav', 'last_name' => 'Sharma', 'dob' => '15-06-2016',
        'gender' => lookup_names('gender')[0] ?? '', 'father_name' => 'Rajesh Sharma', 'father_phone' => '9876543210', 'class' => 'Class 5', 'section' => 'A', 'roll_no' => '1', 'is_new' => 'No', 'is_rte' => 'No', 'nationality' => lookup_names('nationality')[0] ?? ''][$k] ?? '';
    csv_download('student-import-template.csv', array_values($cols), [$sample]);
}

$report = null;
if (is_post()) {
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) { flash('err', 'Choose a CSV file.'); redirect('students/import'); }
    $fh = fopen($_FILES['csv']['tmp_name'], 'r');
    $header = fgetcsv($fh);
    if (!$header) { flash('err', 'The file is empty.'); redirect('students/import'); }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $labelToKey = array_change_key_case(array_flip(array_map('strval', $cols)), CASE_LOWER);
    $map = [];
    foreach ($header as $i => $hcol) { $k = $labelToKey[strtolower(trim((string)$hcol))] ?? null; if ($k) $map[$i] = $k; }
    if (!in_array('first_name', $map, true) || !in_array('class', $map, true)) { flash('err', 'The file must have at least "First Name" and "Class" columns. Download the template.'); redirect('students/import'); }
    $classes = array_change_key_case(array_column(rows('SELECT id, name FROM classes'), 'id', 'name'), CASE_LOWER);
    $routes = array_change_key_case(array_column(rows('SELECT id, name FROM transport_routes'), 'id', 'name'), CASE_LOWER);
    $toDate = function ($v) {
        $v = trim((string)$v); if ($v === '') return null;
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'd.m.Y', 'd-m-y', 'd/m/y'] as $f) { $d = DateTime::createFromFormat('!' . $f, $v); if ($d) return $d->format('Y-m-d'); }
        return null;
    };
    $report = ['ok' => 0, 'skip' => [], 'rows' => 0];
    $line = 1;
    $dryRun = (bool)post('dry_run');
    $pdo = db();
    $pdo->beginTransaction();
    while (($r = fgetcsv($fh)) !== false) {
        $line++;
        if (!array_filter($r, fn($v) => trim((string)$v) !== '')) continue;
        $report['rows']++;
        $d = array_fill_keys(array_keys(STUDENT_FIELDS), '');
        $x = array_fill_keys(array_keys($extra), '');
        foreach ($map as $i => $k) { $v = trim((string)($r[$i] ?? '')); if (isset($d[$k])) $d[$k] = $v; else $x[$k] = $v; }
        if ($d['admission_no'] === '') $d['admission_no'] = suggest_admission_no();
        $d['admission_date'] = $toDate($d['admission_date']);
        $d['dob'] = $toDate($d['dob']);
        foreach (STUDENT_FIELDS as $f => $def) if (in_array($def[2], ['select', 'suggest'], true) && $d[$f] !== '') $d[$f] = lookup_match($def[3], $d[$f]);   // M → Male, obc → OBC
        $d['is_rte'] = in_array(strtolower($x['is_rte']), ['yes', 'y', '1'], true) ? 1 : 0;
        $errs = validate_student(array_map(fn($v) => $v ?? '', $d));
        $classId = $classes[strtolower($x['class'])] ?? null;
        if (!$classId) $errs[] = 'Class "' . $x['class'] . '" not found';
        if (val('SELECT id FROM students WHERE admission_no = ?', [$d['admission_no']])) $errs[] = 'Admission no ' . $d['admission_no'] . ' already exists';
        if ($errs) { $report['skip'][] = "Row $line: " . implode(', ', $errs); continue; }
        $secId = $x['section'] !== '' ? val('SELECT id FROM sections WHERE class_id = ? AND name = ?', [$classId, $x['section']]) : null;
        if ($x['section'] !== '' && !$secId) $secId = insert('sections', ['class_id' => $classId, 'name' => $x['section']]);
        foreach ($d as $k => $v) if ($v === '') $d[$k] = null;
        $d['nationality'] = $d['nationality'] ?? 'Indian';
        $sid = insert('students', $d);
        $eid = insert('enrollments', ['student_id' => $sid, 'academic_year_id' => $year['id'], 'class_id' => $classId, 'section_id' => $secId ?: null,
            'roll_no' => $x['roll_no'], 'is_new' => in_array(strtolower($x['is_new']), ['yes', 'y', '1'], true) ? 1 : 0,
            'transport_route_id' => $routes[strtolower($x['transport_route'])] ?? null,
            'join_date' => max($year['start_date'], min($year['end_date'], $d['admission_date'] ?? $year['start_date']))]);
        generate_demands($eid);
        $report['ok']++;
    }
    fclose($fh);
    if ($dryRun) { $pdo->rollBack(); $report['dry'] = true; }
    else { $pdo->commit(); audit('student.import', 'student', null, ['imported' => $report['ok'], 'skipped' => count($report['skip'])]); }
}
?>
<div class="row g-3">
<div class="col-lg-6"><form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
<?= csrf_field() ?>
<p>Bring in existing students from Excel. Save your sheet as <b>CSV</b> with the same column headings as the template. Students are added to <b><?= h($year['name']) ?></b> and their fee schedules are created.</p>
<a href="<?= url('students/import', ['template' => 1]) ?>" class="btn btn-sm btn-outline-primary mb-3"><i class="bi bi-download"></i> Download template</a>
<div class="mb-2"><input type="file" name="csv" accept=".csv,text/csv" class="form-control" required></div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="dry_run" value="1" id="dr" checked><label for="dr" class="form-check-label">Test only – check the file without saving</label></div>
<button class="btn btn-primary">Upload</button>
</div></form></div>
<div class="col-lg-6">
<?php if ($report): ?>
<div class="card"><div class="card-body">
<h6><?= !empty($report['dry']) ? 'Test result (nothing saved)' : 'Import finished' ?></h6>
<p class="mb-1"><?= $report['rows'] ?> rows read · <b class="text-success"><?= $report['ok'] ?> <?= !empty($report['dry']) ? 'ready to import' : 'imported' ?></b> · <b class="text-danger"><?= count($report['skip']) ?> skipped</b></p>
<?php if ($report['skip']): ?><ul class="small text-danger mb-0" style="max-height:300px;overflow:auto"><?php foreach ($report['skip'] as $sk): ?><li><?= h($sk) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (!empty($report['dry']) && $report['ok']): ?><div class="small mt-2">Untick "Test only" and upload again to save.</div><?php endif; ?>
</div></div>
<?php else: ?>
<div class="card"><div class="card-body small"><b>Tips</b><ul class="mb-0"><li>Class names must match exactly what is in Classes &amp; Sections (e.g. "Class 5").</li><li>Dates as DD-MM-YYYY.</li><li>Leave Admission No blank to number automatically.</li><li>Previous dues from your old records can be added per student afterwards (Add a charge → fee head of your choice).</li></ul></div></div>
<?php endif; ?>
</div></div>

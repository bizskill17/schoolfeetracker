<?php
require_perm('students.edit');
$id = (int)get('id');
$year = working_year();
if (!$year) { echo '<div class="alert alert-warning">Add an academic year in Settings first.</div>'; return; }
$student = $id ? row('SELECT * FROM students WHERE id = ?', [$id]) : null;
if ($id && !$student) { flash('err', 'Student not found.'); redirect('students/list'); }
$enr = $student ? student_enrollment($id, (int)$year['id']) : null;
if ($student && section_scope() !== null && !$enr) { flash('err', 'This student is not in your class this year.'); redirect('students/list'); }
require_enrollment_scope($enr);
$title = $student ? 'Edit Student – ' . student_name($student) : 'New Admission';
$classes = rows('SELECT * FROM classes ORDER BY sort_order, name');
if (section_scope() !== null) {   // class teacher: only their own classes
    $mine = section_scope() ? array_column(rows('SELECT DISTINCT class_id FROM sections WHERE id IN (' . implode(',', section_scope()) . ')'), 'class_id') : [];
    $classes = array_values(array_filter($classes, fn($c) => in_array($c['id'], $mine)));
}
$routes = rows('SELECT * FROM transport_routes WHERE active = 1 ORDER BY name');
$ctypes = rows('SELECT * FROM concession_types WHERE active = 1 ORDER BY name');
$errors = [];

if (is_post()) {
    $d = [];
    foreach (STUDENT_FIELDS as $f => $def) $d[$f] = trim((string)post($f));
    $d['is_rte'] = post('is_rte') ? 1 : 0;
    $d['sibling_of'] = nullif(post('sibling_of'));
    $e = ['class_id' => (int)post('class_id'), 'section_id' => nullif(post('section_id')), 'roll_no' => post('roll_no'),
        'transport_route_id' => nullif(post('transport_route_id')), 'is_new' => post('is_new') ? 1 : 0,
        'join_date' => post('join_date') ?: ($d['admission_date'] ?: today())];
    $errors = validate_student($d);
    if (!$e['class_id']) $errors[] = 'Choose a class.';
    if (!in_scope($e['section_id'] !== null ? (int)$e['section_id'] : null)) $errors[] = 'Choose one of your own sections.';
    if ($e['join_date'] < $year['start_date'] || $e['join_date'] > $year['end_date']) $e['join_date'] = max($year['start_date'], min($year['end_date'], $e['join_date']));
    if (val('SELECT id FROM students WHERE admission_no = ? AND id <> ?', [$d['admission_no'], $id])) $errors[] = 'Admission number ' . $d['admission_no'] . ' is already used.';
    if (!$id && post('dup_ok') !== '1' && $d['first_name'] && $d['dob']) {
        $dup = row('SELECT id, admission_no FROM students WHERE first_name = ? AND dob = ? AND (father_name = ? OR father_phone = ?)', [$d['first_name'], $d['dob'], $d['father_name'], $d['father_phone']]);
        if ($dup) $errors[] = 'A student with the same name, date of birth and father already exists (Adm No ' . $dup['admission_no'] . '). Tick "Save anyway" if this is a different child.';
    }
    if (!$errors) {
        foreach (['admission_date', 'dob'] as $f) $d[$f] = nullif($d[$f]);
        $d['gender'] = nullif($d['gender']);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $photo = save_upload('photo', 'photos', ['jpg', 'jpeg', 'png', 'webp'], 2097152);
            if ($photo) $d['photo'] = $photo;
            if ($id) {
                update('students', $d, 'id = ?', [$id]);
                audit('student.update', 'student', $id, ['admission_no' => $d['admission_no']]);
            } else {
                $id = insert('students', $d);
                audit('student.create', 'student', $id, ['admission_no' => $d['admission_no']]);
            }
            $regen = false;
            if ($enr) {
                $changed = $enr['class_id'] != $e['class_id'] || $enr['transport_route_id'] != $e['transport_route_id'] || $enr['is_new'] != $e['is_new'] || $enr['join_date'] !== $e['join_date'];
                update('enrollments', $e, 'id = ?', [$enr['id']]);
                $enrId = (int)$enr['id'];
                $regen = $changed || (int)$student['is_rte'] !== $d['is_rte'];
            } else {
                $enrId = insert('enrollments', $e + ['student_id' => $id, 'academic_year_id' => $year['id']]);
                if (post('concession_type_id')) {
                    $ct = row('SELECT * FROM concession_types WHERE id = ?', [(int)post('concession_type_id')]);
                    if ($ct && can('fees.concession')) {
                        insert('student_concessions', ['enrollment_id' => $enrId, 'concession_type_id' => $ct['id'], 'kind' => $ct['kind'],
                            'value' => post('concession_value') !== '' ? (float)post('concession_value') : $ct['value'], 'fee_head_id' => $ct['fee_head_id'],
                            'reason' => $ct['name'], 'approved_by' => $_SESSION['uid']]);
                    }
                }
                $regen = true;
            }
            if ($regen) generate_demands($enrId);
            $pdo->commit();
            flash('ok', $student ? 'Student updated.' . ($regen ? ' Fee schedule updated.' : '') : 'Admission saved. Fee schedule created.');
            redirect('students/view', ['id' => $id]);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            if ($ex instanceof RuntimeException) $errors[] = $ex->getMessage(); else throw $ex;
        }
    }
    $student = array_merge($student ?? [], $d);
    $enr = array_merge($enr ?? [], $e);
}
$s = $student ?? ['admission_no' => suggest_admission_no(), 'admission_date' => today(), 'nationality' => lookup_names('nationality')[0] ?? ''];
$en = $enr ?? ['is_new' => 1, 'join_date' => max($year['start_date'], min($year['end_date'], today()))];
$fieldHtml = function (string $f) use ($s) {
    [$label, , $type] = STUDENT_FIELDS[$f];
    $list = STUDENT_FIELDS[$f][3] ?? '';
    $req = in_array($f, ['admission_no', 'first_name'], true) ? ' required' : '';
    $v = $s[$f] ?? '';
    $col = $type === 'textarea' ? 'col-12' : 'col-md-4 col-lg-3';
    $o = '<div class="' . $col . '"><label class="form-label' . ($req ? ' req' : '') . '" for="f_' . $f . '">' . h($label) . '</label>';
    if ($type === 'select') {
        $o .= '<select class="form-select" name="' . $f . '" id="f_' . $f . '">' . lookup_options($list, $v) . '</select>';
    } elseif ($type === 'suggest') {
        $o .= '<input class="form-control" name="' . $f . '" id="f_' . $f . '" value="' . h($v) . '" list="dl_' . h($list) . '" autocomplete="off">';
    } elseif ($type === 'textarea') {
        $o .= '<textarea class="form-control" rows="2" name="' . $f . '" id="f_' . $f . '">' . h($v) . '</textarea>';
    } else {
        $o .= '<input class="form-control" type="' . $type . '" name="' . $f . '" id="f_' . $f . '" value="' . h($v) . '"' . $req . ($type === 'tel' ? ' inputmode="tel"' : '') . '>';
    }
    return $o . '</div>';
};
?>
<script>window.SECTIONS = <?= sections_json(true) ?>;</script>
<?php foreach (array_unique(array_filter(array_map(fn($d) => $d[2] === 'suggest' ? $d[3] : null, STUDENT_FIELDS))) as $dl) echo lookup_datalist($dl); ?>
<?php if (can('settings')): ?><div class="small text-muted mb-2 text-end"><i class="bi bi-list-check"></i> Dropdown choices can be changed in <a href="<?= url('masters/lists') ?>">Masters → Dropdown Lists</a></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= h($er) ?></li><?php endforeach; ?></ul>
<?php if (strpos(implode(' ', $errors), 'Save anyway') !== false): ?><?php endif; ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
<?= csrf_field() ?>
<?php if (strpos(implode(' ', $errors), 'Save anyway') !== false): ?><div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="dup_ok" value="1" id="dup"><label for="dup" class="form-check-label text-danger">Save anyway – this is a different student</label></div><?php endif; ?>

<div class="form-section mt-0">Class for <?= h($year['name']) ?></div>
<div class="row g-3">
  <div class="col-md-3"><label class="form-label req">Class</label><select name="class_id" id="class_id" class="form-select" required><?= select_options($classes, $en['class_id'] ?? '', 'id', 'name', '— Choose —') ?></select></div>
  <div class="col-md-2"><label class="form-label">Section</label><select name="section_id" class="form-select" data-sections-for="class_id" data-selected="<?= h($en['section_id'] ?? '') ?>"></select></div>
  <div class="col-md-2"><label class="form-label">Roll No</label><input name="roll_no" class="form-control" value="<?= h($en['roll_no'] ?? '') ?>"></div>
  <div class="col-md-3"><label class="form-label">Joined this year on</label><input type="date" name="join_date" class="form-control" value="<?= h($en['join_date'] ?? '') ?>" min="<?= h($year['start_date']) ?>" max="<?= h($year['end_date']) ?>">
    <div class="form-text">Fees are charged from this month.</div></div>
  <div class="col-md-2 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_new" value="1" id="is_new" <?= !empty($en['is_new']) ? 'checked' : '' ?>><label for="is_new" class="form-check-label">New admission<br><span class="small text-muted">(admission fees apply)</span></label></div></div>
  <div class="col-md-4"><label class="form-label">School transport</label><select name="transport_route_id" class="form-select"><?= select_options(array_map(fn($r) => $r + ['label' => $r['name'] . ' – ' . money($r['amount'])], $routes), $en['transport_route_id'] ?? '', 'id', 'label', 'No transport') ?></select></div>
  <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_rte" value="1" id="is_rte" <?= !empty($s['is_rte']) ? 'checked' : '' ?>><label for="is_rte" class="form-check-label">RTE (free seat)</label></div></div>
  <?php if (!$enr && $ctypes && can('fees.concession')): ?>
  <div class="col-md-3"><label class="form-label">Concession</label><select name="concession_type_id" class="form-select"><?= select_options(array_map(fn($c) => $c + ['label' => $c['name'] . ' (' . ($c['kind'] === 'percent' ? ($c['value'] + 0) . '%' : money($c['value'])) . ')'], $ctypes), post('concession_type_id'), 'id', 'label', 'None') ?></select></div>
  <div class="col-md-2"><label class="form-label">Value (optional)</label><input name="concession_value" type="number" step="0.01" class="form-control" placeholder="Default"></div>
  <?php endif; ?>
</div>

<?php foreach (STUDENT_GROUPS as $g => $gl): ?>
<div class="form-section"><?= h($gl) ?></div>
<div class="row g-3">
<?php if ($g === 'basic'): ?>
  <div class="col-12 d-flex gap-3 align-items-center">
    <?php if (!empty($s['photo'])): ?><img src="file.php?f=<?= h($s['photo']) ?>" class="student-photo"><?php else: ?><div class="photo-ph"><i class="bi bi-person"></i></div><?php endif; ?>
    <div><label class="form-label">Photo (JPG/PNG, max 2 MB)</label><input type="file" name="photo" class="form-control" accept="image/*" capture="environment"></div>
  </div>
<?php endif; ?>
<?php foreach (STUDENT_FIELDS as $f => $def) if ($def[1] === $g) echo $fieldHtml($f); ?>
</div>
<?php endforeach; ?>
<div class="mt-4 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $student && !empty($student['id']) ? 'Save changes' : 'Save admission' ?></button>
<a href="<?= $id ? url('students/view', ['id' => $id]) : url('students/list') ?>" class="btn btn-light">Cancel</a></div>
</div></form>

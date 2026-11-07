<?php
require_perm('students.view');
$s = row('SELECT * FROM students WHERE id = ?', [(int)get('id')]);
if (!$s) exit('Not found');
$year = working_year();
$enr = $year ? student_enrollment((int)$s['id'], (int)$year['id']) : null;
if (section_scope() !== null && !$enr) exit('Not allowed');
require_enrollment_scope($enr);
$bare = true;
$title = 'Student Record – ' . student_name($s);
$logo = setting('school_logo');
?>
<div class="container py-3" style="max-width:820px">
<div class="no-print mb-3 d-flex gap-2"><button onclick="print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button><a href="<?= url('students/view', ['id' => $s['id']]) ?>" class="btn btn-light">Back</a></div>
<div class="d-flex align-items-center gap-3 border-bottom pb-2 mb-3">
  <?php if ($logo): ?><img src="file.php?f=<?= h($logo) ?>" style="height:64px"><?php endif; ?>
  <div class="flex-fill"><h4 class="mb-0"><?= h(setting('school_name')) ?></h4><div class="small"><?= h(setting('school_address')) ?> <?= setting('school_phone') ? '· ' . h(setting('school_phone')) : '' ?></div></div>
  <?php if ($s['photo']): ?><img src="file.php?f=<?= h($s['photo']) ?>" style="width:90px;height:110px;object-fit:cover;border:1px solid #999"><?php endif; ?>
</div>
<h5 class="text-center mb-3">STUDENT RECORD</h5>
<table class="table table-sm table-bordered">
<tr><th style="width:25%">Class (<?= h($year['name'] ?? '') ?>)</th><td><?= $enr ? h(class_label($enr)) : '—' ?></td><th style="width:20%">Roll No</th><td><?= h($enr['roll_no'] ?? '') ?></td></tr>
<?php $pairs = array_chunk(array_keys(STUDENT_FIELDS), 2); foreach ($pairs as $pr): ?>
<tr><?php foreach ($pr as $f): $v = $s[$f]; if (STUDENT_FIELDS[$f][2] === 'date') $v = dmy($v); ?><th><?= h(STUDENT_FIELDS[$f][0]) ?></th><td><?= nl2br(h($v)) ?></td><?php endforeach; if (count($pr) === 1) echo '<th></th><td></td>'; ?></tr>
<?php endforeach; ?>
<tr><th>Transport</th><td><?= h($enr['route_name'] ?? 'No') ?></td><th>RTE</th><td><?= $s['is_rte'] ? 'Yes' : 'No' ?></td></tr>
</table>
<div class="d-flex justify-content-between mt-5 pt-4"><div class="text-center border-top px-4">Parent / Guardian</div><div class="text-center border-top px-4">Office</div><div class="text-center border-top px-4">Principal</div></div>
</div>

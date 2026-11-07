<?php
require_perm('fees.adjust');
$title = 'Update Fee Schedules';
$year = working_year();
$classes = rows('SELECT * FROM classes ORDER BY sort_order, name');
if (is_post()) {
    $where = "academic_year_id = ? AND status <> 'left'"; $p = [$year['id']];
    if (post('class_id')) { $where .= ' AND class_id = ?'; $p[] = (int)post('class_id'); }
    $n = 0; $cnt = 0;
    db()->beginTransaction();
    foreach (rows("SELECT id FROM enrollments WHERE $where", $p) as $e) { $n += generate_demands((int)$e['id']); $cnt++; }
    db()->commit();
    audit('fees.regenerate', 'year', (int)$year['id'], ['class' => post('class_id'), 'students' => $cnt, 'changes' => $n]);
    flash('ok', "Checked $cnt students; $n instalments created, updated or removed.");
    redirect('fees/regenerate');
}
?>
<form method="post" class="card" style="max-width:640px"><div class="card-body">
<?= csrf_field() ?>
<p>Run this after you change the fee structure, fee heads, transport fees, due day or concessions, so every student's fee schedule for <b><?= h($year['name'] ?? '') ?></b> matches.</p>
<ul class="small text-muted"><li>New instalments are added; amounts and due dates of <b>unpaid</b> instalments are updated.</li><li>Instalments that are already paid, part-paid or waived are never changed.</li><li>Extra charges you added by hand and previous year dues are kept.</li></ul>
<div class="mb-3"><label class="form-label">Class</label><select name="class_id" class="form-select"><?= select_options($classes, '', 'id', 'name', 'All classes') ?></select></div>
<button class="btn btn-primary" data-confirm="Update fee schedules now?"><i class="bi bi-arrow-repeat"></i> Update fee schedules</button>
</div></form>

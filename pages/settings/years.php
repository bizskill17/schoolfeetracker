<?php
require_perm('settings');
$title = 'Academic Years';
if (is_post()) {
    $act = post('act');
    try {
        if ($act === 'save') {
            $id = (int)post('id');
            $d = ['name' => post('name'), 'start_date' => post('start_date'), 'end_date' => post('end_date')];
            if (!$d['name'] || !$d['start_date'] || !$d['end_date']) throw new RuntimeException('Fill name, start and end date.');
            if ($d['end_date'] <= $d['start_date']) throw new RuntimeException('End date must be after start date.');
            if ($id) { update('academic_years', $d, 'id = ?', [$id]); audit('year.update', 'year', $id, $d); }
            else { $id = insert('academic_years', $d); audit('year.create', 'year', $id, $d); }
            flash('ok', 'Academic year saved.');
        } elseif ($act === 'current') {
            $id = (int)post('id');
            q('UPDATE academic_years SET is_current = (id = ?)', [$id]);
            unset($_SESSION['year_id']);
            audit('year.set_current', 'year', $id);
            flash('ok', 'Current academic year changed.');
        } elseif ($act === 'delete') {
            $id = (int)post('id');
            if (val('SELECT COUNT(*) FROM enrollments WHERE academic_year_id = ?', [$id])) throw new RuntimeException('This year has students and cannot be deleted.');
            if (val('SELECT is_current FROM academic_years WHERE id = ?', [$id])) throw new RuntimeException('You cannot delete the current year.');
            q('DELETE FROM academic_years WHERE id = ?', [$id]);
            audit('year.delete', 'year', $id);
            flash('ok', 'Deleted.');
        }
    } catch (PDOException $e) {
        flash('err', strpos($e->getMessage(), 'Duplicate') !== false ? 'A year with that name already exists.' : 'Could not save.');
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('settings/years');
}
$list = rows('SELECT y.*, (SELECT COUNT(*) FROM enrollments e WHERE e.academic_year_id = y.id) AS students FROM academic_years y ORDER BY start_date DESC');
$edit = get('edit') ? row('SELECT * FROM academic_years WHERE id = ?', [(int)get('edit')]) : null;
$last = $list[0] ?? null;
$sugStart = $last ? date('Y-m-d', strtotime($last['end_date'] . ' +1 day')) : date('Y') . '-04-01';
$sugEnd = date('Y-m-d', strtotime($sugStart . ' +1 year -1 day'));
$sugName = date('Y', strtotime($sugStart)) . '-' . date('y', strtotime($sugEnd));
?>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body p-0"><table class="table mb-0 align-middle">
<thead><tr><th>Year</th><th>From</th><th>To</th><th>Students</th><th></th></tr></thead><tbody>
<?php foreach ($list as $y): ?>
<tr><td><b><?= h($y['name']) ?></b> <?= $y['is_current'] ? '<span class="badge text-bg-success">Current</span>' : '' ?></td>
<td><?= dmy($y['start_date']) ?></td><td><?= dmy($y['end_date']) ?></td><td><?= (int)$y['students'] ?></td>
<td class="text-end text-nowrap">
  <a class="btn btn-sm btn-light" href="<?= url('settings/years', ['edit' => $y['id']]) ?>"><i class="bi bi-pencil"></i></a>
  <?php if (!$y['is_current']): ?>
  <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="current"><input type="hidden" name="id" value="<?= $y['id'] ?>">
    <button class="btn btn-sm btn-outline-success" data-confirm="Make <?= h($y['name']) ?> the current year? New admissions and fee collection will default to it.">Make current</button></form>
  <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $y['id'] ?>">
    <button class="btn btn-sm btn-outline-danger" data-confirm="Delete this year?"><i class="bi bi-trash"></i></button></form>
  <?php endif; ?>
</td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<div class="small text-muted mt-2">To start a new year: add it here, set up its fee structure, then use <a href="<?= url('students/promote') ?>">Promote / New Year</a> to move students. Make it current when the session begins.</div>
</div>
<div class="col-lg-5"><form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit year' : 'Add academic year' ?></div><div class="card-body">
<?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
<div class="mb-2"><label class="form-label req">Name</label><input name="name" class="form-control" required value="<?= h($edit['name'] ?? $sugName) ?>" placeholder="2026-27"></div>
<div class="row g-2 mb-3"><div class="col"><label class="form-label req">Start date</label><input type="date" name="start_date" class="form-control" required value="<?= h($edit['start_date'] ?? $sugStart) ?>"></div>
<div class="col"><label class="form-label req">End date</label><input type="date" name="end_date" class="form-control" required value="<?= h($edit['end_date'] ?? $sugEnd) ?>"></div></div>
<button class="btn btn-primary">Save</button> <?php if ($edit): ?><a href="<?= url('settings/years') ?>" class="btn btn-light">Cancel</a><?php endif; ?>
</div></form></div>
</div>

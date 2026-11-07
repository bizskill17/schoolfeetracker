<?php
require_perm('settings');
$title = 'Classes & Sections';
if (is_post()) {
    $act = post('act');
    try {
        if ($act === 'class') {
            $id = (int)post('id');
            $d = ['name' => post('name'), 'sort_order' => (int)post('sort_order'), 'next_class_id' => nullif(post('next_class_id'))];
            if (!$d['name']) throw new RuntimeException('Enter a class name.');
            if ($id) { update('classes', $d, 'id = ?', [$id]); audit('class.update', 'class', $id, $d); }
            else {
                $id = insert('classes', $d);
                foreach (array_filter(array_map('trim', explode(',', (string)post('sections', 'A')))) as $s) insert('sections', ['class_id' => $id, 'name' => $s]);
                audit('class.create', 'class', $id, $d);
            }
            flash('ok', 'Class saved.');
        } elseif ($act === 'section') {
            insert('sections', ['class_id' => (int)post('class_id'), 'name' => post('name')]);
            flash('ok', 'Section added.');
        } elseif ($act === 'del_section') {
            $id = (int)post('id');
            if (val('SELECT COUNT(*) FROM enrollments WHERE section_id = ?', [$id])) throw new RuntimeException('Students are in this section. Move them first.');
            q('DELETE FROM sections WHERE id = ?', [$id]);
            flash('ok', 'Section removed.');
        } elseif ($act === 'del_class') {
            $id = (int)post('id');
            if (val('SELECT COUNT(*) FROM enrollments WHERE class_id = ?', [$id])) throw new RuntimeException('This class has students and cannot be deleted.');
            q('UPDATE classes SET next_class_id = NULL WHERE next_class_id = ?', [$id]);
            q('DELETE FROM classes WHERE id = ?', [$id]);
            audit('class.delete', 'class', $id);
            flash('ok', 'Class deleted.');
        }
    } catch (PDOException $e) {
        flash('err', strpos($e->getMessage(), 'Duplicate') !== false ? 'That name already exists.' : 'Could not save.');
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('settings/classes');
}
$classes = rows('SELECT c.*, n.name AS next_name FROM classes c LEFT JOIN classes n ON n.id = c.next_class_id ORDER BY c.sort_order, c.name');
$secs = [];
foreach (rows('SELECT s.*, (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.id) AS used FROM sections s ORDER BY name') as $s) $secs[$s['class_id']][] = $s;
$edit = get('edit') ? row('SELECT * FROM classes WHERE id = ?', [(int)get('edit')]) : null;
$nextOrder = (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM classes');
?>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 align-middle">
<thead><tr><th>#</th><th>Class</th><th>Sections</th><th>Promotes to</th><th></th></tr></thead><tbody>
<?php foreach ($classes as $c): ?>
<tr><td class="text-muted"><?= (int)$c['sort_order'] ?></td><td><b><?= h($c['name']) ?></b></td>
<td>
  <?php foreach ($secs[$c['id']] ?? [] as $s): ?>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="del_section"><input type="hidden" name="id" value="<?= $s['id'] ?>">
    <span class="badge text-bg-light border"><?= h($s['name']) ?><?php if (!$s['used']): ?> <button class="btn btn-link p-0 text-danger small" style="font-size:.7rem" data-confirm="Remove section <?= h($s['name']) ?>?">×</button><?php endif; ?></span></form>
  <?php endforeach; ?>
  <form method="post" class="d-inline-flex gap-1"><?= csrf_field() ?><input type="hidden" name="act" value="section"><input type="hidden" name="class_id" value="<?= $c['id'] ?>">
    <input name="name" class="form-control form-control-sm" style="width:60px" placeholder="B" required><button class="btn btn-sm btn-light">+</button></form>
</td>
<td class="small"><?= h($c['next_name'] ?? '— (final class)') ?></td>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="<?= url('settings/classes', ['edit' => $c['id']]) ?>"><i class="bi bi-pencil"></i></a>
<form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="del_class"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete <?= h($c['name']) ?>?"><i class="bi bi-trash"></i></button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div></div></div></div>
<div class="col-lg-4"><form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit class' : 'Add class' ?></div><div class="card-body">
<?= csrf_field() ?><input type="hidden" name="act" value="class"><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
<div class="mb-2"><label class="form-label req">Class name</label><input name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="Class 6"></div>
<div class="mb-2"><label class="form-label">Display order</label><input type="number" name="sort_order" class="form-control" value="<?= h($edit['sort_order'] ?? $nextOrder) ?>"></div>
<?php if (!$edit): ?><div class="mb-2"><label class="form-label">Sections</label><input name="sections" class="form-control" value="A" placeholder="A,B,C"></div><?php endif; ?>
<div class="mb-3"><label class="form-label">Students promote to</label><select name="next_class_id" class="form-select"><?= select_options(array_filter($classes, fn($c) => !$edit || $c['id'] != $edit['id']), $edit['next_class_id'] ?? '', 'id', 'name', '— Final class / none —') ?></select></div>
<button class="btn btn-primary">Save</button> <?php if ($edit): ?><a href="<?= url('settings/classes') ?>" class="btn btn-light">Cancel</a><?php endif; ?>
</div></form></div>
</div>

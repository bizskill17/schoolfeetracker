<?php
require_perm('settings');
$title = 'Roles & Permissions';
if (is_post()) {
    try {
        $id = (int)post('id');
        $role = $id ? row('SELECT * FROM roles WHERE id = ?', [$id]) : null;
        if (post('act') === 'delete') {
            if (!$role || $role['is_system']) throw new RuntimeException('This role cannot be deleted.');
            if (val('SELECT COUNT(*) FROM users WHERE role_id = ?', [$id])) throw new RuntimeException('Users have this role. Give them another role first.');
            q('DELETE FROM roles WHERE id = ?', [$id]);
            audit('role.delete', 'role', $id, $role['name']);
            flash('ok', 'Role deleted.');
        } else {
            $name = trim((string)post('name'));
            if ($name === '') throw new RuntimeException('Enter a role name.');
            $perms = array_values(array_intersect(array_keys(PERMISSION_LABELS), (array)($_POST['perms'] ?? [])));
            if ($role && $role['is_system']) {
                update('roles', ['name' => $name], 'id = ?', [$id]);   // Administrator always keeps every permission
            } else {
                if (in_array('fees.collect', $perms, true) && !in_array('fees.view', $perms, true)) $perms[] = 'fees.view';
                if (array_intersect(['students.edit', 'students.delete', 'students.promote', 'fees.view'], $perms) && !in_array('students.view', $perms, true)) $perms[] = 'students.view';
                $d = ['name' => $name, 'perms' => json_encode($perms), 'section_scoped' => post('section_scoped') ? 1 : 0];
                if ($role) update('roles', $d, 'id = ?', [$id]); else $id = insert('roles', $d);
            }
            audit('role.save', 'role', $id, ['name' => $name, 'perms' => $perms, 'scoped' => post('section_scoped') ? 1 : 0]);
            flash('ok', 'Role saved. It applies the next time its users open a page.');
        }
    } catch (PDOException $e) { flash('err', 'A role with that name already exists.');
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('settings/roles', post('act') === 'delete' ? [] : ['edit' => $id ?? '']);
}
$roles = rows('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS users FROM roles r ORDER BY is_system DESC, name');
$edit = get('edit') ? row('SELECT * FROM roles WHERE id = ?', [(int)get('edit')]) : null;
$editPerms = $edit ? ($edit['is_system'] ? array_keys(PERMISSION_LABELS) : (json_decode((string)$edit['perms'], true) ?: [])) : ['students.view', 'fees.view'];
$groups = [];
foreach (PERMISSION_LABELS as $k => [$g, $l]) $groups[$g][$k] = $l;
?>
<div class="row g-3">
<div class="col-lg-5">
  <div class="card"><div class="list-group list-group-flush">
  <?php foreach ($roles as $r): $pl = $r['is_system'] ? count(PERMISSION_LABELS) : count(json_decode((string)$r['perms'], true) ?: []); ?>
    <a class="list-group-item list-group-item-action <?= $edit && $edit['id'] == $r['id'] ? 'active' : '' ?>" href="<?= url('settings/roles', ['edit' => $r['id']]) ?>">
      <div class="d-flex justify-content-between"><b><?= h($r['name']) ?></b><span class="small"><?= (int)$r['users'] ?> user<?= $r['users'] == 1 ? '' : 's' ?></span></div>
      <div class="small <?= $edit && $edit['id'] == $r['id'] ? '' : 'text-muted' ?>"><?= $r['is_system'] ? 'Full access (cannot be limited)' : $pl . ' permissions' ?><?= $r['section_scoped'] ? ' · only their own class/sections' : '' ?></div></a>
  <?php endforeach; ?>
  </div></div>
  <a href="<?= url('settings/roles') ?>" class="btn btn-outline-primary btn-sm mt-2"><i class="bi bi-plus-lg"></i> New role</a>
  <p class="small text-muted mt-2">Assign roles to people in <a href="<?= url('settings/users') ?>">Users</a>. For a class teacher, also pick their class and section there.</p>
</div>
<div class="col-lg-7">
<form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit role' : 'New role' ?></div><div class="card-body">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
<div class="mb-3"><label class="form-label req">Role name</label><input name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. Accountant, Principal"></div>
<?php if ($edit && $edit['is_system']): ?>
  <div class="alert alert-light border small">Administrator always has every permission so the school can never lock itself out.</div>
<?php else: ?>
  <div class="form-check form-switch mb-3 p-2 ps-5 border rounded bg-light"><input class="form-check-input" type="checkbox" name="section_scoped" value="1" id="sc" <?= !empty($edit['section_scoped']) ? 'checked' : '' ?>>
    <label class="form-check-label" for="sc"><b>Only their own class / sections</b><br><span class="small text-muted">Users with this role see only the students, dues, receipts and reports of the sections assigned to them.</span></label></div>
  <?php foreach ($groups as $g => $items): ?>
    <div class="form-section"><?= h($g) ?></div>
    <?php foreach ($items as $k => $l): ?>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="perms[]" value="<?= $k ?>" id="p_<?= str_replace('.', '_', $k) ?>" <?= in_array($k, $editPerms, true) ? 'checked' : '' ?>>
      <label class="form-check-label" for="p_<?= str_replace('.', '_', $k) ?>"><?= h($l) ?></label></div>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php endif; ?>
<div class="mt-3 d-flex gap-2"><button class="btn btn-primary">Save role</button></div>
</div></form>
<?php if ($edit && !$edit['is_system']): ?><form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $edit['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete role <?= h($edit['name']) ?>?">Delete role</button></form><?php endif; ?>
</div>
</div>

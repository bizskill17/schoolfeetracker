<?php
require_perm('settings');
$title = 'Users';
$roles = rows('SELECT * FROM roles ORDER BY is_system DESC, name');
$roleById = array_column($roles, null, 'id');
if (is_post()) {
    try {
        $id = (int)post('id');
        $d = ['name' => post('name'), 'username' => post('username'), 'role_id' => (int)post('role_id'), 'phone' => post('phone'), 'active' => post('active') ? 1 : 0];
        if (!$d['name'] || !preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $d['username'])) throw new RuntimeException('Enter a name and a username (3–50 letters, numbers, dot, underscore).');
        if (!isset($roleById[$d['role_id']])) throw new RuntimeException('Choose a role.');
        $pw = (string)post('password');
        if ($pw !== '' && strlen($pw) < 8) throw new RuntimeException('Password must be at least 8 characters.');
        if ($id === (int)$_SESSION['uid'] && (!$roleById[$d['role_id']]['is_system'] || !$d['active'])) throw new RuntimeException('You cannot remove your own Administrator access.');
        $secs = array_map('intval', (array)($_POST['sections'] ?? []));
        if ($roleById[$d['role_id']]['section_scoped'] && !$secs) throw new RuntimeException('Choose the class / section(s) this ' . $roleById[$d['role_id']]['name'] . ' is responsible for.');
        if ($pw !== '') $d['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
        db()->beginTransaction();
        if ($id) update('users', $d, 'id = ?', [$id]);
        else {
            if ($pw === '') throw new RuntimeException('Set a password for the new user.');
            $id = insert('users', $d);
        }
        q('DELETE FROM user_sections WHERE user_id = ?', [$id]);
        if ($roleById[$d['role_id']]['section_scoped']) foreach ($secs as $sid) q('INSERT IGNORE INTO user_sections (user_id, section_id) VALUES (?, ?)', [$id, $sid]);
        db()->commit();
        unset($d['password_hash']);
        audit('user.save', 'user', $id, $d + ['sections' => $secs, 'password_changed' => $pw !== '']);
        flash('ok', 'User saved.');
    } catch (PDOException $e) { if (db()->inTransaction()) db()->rollBack(); flash('err', 'That username is taken.');
    } catch (RuntimeException $e) { if (db()->inTransaction()) db()->rollBack(); flash('err', $e->getMessage()); }
    redirect('settings/users');
}
$list = rows("SELECT u.*, r.name AS role_name, r.section_scoped,
              (SELECT GROUP_CONCAT(CONCAT(c.name, ' - ', s.name) ORDER BY c.sort_order, s.name SEPARATOR ', ') FROM user_sections us JOIN sections s ON s.id = us.section_id JOIN classes c ON c.id = s.class_id WHERE us.user_id = u.id) AS secs
              FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.active DESC, u.name");
$edit = get('edit') ? row('SELECT * FROM users WHERE id = ?', [(int)get('edit')]) : null;
$editSecs = $edit ? array_map('intval', array_column(rows('SELECT section_id FROM user_sections WHERE user_id = ?', [$edit['id']]), 'section_id')) : [];
$allSecs = rows('SELECT s.id, s.name, c.name AS class_name FROM sections s JOIN classes c ON c.id = s.class_id ORDER BY c.sort_order, s.name');
$byClass = [];
foreach ($allSecs as $sc) $byClass[$sc['class_name']][] = $sc;
$defaultRole = $edit['role_id'] ?? (val("SELECT id FROM roles WHERE section_scoped = 1 ORDER BY id LIMIT 1") ?: $roles[0]['id']);
?>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 align-middle">
<thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Class / sections</th><th></th></tr></thead><tbody>
<?php foreach ($list as $u): ?>
<tr class="<?= $u['active'] ? '' : 'text-muted' ?>"><td><?= h($u['name']) ?> <?= $u['active'] ? '' : '<span class="badge text-bg-secondary">Disabled</span>' ?><div class="small text-muted">Last login <?= $u['last_login'] ? h(date('d-m-Y H:i', strtotime($u['last_login']))) : '—' ?></div></td>
<td><?= h($u['username']) ?></td><td><?= h($u['role_name'] ?? '—') ?></td>
<td class="small"><?= $u['section_scoped'] ? ($u['secs'] ? h($u['secs']) : '<span class="text-danger">None assigned</span>') : '<span class="text-muted">All</span>' ?></td>
<td class="text-end"><a class="btn btn-sm btn-light" href="<?= url('settings/users', ['edit' => $u['id']]) ?>"><i class="bi bi-pencil"></i></a></td></tr>
<?php endforeach; ?>
</tbody></table></div></div></div>
<p class="small text-muted mt-2">What each role may do is set in <a href="<?= url('settings/roles') ?>">Roles &amp; Permissions</a>.</p></div>
<div class="col-lg-5"><form method="post" class="card" autocomplete="off"><div class="card-header"><?= $edit ? 'Edit user' : 'Add user' ?></div><div class="card-body">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
<div class="mb-2"><label class="form-label req">Full name</label><input name="name" class="form-control" required value="<?= h($edit['name'] ?? '') ?>"></div>
<div class="mb-2"><label class="form-label req">Username</label><input name="username" class="form-control" required value="<?= h($edit['username'] ?? '') ?>" autocomplete="off"></div>
<div class="mb-2"><label class="form-label">Mobile</label><input name="phone" class="form-control" value="<?= h($edit['phone'] ?? '') ?>"></div>
<div class="mb-2"><label class="form-label req">Role</label><select name="role_id" id="roleSel" class="form-select">
<?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>" data-scoped="<?= (int)$r['section_scoped'] ?>" <?= (string)$defaultRole === (string)$r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option><?php endforeach; ?></select></div>
<div class="mb-2" id="secBox"><label class="form-label req">Class / section(s) in charge of</label>
  <div class="border rounded p-2" style="max-height:220px;overflow:auto">
  <?php foreach ($byClass as $cn => $list2): ?><div class="d-flex flex-wrap align-items-center gap-2 mb-1"><span class="small fw-semibold" style="width:80px"><?= h($cn) ?></span>
    <?php foreach ($list2 as $sc): ?><label class="form-check form-check-inline mb-0 small"><input class="form-check-input" type="checkbox" name="sections[]" value="<?= $sc['id'] ?>" <?= in_array((int)$sc['id'], $editSecs, true) ? 'checked' : '' ?>> <?= h($sc['name']) ?></label><?php endforeach; ?></div>
  <?php endforeach; ?></div></div>
<div class="mb-2"><label class="form-label <?= $edit ? '' : 'req' ?>"><?= $edit ? 'New password (leave blank to keep)' : 'Password' ?></label><input name="password" type="password" class="form-control" minlength="8" autocomplete="new-password"></div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active" value="1" id="ac" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>><label for="ac" class="form-check-label">Active (can log in)</label></div>
<button class="btn btn-primary">Save</button> <?php if ($edit): ?><a href="<?= url('settings/users') ?>" class="btn btn-light">Cancel</a><?php endif; ?>
</div></form></div>
</div>
<script>(function(){const r=document.getElementById('roleSel'),b=document.getElementById('secBox');const t=()=>b.style.display=r.selectedOptions[0].dataset.scoped==='1'?'':'none';r.addEventListener('change',t);t();})();</script>

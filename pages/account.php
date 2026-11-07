<?php
$title = 'Change Password';
if (is_post()) {
    $u = user();
    if (!password_verify((string)post('current'), $u['password_hash'])) flash('err', 'Current password is wrong.');
    elseif (strlen((string)post('new')) < 8) flash('err', 'New password must be at least 8 characters.');
    elseif (post('new') !== post('new2')) flash('err', 'New passwords do not match.');
    else {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)post('new'), PASSWORD_DEFAULT), $u['id']]);
        audit('user.password', 'user', (int)$u['id']);
        flash('ok', 'Password changed.');
    }
    redirect('account');
}
?>
<form method="post" class="card" style="max-width:420px"><div class="card-body">
<?= csrf_field() ?>
<div class="mb-2"><label class="form-label">Current password</label><input type="password" name="current" class="form-control" required></div>
<div class="mb-2"><label class="form-label">New password (min 8)</label><input type="password" name="new" class="form-control" required minlength="8"></div>
<div class="mb-3"><label class="form-label">Repeat new password</label><input type="password" name="new2" class="form-control" required></div>
<button class="btn btn-primary">Change password</button>
</div></form>

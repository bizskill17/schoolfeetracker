<?php
if (user()) redirect('dashboard');
$error = '';
if (is_post()) {
    csrf_check();
    try {
        if (attempt_login((string)post('username'), (string)post('password'))) redirect('dashboard');
        $error = 'Wrong username or password.';
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$school = setting('school_name', 'School Manager');
$logo = setting('school_logo', '');
ob_start(); ?>
<div class="login-wrap">
  <div class="card login-card shadow">
    <div class="card-body p-4">
      <div class="text-center mb-3">
        <?php if ($logo): ?><img src="file.php?f=<?= h($logo) ?>" style="height:64px" alt=""><?php else: ?><i class="bi bi-mortarboard-fill" style="font-size:3rem;color:#1f3864"></i><?php endif; ?>
        <h5 class="mt-2 mb-0"><?= h($school) ?></h5>
        <div class="text-muted small">Student &amp; Fee Management</div>
      </div>
      <?= flashes() ?>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" required autofocus value="<?= h(post('username')) ?>"></div>
        <div class="mb-3"><label class="form-label">Password</label><input name="password" type="password" class="form-control" required></div>
        <button class="btn btn-primary w-100">Log in</button>
      </form>
    </div>
  </div>
</div>
<?php render_bare('Login', ob_get_clean());

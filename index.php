<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$page = (string)($_GET['p'] ?? 'dashboard');
if (!preg_match('#^[a-z_]+(/[a-z_]+)?$#', $page)) $page = 'dashboard';

// Public pages
if ($page === 'login') { require APP_ROOT . '/pages/login.php'; exit; }
if ($page === 'logout') { logout(); header('Location: index.php?p=login'); exit; }

// Everything else needs a logged-in user
if (!user()) { header('Location: index.php?p=login'); exit; }

// Auto logout after 2 hours of inactivity
if (time() - ($_SESSION['last_seen'] ?? time()) > 7200) {
    logout();
    session_start();
    flash('info', 'You were logged out after 2 hours of inactivity.');
    header('Location: index.php?p=login'); exit;
}
$_SESSION['last_seen'] = time();

if (is_post()) csrf_check();

if ($page === 'year_switch') {
    $id = (int)post('year_id');
    if (val('SELECT id FROM academic_years WHERE id = ?', [$id])) $_SESSION['year_id'] = $id;
    $back = (string)post('back');
    header('Location: ' . (preg_match('#^/[^/\\\\]#', $back) ? $back : 'index.php'));
    exit;
}

$file = APP_ROOT . '/pages/' . $page . '.php';
if (!is_file($file)) { http_response_code(404); $page = 'dashboard'; $file = APP_ROOT . '/pages/dashboard.php'; }

$title = 'Dashboard';
$bare = false;
ob_start();
require $file;
$content = ob_get_clean();
if ($bare) render_bare($title, $content);
else render_page($title, $content, $page);

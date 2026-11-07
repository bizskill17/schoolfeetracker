<?php
// Serves uploaded files. Student photos and documents need a login; the school logo is public.
require __DIR__ . '/inc/bootstrap.php';
$f = (string)($_GET['f'] ?? '');
if (!preg_match('#^(public|photos|docs)/[a-f0-9]{20}\.(jpg|jpeg|png|webp|pdf)$#', $f)) { http_response_code(404); exit; }
if (strpos($f, 'public/') !== 0 && !user()) { http_response_code(403); exit; }
$path = APP_ROOT . '/uploads/' . $f;
if (!is_file($path)) { http_response_code(404); exit; }
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
header('Content-Type: ' . $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
if (!empty($_GET['download'])) header('Content-Disposition: attachment; filename="' . basename($path) . '"');
readfile($path);

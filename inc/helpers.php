<?php

function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function money($n, bool $symbol = true): string
{
    $n = round((float)$n, 2);
    $neg = $n < 0; $n = abs($n);
    $int = (string)floor($n);
    $dec = sprintf('%02d', round(($n - floor($n)) * 100));
    // Indian grouping: 12,34,567
    $last3 = substr($int, -3);
    $rest = substr($int, 0, -3);
    if ($rest !== '' && $rest !== false) {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    $out = $int . ($dec !== '00' ? '.' . $dec : '');
    return ($neg ? '-' : '') . ($symbol ? '₹' : '') . $out;
}

function amount_in_words(float $num): string
{
    $num = round($num, 2);
    $rupees = (int)floor($num);
    $paise = (int)round(($num - $rupees) * 100);
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $n) use ($ones, $tens) {
        if ($n < 20) return $ones[$n];
        return trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };
    $three = function (int $n) use ($two, $ones) {
        $s = '';
        if ($n >= 100) { $s = $ones[intdiv($n, 100)] . ' Hundred'; $n %= 100; if ($n) $s .= ' '; }
        return $s . ($n ? $two($n) : '');
    };
    if ($rupees === 0) $words = 'Zero';
    else {
        $parts = [];
        $crore = intdiv($rupees, 10000000); $rupees %= 10000000;
        $lakh = intdiv($rupees, 100000); $rupees %= 100000;
        $thousand = intdiv($rupees, 1000); $rupees %= 1000;
        if ($crore) $parts[] = $three($crore) . ' Crore';
        if ($lakh) $parts[] = $two($lakh) . ' Lakh';
        if ($thousand) $parts[] = $two($thousand) . ' Thousand';
        if ($rupees) $parts[] = $three($rupees);
        $words = implode(' ', $parts);
    }
    $out = 'Rupees ' . $words;
    if ($paise) $out .= ' and ' . $two($paise) . ' Paise';
    return $out . ' Only';
}

function dmy($d): string
{
    if (!$d || $d === '0000-00-00') return '';
    return date('d-m-Y', strtotime((string)$d));
}

function today(): string { return date('Y-m-d'); }

function url(string $page, array $params = []): string
{
    return 'index.php?' . http_build_query(array_merge(['p' => $page], $params));
}

function redirect(string $page, array $params = []): void
{
    header('Location: ' . url($page, $params));
    exit;
}

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }

function flashes(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$t, $m]) {
        $cls = ['ok' => 'success', 'err' => 'danger', 'warn' => 'warning', 'info' => 'info'][$t] ?? 'info';
        $out .= '<div class="alert alert-' . $cls . ' alert-dismissible fade show py-2" role="alert">' . h($m) .
            '<button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

function post(string $k, $default = '') { return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $default; }
function get(string $k, $default = '') { return isset($_GET[$k]) ? (is_string($_GET[$k]) ? trim($_GET[$k]) : $_GET[$k]) : $default; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
function nullif($v) { return ($v === '' || $v === null) ? null : $v; }

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expired. Please go back, refresh the page and try again.');
    }
}

function setting(string $key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (rows('SELECT skey, svalue FROM settings') as $r) $cache[$r['skey']] = $r['svalue'];
    }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, $value): void
{
    q('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, (string)$value]);
}

function audit(string $action, string $entity = '', ?int $id = null, $details = ''): void
{
    if (is_array($details)) $details = json_encode($details, JSON_UNESCAPED_UNICODE);
    insert('audit_log', [
        'user_id' => $_SESSION['uid'] ?? null, 'action' => $action, 'entity' => $entity,
        'entity_id' => $id, 'details' => mb_substr((string)$details, 0, 5000), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

function current_year(): ?array
{
    static $y = false;
    if ($y === false) $y = row('SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1');
    return $y;
}

/** Year chosen in the top bar (defaults to the current year). */
function working_year(): ?array
{
    if (!empty($_SESSION['year_id'])) {
        $y = row('SELECT * FROM academic_years WHERE id = ?', [$_SESSION['year_id']]);
        if ($y) return $y;
    }
    return current_year();
}

function select_options(array $items, $selected, string $valKey = 'id', string $labelKey = 'name', string $empty = ''): string
{
    $out = $empty !== '' ? '<option value="">' . h($empty) . '</option>' : '';
    foreach ($items as $it) {
        $v = is_array($it) ? $it[$valKey] : $it;
        $l = is_array($it) ? $it[$labelKey] : $it;
        $out .= '<option value="' . h($v) . '"' . ((string)$v === (string)$selected ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    return $out;
}

function student_name(array $s): string { return trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')); }

function class_label(array $r): string
{
    return trim(($r['class_name'] ?? '') . (!empty($r['section_name']) ? ' - ' . $r['section_name'] : ''));
}

function payment_modes(): array
{
    return lookup_names('payment_mode');   // Masters → Dropdown Lists → Payment Mode
}

function csv_download(string $filename, array $header, array $data): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($data as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit;
}

function wa_link(?string $phone, string $msg): string
{
    $p = preg_replace('/\D/', '', (string)$phone);
    if (strlen($p) === 10) $p = '91' . $p;
    return 'https://wa.me/' . $p . '?text=' . rawurlencode($msg);
}

function paginate(int $total, int $per, int $page, array $params): string
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return '';
    $out = '<nav><ul class="pagination pagination-sm flex-wrap">';
    for ($i = 1; $i <= $pages; $i++) {
        if ($pages > 12 && abs($i - $page) > 3 && $i !== 1 && $i !== $pages) {
            if ($i === 2 || $i === $pages - 1) $out .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            continue;
        }
        $params['pg'] = $i;
        $out .= '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="index.php?' . h(http_build_query($params)) . '">' . $i . '</a></li>';
    }
    return $out . '</ul></nav>';
}

function save_upload(string $field, string $dir, array $allowedExt, int $maxBytes = 2097152): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Try a smaller file.');
    if ($f['size'] > $maxBytes) throw new RuntimeException('File is too large (max ' . round($maxBytes / 1048576) . ' MB).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) throw new RuntimeException('File type not allowed. Use: ' . implode(', ', $allowedExt));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($f['tmp_name']) === false) {
        throw new RuntimeException('That file is not a valid image.');
    }
    if (!is_file(APP_ROOT . '/uploads/.htaccess')) {   // the FTP deploy does not upload this folder, so protect it here
        @mkdir(APP_ROOT . '/uploads', 0755, true);
        @file_put_contents(APP_ROOT . '/uploads/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\nOptions -Indexes\n");
        @file_put_contents(APP_ROOT . '/uploads/index.html', '');
    }
    $target = APP_ROOT . '/uploads/' . $dir;
    if (!is_dir($target)) mkdir($target, 0755, true);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $target . '/' . $name)) throw new RuntimeException('Could not save the file.');
    return $dir . '/' . $name;
}

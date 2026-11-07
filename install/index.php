<?php
declare(strict_types=1);
// One-time installer. Delete the /install folder after setup.
$root = dirname(__DIR__);
$lock = __DIR__ . '/installed.lock';
$msg = ''; $ok = false;
require $root . '/inc/config_loader.php';
$serverCfg = load_config();   // already set on the server (environment variables or a config file)

// Every table this app uses. The installer never touches any other table in the database.
const APP_TABLES = ['settings', 'counters', 'users', 'academic_years', 'classes', 'sections', 'fee_heads', 'fee_structure', 'transport_routes',
    'concession_types', 'students', 'student_documents', 'enrollments', 'student_concessions', 'fee_demands', 'receipts', 'receipt_items',
    'refunds', 'cheque_bounces', 'audit_log', 'lookups', 'roles', 'user_sections', 'fee_frequencies', 'late_fee_rules', 'late_fee_slabs'];

function hh($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Student photos and documents must never be reachable directly from a browser. */
function protect_uploads(string $root): void
{
    @mkdir($root . '/uploads', 0755, true);
    @file_put_contents($root . '/uploads/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\nOptions -Indexes\n");
    @file_put_contents($root . '/uploads/index.html', '');
}

/** Save DB settings outside the web folder when possible (cannot be downloaded), else in config.php (blocked by .htaccess). */
function write_config(array $c): string
{
    $body = "<?php\n// School Manager database settings. Keep this file private. Do not commit it to Git.\nreturn " . var_export($c, true) . ";\n";
    foreach (config_paths() as $where => $path) {
        if (@file_put_contents($path, $body, LOCK_EX) !== false) { @chmod($path, 0600); return $where === 'outside' ? $path . ' (outside the web folder)' : $path; }
    }
    throw new RuntimeException('Could not save the database settings. Create schoolfee-config.php one folder above the app (see config.sample.php).');
}

if (file_exists($lock)) {
    $msg = 'Already installed. For security, delete the "install" folder from your server.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST);
    try {
        $cfg = $serverCfg ?: ['db_host' => $in['db_host'] ?: 'localhost', 'db_port' => (int)($in['db_port'] ?: 3306), 'db_name' => $in['db_name'] ?? '',
            'db_user' => $in['db_user'] ?? '', 'db_pass' => $in['db_pass'] ?? '', 'timezone' => 'Asia/Kolkata', 'debug' => false];
        if (!$cfg['db_name'] || !$cfg['db_user']) throw new RuntimeException('Enter the database name and user.');
        $pdo = new PDO(db_dsn($cfg), $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $existing = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $clash = array_values(array_intersect(APP_TABLES, $existing));

        if ($clash) {
            // Never overwrite or change existing data. Only connect if it is clearly this app's own data.
            $isOurs = in_array('settings', $clash, true) && in_array('users', $clash, true) && in_array('fee_demands', $clash, true)
                && (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE skey = 'school_name'")->fetchColumn() > 0;
            if (!$isOurs || empty($in['connect_existing'])) {
                throw new RuntimeException('Nothing was changed. The database already has tables named: ' . implode(', ', $clash) . '. '
                    . ($isOurs ? 'They look like School Manager data – tick "Connect to the existing School Manager data" to use them as they are.'
                               : 'They belong to another application, so the installer will not touch them. Use an empty database (or ask your developer).'));
            }
            if (!$serverCfg) $saved = write_config($cfg);
            protect_uploads($root);
            file_put_contents($lock, date('c'));
            $ok = true;
            $msg = 'Connected to the existing School Manager data. No records were changed.' . (!empty($saved) ? ' Settings saved to ' . $saved . '.' : '') . ' Delete the "install" folder, then log in with your existing account.';
            goto done;
        }
        if (($in['school_name'] ?? '') === '') throw new RuntimeException('Enter the school name.');
        if (strlen($in['admin_pass'] ?? '') < 8) throw new RuntimeException('Admin password must be at least 8 characters.');
        if (($in['admin_pass'] ?? '') !== ($in['admin_pass2'] ?? '')) throw new RuntimeException('Admin passwords do not match.');
        if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $in['admin_user'] ?? '')) throw new RuntimeException('Admin username: 3–50 letters, numbers, dot or underscore.');
        $schema = require __DIR__ . '/schema.php';
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $schema)))) as $stmt) $pdo->exec($stmt);


        $set = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
        foreach ([
            'school_name' => $in['school_name'], 'school_address' => $in['school_address'] ?? '', 'school_phone' => $in['school_phone'] ?? '',
            'school_email' => '', 'school_board' => '', 'school_affiliation' => '', 'receipt_prefix' => 'RCP',
            'receipt_note' => 'Fees once paid are not refundable. Please keep this receipt safe.',
            'fee_due_day' => '10', 'late_fee_type' => 'none', 'late_fee_amount' => '0', 'late_fee_grace_days' => '0', 'late_fee_max' => '0',
            'rte_full_waiver' => '1', 'admission_prefix' => 'ADM', 'reminder_text' => 'Dear Parent, fee of {amount} is due for {student} ({class}). Kindly pay at the earliest. – {school}',
        ] as $k => $v) $set->execute([$k, $v]);

        $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([$in['admin_name'] ?: 'Administrator', $in['admin_user'], password_hash($in['admin_pass'], PASSWORD_DEFAULT), 'admin']);

        $y = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
        $pdo->prepare('INSERT INTO academic_years (name, start_date, end_date, is_current) VALUES (?, ?, ?, 1)')
            ->execute([$y . '-' . substr((string)($y + 1), 2), $y . '-04-01', ($y + 1) . '-03-31']);

        if (!empty($in['seed'])) {
            $classes = ['Nursery', 'LKG', 'UKG', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10', 'Class 11', 'Class 12'];
            $ins = $pdo->prepare('INSERT INTO classes (name, sort_order) VALUES (?, ?)');
            $sec = $pdo->prepare('INSERT INTO sections (class_id, name) VALUES (?, ?)');
            $ids = [];
            $secNames = array_values(array_unique(array_filter(array_map(fn($x) => strtoupper(trim($x)), explode(',', (string)($in['sections'] ?? 'A'))))));
            if (!$secNames) $secNames = ['A'];
            foreach ($classes as $i => $c) { $ins->execute([$c, $i + 1]); $ids[] = (int)$pdo->lastInsertId(); foreach ($secNames as $sn) $sec->execute([end($ids), $sn]); }
            $nx = $pdo->prepare('UPDATE classes SET next_class_id = ? WHERE id = ?');
            for ($i = 0; $i < count($ids) - 1; $i++) $nx->execute([$ids[$i + 1], $ids[$i]]);
            $fh = $pdo->prepare('INSERT INTO fee_heads (name, frequency, applies_to, is_transport, refundable, late_fee_applies, sort_order) VALUES (?,?,?,?,?,?,?)');
            foreach ([
                ['Admission Fee', 'one_time', 'new', 0, 0, 0, 1],
                ['Caution Deposit', 'one_time', 'new', 0, 1, 0, 2],
                ['Annual / Development Fee', 'yearly', 'all', 0, 0, 1, 3],
                ['Tuition Fee', 'monthly', 'all', 0, 0, 1, 4],
                ['Exam Fee', 'half_yearly', 'all', 0, 0, 1, 5],
                ['Transport Fee', 'monthly', 'all', 1, 0, 1, 6],
                ['Books & Stationery', 'one_time', 'all', 0, 0, 0, 7],
                ['Uniform', 'one_time', 'all', 0, 0, 0, 8],
                ['Fine / Miscellaneous', 'one_time', 'all', 0, 0, 0, 9],
            ] as $r) $fh->execute($r);
            $ct = $pdo->prepare('INSERT INTO concession_types (name, kind, value, fee_head_id) VALUES (?, ?, ?, (SELECT id FROM fee_heads WHERE name = ?))');
            foreach ([['Sibling Concession', 'percent', 10, 'Tuition Fee'], ['Staff Ward', 'percent', 50, 'Tuition Fee'],
                      ['Merit Scholarship', 'percent', 25, 'Tuition Fee'], ['Management Discretion', 'fixed', 0, null]] as $r) $ct->execute($r);
        }
        $pdo->exec("INSERT IGNORE INTO fee_heads (name, frequency, is_system, sort_order, late_fee_applies) VALUES ('Previous Year Dues','one_time',1,0,1), ('Cheque Bounce Charges','one_time',1,998,0)");

        $saved = $serverCfg ? 'server environment (nothing written)' : write_config($cfg);
        protect_uploads($root);
        file_put_contents($lock, date('c'));
        $ok = true;
        $msg = 'Installation complete. Database settings: ' . $saved . '. Delete the "install" folder from your server now, then log in.';
        done:
    } catch (Throwable $e) {
        $msg = 'Error: ' . $e->getMessage();
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install – School Manager</title>
<link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-4" style="max-width:720px">
<h3>School Manager – Setup</h3>
<?php if ($msg): ?><div class="alert alert-<?= $ok ? 'success' : (file_exists($lock) ? 'info' : 'danger') ?>"><?= hh($msg) ?></div><?php endif; ?>
<?php if ($ok || file_exists($lock)): ?><a class="btn btn-primary" href="../index.php">Go to login</a>
<?php else: ?>
<form method="post" class="card card-body">
  <h6>1. Database</h6>
  <?php if ($serverCfg): ?>
  <div class="alert alert-success small">Database settings were found on the server (<?= hh($serverCfg['db_name']) ?> on <?= hh($serverCfg['db_host']) ?>). They will be used as they are.</div>
  <?php else: ?>
  <p class="small text-muted">From Hostinger hPanel → Databases → MySQL Databases. The password is saved only on your server, outside the web folder, and is never shown again.</p>
  <div class="row g-2 mb-3">
    <div class="col-8 col-md-4"><label class="form-label">Host</label><input name="db_host" class="form-control" value="<?= hh($_POST['db_host'] ?? 'localhost') ?>" required></div>
    <div class="col-4 col-md-2"><label class="form-label">Port</label><input name="db_port" type="number" class="form-control" value="<?= hh($_POST['db_port'] ?? '3306') ?>"></div>
    <div class="col-md-6"><label class="form-label">Database name</label><input name="db_name" class="form-control" value="<?= hh($_POST['db_name'] ?? '') ?>" required></div>
    <div class="col-md-6"><label class="form-label">Database user</label><input name="db_user" class="form-control" value="<?= hh($_POST['db_user'] ?? '') ?>" required></div>
    <div class="col-md-6"><label class="form-label">Database password</label><input name="db_pass" type="password" class="form-control" autocomplete="off"></div>
  </div>
  <?php endif; ?>
  <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="connect_existing" value="1" id="ce" <?= !empty($_POST['connect_existing']) ? 'checked' : '' ?>>
    <label class="form-check-label small" for="ce">Connect to the existing School Manager data in this database (use this when re-installing the app files; nothing is changed). Leave unticked for a new school.</label></div>
  <h6>2. School</h6>
  <div class="row g-2 mb-3">
    <div class="col-12"><label class="form-label">School name</label><input name="school_name" class="form-control" value="<?= hh($_POST['school_name'] ?? '') ?>"></div>
    <div class="col-md-8"><label class="form-label">Address</label><input name="school_address" class="form-control" value="<?= hh($_POST['school_address'] ?? '') ?>"></div>
    <div class="col-md-4"><label class="form-label">Phone</label><input name="school_phone" class="form-control" value="<?= hh($_POST['school_phone'] ?? '') ?>"></div>
    <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="seed" value="1" id="seed" checked>
      <label class="form-check-label" for="seed">Add standard classes (Nursery–12), fee heads and concession types to start with</label></div>
    <div class="col-md-6"><label class="form-label">Sections in every class</label><input name="sections" class="form-control" value="<?= hh($_POST['sections'] ?? 'A,B,C') ?>">
      <div class="form-text">Comma separated. You can add or remove sections for each class later.</div></div>
  </div>
  <h6>3. Administrator login <span class="fw-normal small text-muted">(new school only)</span></h6>
  <div class="row g-2 mb-3">
    <div class="col-md-6"><label class="form-label">Your name</label><input name="admin_name" class="form-control" value="<?= hh($_POST['admin_name'] ?? '') ?>"></div>
    <div class="col-md-6"><label class="form-label">Username</label><input name="admin_user" class="form-control" value="<?= hh($_POST['admin_user'] ?? 'admin') ?>" required></div>
    <div class="col-md-6"><label class="form-label">Password (min 8)</label><input name="admin_pass" type="password" class="form-control" minlength="8"></div>
    <div class="col-md-6"><label class="form-label">Repeat password</label><input name="admin_pass2" type="password" class="form-control"></div>
  </div>
  <button class="btn btn-primary">Install</button>
</form>
<?php endif; ?>
</div></body></html>

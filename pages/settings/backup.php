<?php
require_perm('settings');
$title = 'Backup';
if (is_post() && post('act') === 'download') {
    audit('backup.download', 'settings');
    $tables = array_column(rows('SHOW TABLES'), array_key_first(rows('SHOW TABLES')[0]));
    $fn = 'school-backup-' . date('Y-m-d-His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $pdo = db();
    echo "-- School Manager backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $t) {
        $create = row("SHOW CREATE TABLE `$t`");
        echo "DROP TABLE IF EXISTS `$t`;\n" . $create['Create Table'] . ";\n\n";
        $st = $pdo->query("SELECT * FROM `$t`");
        $batch = [];
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            if (count($batch) >= 200) { echo "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n"; $batch = []; }
        }
        if ($batch) echo "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n";
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}
$counts = [
    'Students' => val('SELECT COUNT(*) FROM students'),
    'Receipts' => val('SELECT COUNT(*) FROM receipts'),
    'Fee instalments' => val('SELECT COUNT(*) FROM fee_demands'),
];
?>
<div class="card" style="max-width:720px"><div class="card-body">
<p>Download a full copy of the database (students, fees, receipts, settings). Keep it somewhere safe, such as Google Drive or a pen drive. <b>Take a backup at least once a week</b>, and always before the year-end promotion.</p>
<ul class="small"><?php foreach ($counts as $k => $v): ?><li><?= h($k) ?>: <?= (int)$v ?></li><?php endforeach; ?></ul>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="download"><button class="btn btn-primary"><i class="bi bi-download"></i> Download backup (.sql)</button></form>
<hr>
<p class="small text-muted mb-0">Student photos and documents are stored in the <code>uploads</code> folder; back that up from Hostinger File Manager. Hostinger also keeps automatic backups (hPanel → Files → Backups). To restore, import the .sql file in hPanel → Databases → phpMyAdmin.</p>
</div></div>

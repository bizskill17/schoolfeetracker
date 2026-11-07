<?php
/**
 * Masters for dropdown lists. Every list here is edited by the school in Masters → Dropdown Lists.
 * Nothing in the forms is hard-coded: the forms read their options from the `lookups` table.
 *
 * 'usage' says which column stores the value, so renaming a value updates existing records
 * and deleting is blocked while a value is in use.
 * 'flags' are extra yes/no settings a value can carry (used by payment modes).
 */
const LOOKUP_LISTS = [
    'gender' => ['Gender', 'Student details', [['students', 'gender']], [], ['Male', 'Female', 'Other']],
    'blood_group' => ['Blood Group', 'Student details', [['students', 'blood_group']], [], ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']],
    'category' => ['Category / Social Group', 'Student details', [['students', 'category']], [], ['General', 'OBC', 'SC', 'ST', 'EWS']],
    'religion' => ['Religion', 'Student details', [['students', 'religion']], [], ['Hindu', 'Muslim', 'Christian', 'Sikh', 'Buddhist', 'Jain', 'Parsi', 'Other']],
    'mother_tongue' => ['Mother Tongue', 'Student details', [['students', 'mother_tongue']], [], ['Hindi', 'Marathi', 'English', 'Gujarati', 'Tamil', 'Telugu', 'Kannada', 'Malayalam', 'Bengali', 'Punjabi', 'Urdu', 'Other']],
    'nationality' => ['Nationality', 'Student details', [['students', 'nationality']], [], ['Indian', 'Other']],
    'guardian_relation' => ['Guardian Relation', 'Parents / guardian', [['students', 'guardian_relation']], [], ['Grandfather', 'Grandmother', 'Uncle', 'Aunt', 'Brother', 'Sister', 'Other']],
    'occupation' => ['Parent Occupation', 'Parents / guardian (suggestions – typing other values is allowed)', [['students', 'father_occupation'], ['students', 'mother_occupation']], [], ['Service', 'Business', 'Farmer', 'Teacher', 'Doctor', 'Engineer', 'Homemaker', 'Self-employed', 'Labour', 'Government Employee']],
    'income_range' => ['Family Annual Income', 'Parents / guardian', [['students', 'annual_income']], [], ['Below ₹1 lakh', '₹1–2.5 lakh', '₹2.5–5 lakh', '₹5–10 lakh', 'Above ₹10 lakh']],
    'state' => ['State', 'Address', [['students', 'state']], [], ['Maharashtra', 'Karnataka', 'Gujarat', 'Madhya Pradesh', 'Goa', 'Telangana', 'Andhra Pradesh', 'Tamil Nadu', 'Kerala', 'Rajasthan', 'Uttar Pradesh', 'Delhi', 'West Bengal', 'Bihar', 'Punjab', 'Other']],
    'city' => ['City / Village', 'Address (suggestions – typing other values is allowed)', [['students', 'city']], [], []],
    'previous_class' => ['Class Passed (previous school)', 'Previous school', [['students', 'previous_class']], [], ['Nursery', 'LKG', 'UKG', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10', 'Class 11']],
    'document_type' => ['Document Type', 'Student documents', [['student_documents', 'title']], [], ['Birth Certificate', 'Aadhaar Card', 'Transfer Certificate', 'Previous Marksheet', 'Caste Certificate', 'Income Certificate', 'Address Proof', 'Parent ID Proof', 'Medical Certificate', 'Passport Photo', 'Other']],
    'leaving_reason' => ['Reason for Leaving', 'Student leaving / TC', [['students', 'leaving_reason']], [], ['Relocation / transfer of parent', 'Admission in another school', 'Financial reasons', 'Completed final class', 'Health reasons', 'Not satisfied', 'Other']],
    'payment_mode' => ['Payment Mode', 'Fee collection', [['receipts', 'mode'], ['refunds', 'mode']],
        ['cash' => 'Cash (counted in cash hand-over)', 'ref' => 'Reference number required', 'cheque' => 'Cheque / DD (can bounce)'],
        [['Cash', ['cash' => 1]], ['UPI', ['ref' => 1]], ['Cheque', ['ref' => 1, 'cheque' => 1]], ['DD', ['ref' => 1, 'cheque' => 1]], ['Bank Transfer', ['ref' => 1]], ['Card', []], ['Online', []]]],
    'bank' => ['Bank Name', 'Fee collection – cheque / transfer (suggestions)', [['receipts', 'bank_name']], [], ['State Bank of India', 'HDFC Bank', 'ICICI Bank', 'Axis Bank', 'Bank of Maharashtra', 'Bank of Baroda', 'Punjab National Bank', 'Canara Bank', 'Union Bank of India', 'Kotak Mahindra Bank']],
    'cancel_reason' => ['Receipt Cancel Reason', 'Receipts (suggestions)', [], [], ['Wrong amount entered', 'Wrong student selected', 'Duplicate receipt', 'Payment not received']],
    'waiver_reason' => ['Fee Waiver Reason', 'Fee waivers and concessions (suggestions)', [], [], ['Approved by Principal', 'Financial hardship', 'Management decision', 'Staff ward', 'Sibling']],
];

function lookup_label(string $key): string { return LOOKUP_LISTS[$key][0] ?? $key; }

/** Values of a list, in display order. */
function lookup_values(string $key, bool $activeOnly = true): array
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $cache[$key] = rows('SELECT * FROM lookups WHERE list_key = ? ORDER BY sort_order, value', [$key]);
        foreach ($cache[$key] as &$r) $r['flags'] = $r['meta'] ? (json_decode($r['meta'], true) ?: []) : [];
        unset($r);
    }
    return $activeOnly ? array_values(array_filter($cache[$key], fn($r) => (int)$r['active'] === 1)) : $cache[$key];
}

function lookup_names(string $key): array { return array_column(lookup_values($key), 'value'); }

/** <option> list; keeps the record's current value even if it was later deactivated. */
function lookup_options(string $key, $selected, string $empty = '—'): string
{
    $vals = lookup_names($key);
    if ($selected !== null && $selected !== '' && !in_array($selected, $vals, true)) $vals[] = (string)$selected;
    $out = $empty !== '' ? '<option value="">' . h($empty) . '</option>' : '';
    foreach ($vals as $v) $out .= '<option' . ((string)$v === (string)$selected ? ' selected' : '') . '>' . h($v) . '</option>';
    return $out;
}

/** <datalist> for fields that suggest values but accept anything. */
function lookup_datalist(string $key): string
{
    $o = '<datalist id="dl_' . h($key) . '">';
    foreach (lookup_names($key) as $v) $o .= '<option value="' . h($v) . '">';
    return $o . '</datalist>';
}

/** Does a value carry a flag (e.g. payment mode "Cheque" has 'cheque')? */
function lookup_flag(string $key, ?string $value, string $flag): bool
{
    foreach (lookup_values($key, false) as $r) if ($r['value'] === $value) return !empty($r['flags'][$flag]);
    return false;
}

/** Match free text (e.g. from an import) to a list value: exact, case-insensitive, or first letter (M → Male). */
function lookup_match(string $key, string $text): ?string
{
    $text = trim($text);
    if ($text === '') return null;
    $vals = lookup_names($key);
    foreach ($vals as $v) if (strcasecmp($v, $text) === 0) return $v;
    if (strlen($text) === 1) foreach ($vals as $v) if (strcasecmp($v[0], $text) === 0) return $v;
    return $text;
}

function lookup_usage(string $key, string $value): int
{
    $n = 0;
    foreach (LOOKUP_LISTS[$key][2] ?? [] as [$table, $col]) $n += (int)val("SELECT COUNT(*) FROM `$table` WHERE `$col` = ?", [$value]);
    return $n;
}

function seed_lookups(?string $only = null): void
{
    foreach (LOOKUP_LISTS as $key => $def) {
        if ($only && $only !== $key) continue;
        if ((int)val('SELECT COUNT(*) FROM lookups WHERE list_key = ?', [$key]) > 0) continue;
        foreach ($def[4] as $i => $item) {
            [$v, $flags] = is_array($item) ? $item : [$item, []];
            q('INSERT IGNORE INTO lookups (list_key, value, sort_order, meta) VALUES (?, ?, ?, ?)', [$key, $v, ($i + 1) * 10, $flags ? json_encode($flags) : null]);
        }
    }
}

/* ---------------- database upgrades ---------------- */
const DB_VERSION = 3;

function run_migrations(): void
{
    $v = (int)(val("SELECT svalue FROM settings WHERE skey = 'db_version'") ?? 1);
    if ($v >= DB_VERSION) return;
    if ($v < 2) {
        q('CREATE TABLE IF NOT EXISTS lookups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            list_key VARCHAR(40) NOT NULL,
            value VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            meta VARCHAR(255) NULL,
            UNIQUE KEY uq_lookup (list_key, value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        q('ALTER TABLE students MODIFY gender VARCHAR(30) NULL');
        seed_lookups();
        // keep any payment modes the school had already typed in School Profile
        $old = array_filter(array_map('trim', explode(',', (string)val("SELECT svalue FROM settings WHERE skey = 'payment_modes'"))));
        $i = 100;
        foreach ($old as $m) q('INSERT IGNORE INTO lookups (list_key, value, sort_order) VALUES (?, ?, ?)', ['payment_mode', $m, $i++]);
        // and any values already stored on students that are not in the lists yet
        foreach (LOOKUP_LISTS as $key => $def) foreach ($def[2] as [$table, $col]) {
            foreach (rows("SELECT DISTINCT `$col` AS v FROM `$table` WHERE `$col` IS NOT NULL AND `$col` <> ''") as $r) {
                q('INSERT IGNORE INTO lookups (list_key, value, sort_order) VALUES (?, ?, 500)', [$key, mb_substr($r['v'], 0, 100)]);
            }
        }
    }
    if ($v < 3) migrate_v3();
    q("INSERT INTO settings (skey, svalue) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)", [DB_VERSION]);
}

/** v3: roles master, fee frequency master, late fee rules master. */
function migrate_v3(): void
{
    // ---- roles ----
    q('CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL UNIQUE,
        perms TEXT,
        section_scoped TINYINT(1) NOT NULL DEFAULT 0,
        is_system TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    q('CREATE TABLE IF NOT EXISTS user_sections (
        user_id INT NOT NULL, section_id INT NOT NULL, PRIMARY KEY (user_id, section_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (!val("SHOW COLUMNS FROM users LIKE 'role_id'")) q('ALTER TABLE users ADD role_id INT NULL AFTER role');
    q('ALTER TABLE users MODIFY role VARCHAR(20) NULL');
    q("INSERT IGNORE INTO roles (name, perms, section_scoped, is_system) VALUES ('Administrator', ?, 0, 1)", [json_encode(array_keys(PERMISSION_LABELS))]);
    q("INSERT IGNORE INTO roles (name, perms, section_scoped, is_system) VALUES ('Class Teacher', ?, 1, 0)", [json_encode(['students.view', 'students.edit', 'fees.view', 'reports'])]);
    $adminRole = (int)val("SELECT id FROM roles WHERE name = 'Administrator'");
    q("UPDATE users SET role_id = ? WHERE role = 'admin' AND role_id IS NULL", [$adminRole]);
    // users created with the old fixed roles keep the same rights under a role of the same name
    $legacy = [
        'accountant' => ['Accountant / Cashier', ['students.view', 'students.edit', 'fees.view', 'fees.collect', 'fees.waive_late', 'fees.concession', 'reports']],
        'clerk' => ['Office Clerk', ['students.view', 'students.edit', 'fees.view']],
        'viewer' => ['Viewer', ['students.view', 'fees.view', 'reports']],
    ];
    foreach ($legacy as $old => [$name, $perms]) {
        if (!val('SELECT COUNT(*) FROM users WHERE role = ? AND role_id IS NULL', [$old])) continue;
        q('INSERT IGNORE INTO roles (name, perms) VALUES (?, ?)', [$name, json_encode($perms)]);
        q('UPDATE users SET role_id = (SELECT id FROM roles WHERE name = ?) WHERE role = ? AND role_id IS NULL', [$name, $old]);
    }
    q('UPDATE users SET role_id = ? WHERE role_id IS NULL', [$adminRole]);

    // ---- fee frequencies ----
    q('CREATE TABLE IF NOT EXISTS fee_frequencies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL UNIQUE,
        months VARCHAR(60) NULL,
        is_one_time TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $startMonth = (int)date('n', strtotime((string)(val('SELECT start_date FROM academic_years ORDER BY is_current DESC, start_date DESC LIMIT 1') ?: date('Y') . '-04-01')));
    $seq = function (int $every) use ($startMonth) {
        $m = [];
        for ($i = 0; $i < 12; $i += $every) $m[] = (($startMonth - 1 + $i) % 12) + 1;
        return implode(',', $m);
    };
    $seed = ['one_time' => ['One time', null, 1, 10], 'monthly' => ['Monthly', $seq(1), 0, 20], 'quarterly' => ['Quarterly', $seq(3), 0, 30],
        'half_yearly' => ['Half-yearly', $seq(6), 0, 40], 'yearly' => ['Yearly', $seq(12), 0, 50]];
    foreach ($seed as [$name, $months, $once, $sort]) q('INSERT IGNORE INTO fee_frequencies (name, months, is_one_time, sort_order) VALUES (?, ?, ?, ?)', [$name, $months, $once, $sort]);
    if (!val("SHOW COLUMNS FROM fee_heads LIKE 'frequency_id'")) q('ALTER TABLE fee_heads ADD frequency_id INT NULL AFTER frequency');
    if (!val("SHOW COLUMNS FROM fee_heads LIKE 'late_fee_rule_id'")) q('ALTER TABLE fee_heads ADD late_fee_rule_id INT NULL AFTER late_fee_applies');
    foreach ($seed as $old => [$name]) q('UPDATE fee_heads SET frequency_id = (SELECT id FROM fee_frequencies WHERE name = ?) WHERE frequency = ? AND frequency_id IS NULL', [$name, $old]);
    q("UPDATE fee_heads SET frequency_id = (SELECT id FROM fee_frequencies WHERE name = 'One time') WHERE frequency_id IS NULL");
    q('ALTER TABLE fee_heads MODIFY frequency VARCHAR(20) NULL');

    // ---- late fee rules ----
    q('CREATE TABLE IF NOT EXISTS late_fee_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL UNIQUE,
        grace_days INT NOT NULL DEFAULT 0,
        max_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    q("CREATE TABLE IF NOT EXISTS late_fee_slabs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rule_id INT NOT NULL,
        from_day INT NOT NULL DEFAULT 1,
        to_day INT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        calc ENUM('once','per_day','per_month') NOT NULL DEFAULT 'once',
        FOREIGN KEY (rule_id) REFERENCES late_fee_rules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!val('SELECT COUNT(*) FROM late_fee_rules')) {
        // the rule the school had set up before becomes the first rule
        $type = (string)val("SELECT svalue FROM settings WHERE skey = 'late_fee_type'") ?: 'none';
        $amt = (float)val("SELECT svalue FROM settings WHERE skey = 'late_fee_amount'");
        $grace = (int)val("SELECT svalue FROM settings WHERE skey = 'late_fee_grace_days'");
        $max = (float)val("SELECT svalue FROM settings WHERE skey = 'late_fee_max'");
        $calc = ['per_day' => 'per_day', 'per_month' => 'per_month', 'flat' => 'once'][$type] ?? 'per_month';
        $label = ['once' => '₹' . ($amt + 0) . ' once', 'per_day' => '₹' . ($amt + 0) . ' per day', 'per_month' => '₹' . ($amt + 0) . ' per month'][$calc];
        $name = $type !== 'none' && $amt > 0 ? 'Standard – ' . $label . ($max > 0 ? ' (max ₹' . ($max + 0) . ')' : '') : 'Standard – ₹100 per month (max ₹500)';
        $rid = insert('late_fee_rules', ['name' => $name, 'grace_days' => $type !== 'none' ? $grace : 5, 'max_amount' => $type !== 'none' ? $max : 500]);
        insert('late_fee_slabs', ['rule_id' => $rid, 'from_day' => 1, 'to_day' => null, 'amount' => $type !== 'none' && $amt > 0 ? $amt : 100, 'calc' => $calc]);
        $sl = insert('late_fee_rules', ['name' => 'Slab – ₹50 up to 15 days, ₹100 up to 30, then ₹200', 'grace_days' => 0, 'max_amount' => 0]);
        foreach ([[1, 15, 50], [16, 30, 100], [31, null, 200]] as [$f, $t, $a]) insert('late_fee_slabs', ['rule_id' => $sl, 'from_day' => $f, 'to_day' => $t, 'amount' => $a, 'calc' => 'once']);
        q("INSERT INTO settings (skey, svalue) VALUES ('late_fee_rule_id', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)", [$type !== 'none' && $amt > 0 ? $rid : 0]);
    }
}

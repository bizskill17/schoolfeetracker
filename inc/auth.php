<?php

/** Everything a role can be allowed to do. Roles themselves are a master (Settings → Users & Roles). */
const PERMISSION_LABELS = [
    'students.view'    => ['Students', 'View students and their details'],
    'students.edit'    => ['Students', 'Add and edit students, upload documents, mark as left'],
    'students.delete'  => ['Students', 'Delete wrongly entered students'],
    'students.promote' => ['Students', 'Promote students to the next year'],
    'fees.view'        => ['Fees', 'View fee schedules, receipts and dues; send WhatsApp reminders'],
    'fees.collect'     => ['Fees', 'Collect fees and print receipts'],
    'fees.waive_late'  => ['Fees', 'Reduce or waive late fee while collecting'],
    'fees.concession'  => ['Fees', 'Give or remove concessions'],
    'fees.adjust'      => ['Fees', 'Waive fees, add extra charges, update fee schedules'],
    'fees.cancel'      => ['Fees', 'Cancel receipts, mark cheques bounced, back-date receipts'],
    'fees.refund'      => ['Fees', 'Record refunds'],
    'reports'          => ['Reports', 'See reports and download them'],
    'settings'         => ['Settings', 'Masters, settings, users and roles, backup'],
];

function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = empty($_SESSION['uid']) ? null : row('SELECT u.*, r.name AS role_name, r.perms, r.section_scoped, r.is_system
            FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.active = 1', [$_SESSION['uid']]);
        if ($u) $u['perm_list'] = (int)$u['is_system'] === 1 ? array_keys(PERMISSION_LABELS) : (json_decode((string)$u['perms'], true) ?: []);
    }
    return $u;
}

function can(string $perm): bool
{
    $u = user();
    return $u && in_array($perm, $u['perm_list'], true);
}

function require_perm(string $perm): void
{
    if (!can($perm)) {
        http_response_code(403);
        flash('err', 'You do not have permission to do that.');
        redirect('dashboard');
    }
}

/**
 * Sections this user may see. null = all sections (not limited).
 * A class teacher sees only the sections assigned to them in Users & Roles.
 */
function section_scope(): ?array
{
    static $scope = false;
    if ($scope === false) {
        $u = user();
        $scope = ($u && (int)$u['section_scoped'] === 1)
            ? array_map('intval', array_column(rows('SELECT section_id FROM user_sections WHERE user_id = ?', [$u['id']]), 'section_id'))
            : null;
    }
    return $scope;
}

/** SQL to add to a WHERE clause on an enrollments alias so lists only show allowed sections. */
function scope_sql(string $alias = 'e'): string
{
    $s = section_scope();
    if ($s === null) return '';
    return $s ? " AND $alias.section_id IN (" . implode(',', $s) . ')' : ' AND 1 = 0';
}

function in_scope(?int $sectionId): bool
{
    $s = section_scope();
    return $s === null || ($sectionId !== null && in_array($sectionId, $s, true));
}

/** Stop a limited user opening a student outside their sections. */
function require_enrollment_scope(?array $enr): void
{
    if ($enr && !in_scope($enr['section_id'] !== null ? (int)$enr['section_id'] : null)) {
        flash('err', 'This student is not in your class.');
        redirect('students/list');
    }
}

function attempt_login(string $username, string $password): bool
{
    $key = 'login_fail_' . md5(strtolower($username) . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $fails = (int)setting($key, '0');
    $lastFail = (int)setting($key . '_t', '0');
    if ($fails >= 5 && time() - $lastFail < 900) {
        throw new RuntimeException('Too many failed attempts. Try again in 15 minutes.');
    }
    $u = row('SELECT * FROM users WHERE username = ? AND active = 1', [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        set_setting($key, $fails + 1);
        set_setting($key . '_t', time());
        return false;
    }
    q('DELETE FROM settings WHERE skey IN (?, ?)', [$key, $key . '_t']);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['last_seen'] = time();
    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    audit('login', 'user', (int)$u['id']);
    return true;
}

function logout(): void
{
    if (!empty($_SESSION['uid'])) audit('logout', 'user', (int)$_SESSION['uid']);
    $_SESSION = [];
    session_destroy();
}

/** Table expression for receipts, limited to the user's sections when needed: use as "FROM " . receipts_src() . " ...". */
function receipts_src(string $alias = 'receipts'): string
{
    return section_scope() === null ? "receipts $alias"
        : '(SELECT r0.* FROM receipts r0 JOIN enrollments e ON e.id = r0.enrollment_id WHERE 1 = 1' . scope_sql('e') . ") $alias";
}

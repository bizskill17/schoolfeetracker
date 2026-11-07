<?php
require_perm('settings');
$title = 'Activity Log';
$where = ['1=1']; $p = [];
if (get('user')) { $where[] = 'a.user_id = ?'; $p[] = (int)get('user'); }
if (get('action')) { $where[] = 'a.action LIKE ?'; $p[] = get('action') . '%'; }
if (get('from')) { $where[] = 'a.created_at >= ?'; $p[] = get('from') . ' 00:00:00'; }
if (get('to')) { $where[] = 'a.created_at <= ?'; $p[] = get('to') . ' 23:59:59'; }
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM audit_log a WHERE $w", $p);
$pg = max(1, (int)get('pg', 1)); $per = 50;
$list = rows("SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE $w ORDER BY a.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $p);
$users = rows('SELECT id, name FROM users ORDER BY name');
$actions = ['login' => 'Logins', 'receipt' => 'Receipts', 'student' => 'Students', 'concession' => 'Concessions', 'demand' => 'Fee changes', 'refund' => 'Refunds', 'settings' => 'Settings', 'user' => 'Users'];
?>
<form class="card card-body mb-3 row-cols-auto d-flex flex-row flex-wrap gap-2 align-items-end">
<input type="hidden" name="p" value="settings/audit">
<div><label class="form-label">User</label><select name="user" class="form-select form-select-sm"><?= select_options($users, get('user'), 'id', 'name', 'All') ?></select></div>
<div><label class="form-label">Type</label><select name="action" class="form-select form-select-sm"><option value="">All</option><?php foreach ($actions as $k => $l): ?><option value="<?= $k ?>" <?= get('action') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
<div><label class="form-label">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= h(get('from')) ?>"></div>
<div><label class="form-label">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= h(get('to')) ?>"></div>
<div><button class="btn btn-sm btn-primary">Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
<thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($list as $a): ?>
<tr><td class="text-nowrap small"><?= h(date('d-m-Y H:i', strtotime($a['created_at']))) ?></td><td><?= h($a['user_name'] ?? '—') ?></td><td><code><?= h($a['action']) ?></code></td>
<td class="small"><?= h($a['entity']) ?> <?= $a['entity_id'] ? '#' . (int)$a['entity_id'] : '' ?></td><td class="small text-break" style="max-width:420px"><?= h(mb_strimwidth((string)$a['details'], 0, 300, '…')) ?></td><td class="small"><?= h($a['ip']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<div class="mt-2"><?= paginate($total, $per, $pg, $_GET) ?></div>

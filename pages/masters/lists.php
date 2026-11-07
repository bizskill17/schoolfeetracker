<?php
require_perm('settings');
$title = 'Dropdown Lists';
$key = (string)get('list', 'gender');
if (!isset(LOOKUP_LISTS[$key])) $key = 'gender';
[$label, $usedIn, $usage, $flagDefs] = LOOKUP_LISTS[$key];

if (is_post()) {
    $key = (string)post('list');
    if (!isset(LOOKUP_LISTS[$key])) redirect('masters/lists');
    [$label, $usedIn, $usage, $flagDefs] = LOOKUP_LISTS[$key];
    $act = post('act');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($act === 'add') {
            $added = 0;
            $next = (int)val('SELECT COALESCE(MAX(sort_order), 0) FROM lookups WHERE list_key = ?', [$key]);
            foreach (preg_split('/\r\n|\r|\n|,/', (string)post('values')) as $v) {
                $v = trim(mb_substr($v, 0, 100));
                if ($v === '') continue;
                $added += q('INSERT IGNORE INTO lookups (list_key, value, sort_order) VALUES (?, ?, ?)', [$key, $v, $next += 10])->rowCount();
            }
            audit('master.add', 'lookup', null, ['list' => $key, 'values' => post('values')]);
            flash($added ? 'ok' : 'warn', $added ? "$added value(s) added." : 'Nothing added – the values already exist.');
        } elseif ($act === 'save') {
            $renamed = 0;
            foreach ((array)($_POST['row'] ?? []) as $id => $r) {
                $cur = row('SELECT * FROM lookups WHERE id = ? AND list_key = ?', [(int)$id, $key]);
                if (!$cur) continue;
                $new = trim(mb_substr((string)($r['value'] ?? ''), 0, 100));
                if ($new === '') throw new RuntimeException('A value cannot be empty. Use "Delete" or untick "Active" instead.');
                $flags = [];
                foreach ($flagDefs as $fk => $fl) if (!empty($r['flag'][$fk])) $flags[$fk] = 1;
                if ($new !== $cur['value']) {
                    if (val('SELECT id FROM lookups WHERE list_key = ? AND value = ? AND id <> ?', [$key, $new, $cur['id']])) throw new RuntimeException('"' . $new . '" already exists in this list.');
                    foreach ($usage as [$table, $col]) q("UPDATE `$table` SET `$col` = ? WHERE `$col` = ?", [$new, $cur['value']]);
                    audit('master.rename', 'lookup', (int)$cur['id'], ['list' => $key, 'from' => $cur['value'], 'to' => $new]);
                    $renamed++;
                }
                update('lookups', ['value' => $new, 'sort_order' => (int)($r['sort'] ?? 0), 'active' => !empty($r['active']) ? 1 : 0,
                    'meta' => $flags ? json_encode($flags) : null], 'id = ?', [$cur['id']]);
            }
            if ($key === 'payment_mode' && !val("SELECT id FROM lookups WHERE list_key = 'payment_mode' AND active = 1")) throw new RuntimeException('Keep at least one active payment mode.');
            audit('master.save', 'lookup', null, ['list' => $key]);
            flash('ok', 'Saved.' . ($renamed ? " $renamed value(s) renamed – existing records were updated too." : ''));
        } elseif ($act === 'delete') {
            $cur = row('SELECT * FROM lookups WHERE id = ? AND list_key = ?', [(int)post('id'), $key]);
            if ($cur) {
                if (lookup_usage($key, $cur['value']) > 0) throw new RuntimeException('"' . $cur['value'] . '" is used in existing records. Untick "Active" to hide it from new entries instead.');
                q('DELETE FROM lookups WHERE id = ?', [$cur['id']]);
                audit('master.delete', 'lookup', (int)$cur['id'], ['list' => $key, 'value' => $cur['value']]);
                flash('ok', '"' . $cur['value'] . '" deleted.');
            }
        } elseif ($act === 'sort_az') {
            $i = 0;
            foreach (rows('SELECT id FROM lookups WHERE list_key = ? ORDER BY value', [$key]) as $r) q('UPDATE lookups SET sort_order = ? WHERE id = ?', [$i += 10, $r['id']]);
            flash('ok', 'Sorted A–Z.');
        } elseif ($act === 'reset') {
            if (val('SELECT COUNT(*) FROM lookups WHERE list_key = ?', [$key])) {
                foreach (LOOKUP_LISTS[$key][4] as $i => $item) {
                    [$v, $fl] = is_array($item) ? $item : [$item, []];
                    q('INSERT IGNORE INTO lookups (list_key, value, sort_order, meta) VALUES (?, ?, ?, ?)', [$key, $v, ($i + 1) * 10, $fl ? json_encode($fl) : null]);
                }
            } else seed_lookups($key);
            flash('ok', 'Standard values added back (your own values were kept).');
        }
        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    }
    redirect('masters/lists', ['list' => $key]);
}

$counts = [];
foreach (rows('SELECT list_key, COUNT(*) AS n, SUM(active) AS a FROM lookups GROUP BY list_key') as $r) $counts[$r['list_key']] = $r;
$groups = [];
foreach (LOOKUP_LISTS as $k => $d) $groups[preg_replace('/\s*\(.*$/', '', $d[1])][$k] = $d[0];
$vals = lookup_values($key, false);
?>
<div class="row g-3">
<div class="col-lg-3">
  <div class="card"><div class="list-group list-group-flush">
  <?php foreach ($groups as $g => $items): ?>
    <div class="list-group-item small text-uppercase text-muted fw-semibold bg-light py-1"><?= h($g) ?></div>
    <?php foreach ($items as $k => $l): ?>
      <a class="list-group-item list-group-item-action d-flex justify-content-between <?= $k === $key ? 'active' : '' ?>" href="<?= url('masters/lists', ['list' => $k]) ?>"><?= h($l) ?><span class="badge <?= $k === $key ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int)($counts[$k]['a'] ?? 0) ?></span></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </div></div>
  <div class="small text-muted mt-2">Other masters: <a href="<?= url('settings/classes') ?>">Classes &amp; Sections</a>, <a href="<?= url('settings/fee_heads') ?>">Fee Heads</a>, <a href="<?= url('settings/transport') ?>">Transport Routes</a>, <a href="<?= url('settings/concession_types') ?>">Concession Types</a>, <a href="<?= url('settings/years') ?>">Academic Years</a>.</div>
</div>
<div class="col-lg-9">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <h5 class="mb-0"><?= h($label) ?></h5><span class="text-muted small">Used in: <?= h($usedIn) ?></span>
    <form method="post" class="ms-auto d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="list" value="<?= h($key) ?>">
      <button name="act" value="sort_az" class="btn btn-sm btn-light">Sort A–Z</button>
      <?php if (LOOKUP_LISTS[$key][4]): ?><button name="act" value="reset" class="btn btn-sm btn-light" data-confirm="Add back any standard values that are missing? Your own values stay.">Restore standard values</button><?php endif; ?>
    </form>
  </div>
  <form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="list" value="<?= h($key) ?>"><input type="hidden" name="act" value="save">
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
  <thead><tr><th style="width:80px">Order</th><th>Value</th><?php foreach ($flagDefs as $fl): ?><th class="text-center small" style="max-width:120px"><?= h($fl) ?></th><?php endforeach; ?><th class="text-center">Active</th><th class="text-end">In use</th><th></th></tr></thead><tbody>
  <?php foreach ($vals as $v): $used = lookup_usage($key, $v['value']); ?>
  <tr class="<?= $v['active'] ? '' : 'table-secondary' ?>">
    <td><input type="number" name="row[<?= $v['id'] ?>][sort]" value="<?= (int)$v['sort_order'] ?>" class="form-control form-control-sm"></td>
    <td><input name="row[<?= $v['id'] ?>][value]" value="<?= h($v['value']) ?>" class="form-control form-control-sm" maxlength="100" required></td>
    <?php foreach ($flagDefs as $fk => $fl): ?><td class="text-center"><input type="checkbox" class="form-check-input" name="row[<?= $v['id'] ?>][flag][<?= $fk ?>]" value="1" <?= !empty($v['flags'][$fk]) ? 'checked' : '' ?>></td><?php endforeach; ?>
    <td class="text-center"><input type="checkbox" class="form-check-input" name="row[<?= $v['id'] ?>][active]" value="1" <?= $v['active'] ? 'checked' : '' ?>></td>
    <td class="text-end small"><?= $usage ? $used : '—' ?></td>
    <td class="text-end"><?php if (!$used): ?><button form="del<?= $v['id'] ?>" class="btn btn-sm btn-link text-danger p-0" data-confirm="Delete &quot;<?= h($v['value']) ?>&quot;?"><i class="bi bi-trash"></i></button><?php endif; ?></td>
  </tr>
  <?php endforeach; if (!$vals): ?><tr><td colspan="<?= 5 + count($flagDefs) ?>" class="text-muted text-center py-3">No values yet. Add some below.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($vals): ?><div class="card-body border-top d-flex flex-wrap gap-2 align-items-center"><button class="btn btn-primary">Save changes</button>
    <span class="small text-muted">Renaming a value also updates the records that use it. Untick "Active" to hide a value from new entries without touching old records.</span></div><?php endif; ?>
  </form>
  <?php foreach ($vals as $v): ?><form method="post" id="del<?= $v['id'] ?>" class="d-none"><?= csrf_field() ?><input type="hidden" name="list" value="<?= h($key) ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $v['id'] ?>"></form><?php endforeach; ?>
  <form method="post" class="card"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="list" value="<?= h($key) ?>"><input type="hidden" name="act" value="add">
    <label class="form-label">Add values to <?= h($label) ?></label>
    <textarea name="values" class="form-control mb-2" rows="3" placeholder="One per line (or separated by commas)" required></textarea>
    <button class="btn btn-success"><i class="bi bi-plus-lg"></i> Add</button>
    <?php if ($key === 'payment_mode'): ?><div class="form-text">Tick the boxes above for each mode: <b>Cash</b> modes appear in the cash hand-over report; <b>Reference required</b> asks the cashier for the UPI/transaction/cheque number; <b>Cheque / DD</b> receipts can be marked as bounced.</div><?php endif; ?>
  </div></form>
</div>
</div>

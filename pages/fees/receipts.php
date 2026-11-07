<?php
require_perm('fees.view');
$title = 'Receipts';
if (is_post()) {
    require_perm('fees.cancel');
    $rid = (int)post('id');
    try {
        if (!post('reason')) throw new RuntimeException('Enter a reason.');
        if (post('act') === 'bounce') {
            $r = row('SELECT * FROM receipts WHERE id = ?', [$rid]);
            if (!$r || !lookup_flag('payment_mode', $r['mode'], 'cheque')) throw new RuntimeException('Only cheque / DD receipts can be marked bounced (set this in Masters → Dropdown Lists → Payment Mode).');
            cancel_receipt($rid, 'Cheque bounced: ' . post('reason'));
            $charge = (float)post('charges');
            insert('cheque_bounces', ['receipt_id' => $rid, 'bounce_date' => post('bounce_date') ?: today(), 'charges' => $charge, 'remarks' => post('reason'), 'created_by' => $_SESSION['uid']]);
            if ($charge > 0) add_charge((int)$r['enrollment_id'], system_head_id(SYS_BOUNCE), 'Cheque ' . $r['reference'] . ' bounced', $charge, today(), 'Receipt ' . $r['receipt_no']);
            audit('receipt.bounce', 'receipt', $rid, ['charges' => $charge]);
            flash('ok', 'Cheque marked as bounced. The fees are unpaid again' . ($charge > 0 ? ' and a bounce charge of ' . money($charge) . ' was added.' : '.'));
        } else {
            cancel_receipt($rid, post('reason'));
            flash('ok', 'Receipt cancelled. The fees are shown as unpaid again.');
        }
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('fees/receipts', array_filter(['from' => post('from'), 'to' => post('to')]));
}
$from = get('from', date('Y-m-d', strtotime('-30 days'))); $to = get('to', today());
$where = ['r.receipt_date BETWEEN ? AND ?']; $p = [$from, $to];
if (get('q') !== '') { $qq = '%' . get('q') . '%'; $where[] = '(r.receipt_no LIKE ? OR s.admission_no LIKE ? OR CONCAT(s.first_name," ",COALESCE(s.last_name,"")) LIKE ? OR r.reference LIKE ?)'; array_push($p, $qq, $qq, $qq, $qq); }
if (get('mode')) { $where[] = 'r.mode = ?'; $p[] = get('mode'); }
if (get('user')) { $where[] = 'r.created_by = ?'; $p[] = (int)get('user'); }
if (get('show') === 'cancelled') $where[] = 'r.cancelled = 1'; elseif (get('show') !== 'all') $where[] = 'r.cancelled = 0';
$w = implode(' AND ', $where) . scope_sql('e');
$base = "FROM receipts r JOIN students s ON s.id = r.student_id JOIN enrollments e ON e.id = r.enrollment_id JOIN classes c ON c.id = e.class_id
         LEFT JOIN sections sec ON sec.id = e.section_id LEFT JOIN users u ON u.id = r.created_by WHERE $w";
if (get('export') === 'csv') {
    $data = rows("SELECT r.receipt_no, r.receipt_date, s.admission_no, CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) AS name, c.name AS class_name, sec.name AS section_name,
                 r.mode, r.reference, r.fee_amount, r.late_fee, r.late_fee_waived, r.total_amount, u.name AS by_name, IF(r.cancelled,'Cancelled','') AS st, r.cancel_reason $base ORDER BY r.id", $p);
    foreach ($data as &$d) $d['receipt_date'] = dmy($d['receipt_date']);
    csv_download("receipts-$from-to-$to.csv", ['Receipt No', 'Date', 'Adm No', 'Student', 'Class', 'Section', 'Mode', 'Reference', 'Fee', 'Late Fee', 'Late Fee Waived', 'Total', 'Received By', 'Status', 'Cancel Reason'], $data);
}
$total = (int)val("SELECT COUNT(*) $base", $p);
$totAmt = (float)val("SELECT COALESCE(SUM(CASE WHEN r.cancelled = 0 THEN r.total_amount END),0) $base", $p);
$pg = max(1, (int)get('pg', 1)); $per = 50;
$list = rows("SELECT r.*, s.admission_no, s.first_name, s.last_name, c.name AS class_name, sec.name AS section_name, u.name AS by_name $base ORDER BY r.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $p);
$users = rows('SELECT id, name FROM users ORDER BY name');
$qs = $_GET; unset($qs['pg']);
?>
<form class="card card-body mb-3"><input type="hidden" name="p" value="fees/receipts">
<div class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= h($from) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($to) ?>"></div>
  <div class="col-md-3"><label class="form-label">Search</label><input name="q" class="form-control" value="<?= h(get('q')) ?>" placeholder="Receipt no, student, adm no, cheque no"></div>
  <div class="col-4 col-md-1"><label class="form-label">Mode</label><select name="mode" class="form-select"><option value="">All</option><?php foreach (array_column(lookup_values('payment_mode', false), 'value') as $m): ?><option <?= get('mode') === $m ? 'selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select></div>
  <div class="col-4 col-md-2"><label class="form-label">Received by</label><select name="user" class="form-select"><?= select_options($users, get('user'), 'id', 'name', 'Anyone') ?></select></div>
  <div class="col-4 col-md-1"><label class="form-label">Show</label><select name="show" class="form-select"><option value="">Valid</option><option value="cancelled" <?= get('show') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option><option value="all" <?= get('show') === 'all' ? 'selected' : '' ?>>All</option></select></div>
  <div class="col-md-1 d-flex gap-1"><button class="btn btn-primary"><i class="bi bi-search"></i></button><a class="btn btn-outline-secondary" href="index.php?<?= h(http_build_query($qs + ['export' => 'csv'])) ?>"><i class="bi bi-download"></i></a></div>
</div></form>
<div class="mb-2 small text-muted"><?= $total ?> receipts · Total collected <b class="text-dark"><?= money($totAmt) ?></b></div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
<thead><tr><th>Date</th><th>Receipt No</th><th>Student</th><th>Class</th><th>Mode</th><th class="text-end">Amount</th><th>By</th><th></th></tr></thead><tbody>
<?php foreach ($list as $r): ?>
<tr class="<?= $r['cancelled'] ? 'text-muted' : '' ?>"><td class="text-nowrap"><?= dmy($r['receipt_date']) ?></td>
<td><?= h($r['receipt_no']) ?> <?= $r['cancelled'] ? '<span class="badge text-bg-danger" title="' . h($r['cancel_reason']) . '">Cancelled</span>' : '' ?></td>
<td><a href="<?= url('students/view', ['id' => $r['student_id'], 'tab' => 'receipts']) ?>"><?= h(student_name($r)) ?></a> <span class="small text-muted"><?= h($r['admission_no']) ?></span></td>
<td><?= h(class_label($r)) ?></td><td><?= h($r['mode']) ?> <span class="small text-muted"><?= h($r['reference']) ?></span></td>
<td class="amount fw-semibold"><?= money($r['total_amount']) ?></td><td class="small"><?= h($r['by_name']) ?></td>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-light" target="_blank" href="<?= url('fees/receipt', ['id' => $r['id']]) ?>"><i class="bi bi-printer"></i></a>
<?php if (can('fees.cancel') && !$r['cancelled']): ?>
<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal" data-id="<?= $r['id'] ?>" data-no="<?= h($r['receipt_no']) ?>" data-cheque="<?= lookup_flag('payment_mode', $r['mode'], 'cheque') ? 1 : 0 ?>"><i class="bi bi-x-circle"></i></button>
<?php endif; ?></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="8" class="text-center text-muted py-4">No receipts in this period.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="mt-2"><?= paginate($total, $per, $pg, $qs) ?></div>

<?php if (can('fees.cancel')): ?>
<div class="modal fade" id="cancelModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?>
<input type="hidden" name="id" id="cId"><input type="hidden" name="from" value="<?= h($from) ?>"><input type="hidden" name="to" value="<?= h($to) ?>">
<div class="modal-header"><h5 class="modal-title">Cancel receipt <span id="cNo"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
  <div id="cType" class="mb-2"><label class="me-3"><input type="radio" name="act" value="cancel" checked> Cancel (wrong entry)</label><label><input type="radio" name="act" value="bounce"> Cheque bounced</label></div>
  <div id="bounceFields" class="row g-2 mb-2 d-none"><div class="col"><label class="form-label">Bounce date</label><input type="date" name="bounce_date" class="form-control" value="<?= today() ?>"></div>
  <div class="col"><label class="form-label">Bounce charges ₹</label><input type="number" step="0.01" min="0" name="charges" class="form-control" value="0"></div></div>
  <label class="form-label">Reason</label><input name="reason" class="form-control" required list="dl_cancel_reason"><?= lookup_datalist('cancel_reason') ?>
  <div class="small text-muted mt-2">The receipt stays on record marked CANCELLED and the fees go back to unpaid. This is logged with your name.</div>
</div>
<div class="modal-footer"><button class="btn btn-danger">Confirm</button></div></form></div></div>
<script>
document.getElementById('cancelModal').addEventListener('show.bs.modal', e => { const b = e.relatedTarget; cId.value = b.dataset.id; cNo.textContent = b.dataset.no;
  cType.style.display = b.dataset.cheque === '1' ? '' : 'none'; document.querySelector('[name=act][value=cancel]').checked = true; bounceFields.classList.add('d-none'); });
document.querySelectorAll('[name=act]').forEach(r => r.addEventListener('change', () => bounceFields.classList.toggle('d-none', document.querySelector('[name=act]:checked').value !== 'bounce')));
</script>
<?php endif; ?>

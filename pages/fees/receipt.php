<?php
require_perm('fees.view');
$r = row('SELECT r.*, u.name AS by_name, cu.name AS cancelled_name, y.name AS year_name FROM receipts r LEFT JOIN users u ON u.id = r.created_by
          LEFT JOIN users cu ON cu.id = r.cancelled_by JOIN academic_years y ON y.id = r.academic_year_id WHERE r.id = ?', [(int)get('id')]);
if (!$r) exit('Receipt not found');
$e = enrollment_full((int)$r['enrollment_id']);
require_enrollment_scope($e);
$items = rows('SELECT * FROM receipt_items WHERE receipt_id = ? ORDER BY id', [$r['id']]);
$sum = fee_summary((int)$r['enrollment_id']);
$bare = true;
$title = 'Receipt ' . $r['receipt_no'];
$logo = setting('school_logo');
$copies = get('copies', '1') === '2' ? ['Parent copy', 'Office copy'] : [''];
$phone = $e['whatsapp'] ?: $e['father_phone'];
$waMsg = 'Fee received: ' . money($r['total_amount']) . ' for ' . student_name($e) . ' (' . class_label($e) . '). Receipt ' . $r['receipt_no'] . ' dated ' . dmy($r['receipt_date']) . '. Thank you. – ' . setting('school_name');
?>
<div class="no-print container py-3 d-flex flex-wrap gap-2" style="max-width:720px">
  <button onclick="print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
  <a class="btn btn-outline-primary" href="<?= url('fees/receipt', ['id' => $r['id'], 'copies' => count($copies) === 2 ? 1 : 2]) ?>"><?= count($copies) === 2 ? 'Single copy' : 'Print 2 copies' ?></a>
  <?php if ($phone && !$r['cancelled']): ?><a class="btn btn-outline-success" target="_blank" href="<?= h(wa_link($phone, $waMsg)) ?>"><i class="bi bi-whatsapp"></i> Send on WhatsApp</a><?php endif; ?>
  <?php if (can('fees.collect')): ?><a class="btn btn-light" href="<?= url('fees/collect') ?>">Collect another</a><?php endif; ?>
  <a class="btn btn-light" href="<?= url('students/view', ['id' => $r['student_id'], 'tab' => 'receipts']) ?>">Student</a>
</div>
<?php foreach ($copies as $ci => $copy): ?>
<div class="receipt" style="<?= $ci ? 'page-break-before:always' : '' ?>">
  <div class="d-flex align-items-center gap-3 border-bottom pb-2 mb-2">
    <?php if ($logo): ?><img src="file.php?f=<?= h($logo) ?>" style="height:56px"><?php endif; ?>
    <div class="flex-fill text-center"><h2><?= h(setting('school_name')) ?></h2>
      <div class="small"><?= h(setting('school_address')) ?><?= setting('school_phone') ? ' · Ph ' . h(setting('school_phone')) : '' ?></div>
      <?php if (setting('school_affiliation') || setting('school_gstin')): ?><div class="small"><?= setting('school_affiliation') ? 'Affiliation: ' . h(setting('school_affiliation')) : '' ?> <?= setting('school_gstin') ? ' GSTIN: ' . h(setting('school_gstin')) : '' ?></div><?php endif; ?></div>
    <div class="text-end small"><?= h($copy) ?></div>
  </div>
  <div class="d-flex justify-content-between align-items-center"><b>FEE RECEIPT</b><?php if ($r['cancelled']): ?><span class="cancelled-stamp">CANCELLED</span><?php endif; ?><span>Session <?= h($r['year_name']) ?></span></div>
  <table class="meta w-100 mt-1"><tr>
    <td>Receipt No: <b><?= h($r['receipt_no']) ?></b></td><td class="text-end">Date: <b><?= dmy($r['receipt_date']) ?></b></td></tr>
    <tr><td>Student: <b><?= h(student_name($e)) ?></b></td><td class="text-end">Adm No: <b><?= h($e['admission_no']) ?></b></td></tr>
    <tr><td>Father: <?= h($e['father_name']) ?></td><td class="text-end">Class: <b><?= h(class_label($e)) ?></b><?= $e['roll_no'] ? ' · Roll ' . h($e['roll_no']) : '' ?></td></tr>
  </table>
  <table class="items">
    <thead><tr><th style="width:40px">#</th><th>Particulars</th><th class="text-end" style="width:120px">Amount (₹)</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($items as $it): if ((float)$it['amount'] <= 0) continue; ?>
      <tr><td><?= ++$i ?></td><td><?= h($it['description']) ?></td><td class="text-end"><?= money($it['amount'], false) ?></td></tr>
    <?php endforeach; ?>
    <?php if ((float)$r['late_fee_waived'] > 0): ?><tr><td></td><td class="small fst-italic">Late fee waived: <?= money($r['late_fee_waived']) ?></td><td></td></tr><?php endif; ?>
    <tr><th colspan="2" class="text-end">Total</th><th class="text-end"><?= money($r['total_amount']) ?></th></tr>
    </tbody>
  </table>
  <div class="mt-2"><i><?= h(amount_in_words((float)$r['total_amount'])) ?></i></div>
  <div class="mt-1 small">Paid by <b><?= h($r['mode']) ?></b><?= $r['reference'] ? ' · Ref ' . h($r['reference']) : '' ?><?= $r['bank_name'] ? ' · ' . h($r['bank_name']) : '' ?><?= $r['paid_by'] ? ' · Paid by ' . h($r['paid_by']) : '' ?><?= lookup_flag('payment_mode', $r['mode'], 'cheque') ? ' (subject to realisation)' : '' ?></div>
  <?php if ($r['remarks']): ?><div class="small">Remarks: <?= h($r['remarks']) ?></div><?php endif; ?>
  <?php if (!$r['cancelled'] && in_array($e['status'], ['promoted', 'detained'], true)): ?><div class="small mt-1">Remaining dues of this session are carried forward to the next session.</div>
  <?php elseif (!$r['cancelled']): ?><div class="small mt-1">Balance due as on <?= dmy(today()) ?>: <b><?= money($sum['payable_now']) ?></b> · Balance for the year: <?= money($sum['balance']) ?></div><?php endif; ?>
  <?php if ($r['cancelled']): ?><div class="small text-danger mt-1">Cancelled on <?= h(date('d-m-Y H:i', strtotime($r['cancelled_at']))) ?> by <?= h($r['cancelled_name']) ?>: <?= h($r['cancel_reason']) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-end mt-4">
    <div class="small text-muted"><?= h(setting('receipt_note')) ?><br>Received by: <?= h($r['by_name']) ?> · <?= h(date('d-m-Y H:i', strtotime($r['created_at']))) ?></div>
    <div class="text-center" style="min-width:160px"><div class="border-top pt-1 small"><?= h(setting('receipt_signatory', 'Authorised Signatory')) ?></div></div>
  </div>
</div>
<?php endforeach; ?>
<?php if (get('new') && !$r['cancelled']): ?><script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script><?php endif; ?>

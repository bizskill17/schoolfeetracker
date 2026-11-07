<?php
require_perm('settings');
$title = 'School Profile';
$keys = ['school_name', 'school_address', 'school_phone', 'school_email', 'school_website', 'school_board', 'school_affiliation',
    'school_udise', 'school_gstin', 'receipt_prefix', 'receipt_note', 'admission_prefix', 'reminder_text', 'receipt_signatory'];
if (is_post()) {
    try {
        foreach ($keys as $k) set_setting($k, post($k));
        if (post('remove_logo')) set_setting('school_logo', '');
        $logo = save_upload('logo', 'public', ['png', 'jpg', 'jpeg', 'webp'], 1048576);
        if ($logo) set_setting('school_logo', $logo);
        audit('settings.school', 'settings');
        flash('ok', 'School profile saved.');
    } catch (RuntimeException $e) { flash('err', $e->getMessage()); }
    redirect('settings/school');
}
$v = fn($k, $d = '') => h(setting($k, $d));
?>
<form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
<?= csrf_field() ?>
<div class="form-section mt-0">School details (printed on receipts)</div>
<div class="row g-3">
  <div class="col-md-8"><label class="form-label req">School name</label><input name="school_name" class="form-control" required value="<?= $v('school_name') ?>"></div>
  <div class="col-md-4"><label class="form-label">Board</label><input name="school_board" class="form-control" placeholder="CBSE / ICSE / State" value="<?= $v('school_board') ?>"></div>
  <div class="col-12"><label class="form-label">Address</label><textarea name="school_address" class="form-control" rows="2"><?= $v('school_address') ?></textarea></div>
  <div class="col-md-4"><label class="form-label">Phone</label><input name="school_phone" class="form-control" value="<?= $v('school_phone') ?>"></div>
  <div class="col-md-4"><label class="form-label">Email</label><input name="school_email" type="email" class="form-control" value="<?= $v('school_email') ?>"></div>
  <div class="col-md-4"><label class="form-label">Website</label><input name="school_website" class="form-control" value="<?= $v('school_website') ?>"></div>
  <div class="col-md-4"><label class="form-label">Affiliation / Registration no.</label><input name="school_affiliation" class="form-control" value="<?= $v('school_affiliation') ?>"></div>
  <div class="col-md-4"><label class="form-label">UDISE code</label><input name="school_udise" class="form-control" value="<?= $v('school_udise') ?>"></div>
  <div class="col-md-4"><label class="form-label">GSTIN (if any)</label><input name="school_gstin" class="form-control" value="<?= $v('school_gstin') ?>"></div>
  <div class="col-md-6"><label class="form-label">Logo (PNG/JPG, max 1 MB)</label><input type="file" name="logo" class="form-control" accept="image/*">
    <?php if (setting('school_logo')): ?><div class="mt-2 d-flex align-items-center gap-2"><img src="file.php?f=<?= $v('school_logo') ?>" style="height:48px"><label class="small"><input type="checkbox" name="remove_logo" value="1"> Remove</label></div><?php endif; ?></div>
</div>
<div class="form-section">Receipts &amp; numbering</div>
<div class="row g-3">
  <div class="col-md-3"><label class="form-label">Receipt number prefix</label><input name="receipt_prefix" class="form-control" value="<?= $v('receipt_prefix', 'RCP') ?>">
    <div class="form-text">Receipts look like <?= $v('receipt_prefix', 'RCP') ?>/2026-27/00001</div></div>
  <div class="col-md-3"><label class="form-label">Admission number prefix</label><input name="admission_prefix" class="form-control" value="<?= $v('admission_prefix', 'ADM') ?>">
    <div class="form-text">Used to suggest the next admission no.</div></div>
  <div class="col-md-6"><label class="form-label">Signatory on receipt</label><input name="receipt_signatory" class="form-control" placeholder="Accountant / Principal" value="<?= $v('receipt_signatory', 'Authorised Signatory') ?>"></div>
  <div class="col-md-12 small text-muted">Payment modes, banks and other dropdown values are in <a href="<?= url('masters/lists', ['list' => 'payment_mode']) ?>">Masters → Dropdown Lists</a>.</div>
  <div class="col-md-12"><label class="form-label">Note printed at the bottom of receipts</label><input name="receipt_note" class="form-control" value="<?= $v('receipt_note') ?>"></div>
  <div class="col-md-12"><label class="form-label">WhatsApp fee reminder text</label><textarea name="reminder_text" class="form-control" rows="2"><?= $v('reminder_text') ?></textarea>
    <div class="form-text">You can use {student}, {class}, {amount}, {school}, {admission_no}</div></div>
</div>
<button class="btn btn-primary mt-3">Save</button>
</div></form>

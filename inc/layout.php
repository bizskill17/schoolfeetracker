<?php

/** Left menu, grouped into sections. Each item: [page, label, icon, permission]. */
function menu_sections(): array
{
    return [
        ['Dashboard', 'speedometer2', [
            ['dashboard', 'Dashboard', 'speedometer2', 'students.view'],
        ]],
        ['Students', 'people', [
            ['students/form', 'New Admission', 'person-plus', 'students.edit'],
            ['students/list', 'Student List', 'list-ul', 'students.view'],
            ['students/promote', 'Promote / New Year', 'arrow-up-circle', 'students.promote'],
            ['students/import', 'Import Students', 'upload', 'settings'],
        ]],
        ['Fees', 'cash-coin', [
            ['fees/collect', 'Collect Fee', 'cash-stack', 'fees.collect'],
            ['fees/receipts', 'Receipts', 'receipt', 'fees.view'],
            ['fees/dues', 'Dues & Reminders', 'exclamation-diamond', 'fees.view'],
            ['fees/refunds', 'Refunds', 'arrow-counterclockwise', 'fees.refund'],
            ['fees/regenerate', 'Update Fee Schedules', 'arrow-repeat', 'fees.adjust'],
        ]],
        ['Reports', 'bar-chart', [
            ['reports/collection', 'Daily Collection', 'calendar-check', 'reports'],
            ['reports/headwise', 'Fee Head-wise', 'pie-chart', 'reports'],
            ['reports/classwise', 'Class-wise Summary', 'grid-3x3', 'reports'],
            ['reports/concessions', 'Concessions & Waivers', 'percent', 'reports'],
            ['reports/cancelled', 'Cancelled Receipts', 'x-octagon', 'reports'],
            ['reports/strength', 'Student Strength', 'people-fill', 'reports'],
        ]],
        ['Masters', 'database', [
            ['masters/lists', 'Dropdown Lists', 'list-check', 'settings'],
            ['settings/years', 'Academic Years', 'calendar3', 'settings'],
            ['settings/classes', 'Classes & Sections', 'diagram-3', 'settings'],
            ['masters/frequencies', 'Fee Frequencies', 'calendar-range', 'settings'],
            ['masters/late_rules', 'Late Fee Rules', 'alarm', 'settings'],
            ['settings/fee_heads', 'Fee Heads', 'tags', 'settings'],
            ['settings/fee_structure', 'Fee Structure', 'table', 'settings'],
            ['settings/transport', 'Transport Routes', 'bus-front', 'settings'],
            ['settings/concession_types', 'Concession Types', 'percent', 'settings'],
        ]],
        ['Settings', 'gear', [
            ['settings/school', 'School Profile', 'building', 'settings'],
            ['settings/fee_rules', 'Due Date & Late Fee', 'calendar-check', 'settings'],
            ['settings/users', 'Users', 'person-badge', 'settings'],
            ['settings/roles', 'Roles & Permissions', 'shield-lock', 'settings'],
            ['settings/audit', 'Activity Log', 'clock-history', 'settings'],
            ['settings/backup', 'Backup', 'database-down', 'settings'],
        ]],
    ];
}

function render_page(string $title, string $content, string $page): void
{
    $u = user();
    $school = setting('school_name', 'My School');
    $logo = setting('school_logo', '');
    $years = rows('SELECT id, name, is_current FROM academic_years ORDER BY start_date DESC');
    $wy = working_year();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($school) ?></title>
<link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/lib/icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/app.css?v=<?= APP_VERSION ?>" rel="stylesheet">
</head>
<body>
<div class="app">
  <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar">
    <div class="brand">
      <?php if ($logo): ?><img src="file.php?f=<?= h($logo) ?>" alt=""><?php else: ?><i class="bi bi-mortarboard-fill"></i><?php endif; ?>
      <span><?= h($school) ?></span>
      <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button>
    </div>
    <nav class="menu">
    <?php foreach (menu_sections() as $i => [$sec, $icon, $items]):
        $items = array_filter($items, fn($it) => can($it[3]));
        if (!$items) continue;
        $open = false;
        foreach ($items as $it) if (strpos($page, $it[0]) === 0 || ($it[0] === 'students/list' && strpos($page, 'students/view') === 0)) $open = true;
        if (count($items) === 1): $it = reset($items); ?>
        <a class="menu-link top <?= $page === $it[0] ? 'active' : '' ?>" href="<?= url($it[0]) ?>"><i class="bi bi-<?= $icon ?>"></i><?= h($it[1]) ?></a>
        <?php else: ?>
        <button class="menu-section <?= $open ? '' : 'collapsed' ?>" data-bs-toggle="collapse" data-bs-target="#m<?= $i ?>" type="button">
          <i class="bi bi-<?= $icon ?>"></i><span><?= h($sec) ?></span><i class="bi bi-chevron-down chev"></i>
        </button>
        <div class="collapse <?= $open ? 'show' : '' ?>" id="m<?= $i ?>">
          <?php foreach ($items as $it): ?>
            <a class="menu-link <?= strpos($page, $it[0]) === 0 ? 'active' : '' ?>" href="<?= url($it[0]) ?>"><i class="bi bi-<?= $it[2] ?>"></i><?= h($it[1]) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    </nav>
  </aside>
  <main class="main">
    <header class="topbar">
      <button class="btn btn-light d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#sidebar"><i class="bi bi-list"></i></button>
      <h1 class="page-title"><?= h($title) ?></h1>
      <div class="ms-auto d-flex align-items-center gap-2">
        <?php if ($years): ?>
        <form method="post" action="<?= url('year_switch') ?>" class="d-none d-sm-block">
          <?= csrf_field() ?>
          <select name="year_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Academic year">
            <?php foreach ($years as $y): ?>
              <option value="<?= $y['id'] ?>" <?= $wy && $wy['id'] == $y['id'] ? 'selected' : '' ?>><?= h($y['name']) ?><?= $y['is_current'] ? ' (current)' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
        </form>
        <?php endif; ?>
        <div class="dropdown">
          <button class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-person-circle"></i> <span class="d-none d-md-inline"><?= h($u['name']) ?></span></button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text small text-muted"><?= h($u["role_name"] ?? "") ?></span></li>
            <li><a class="dropdown-item" href="<?= url('account') ?>"><i class="bi bi-key"></i> Change password</a></li>
            <li><a class="dropdown-item" href="<?= url('logout') ?>"><i class="bi bi-box-arrow-right"></i> Log out</a></li>
          </ul>
        </div>
      </div>
    </header>
    <div class="content">
      <?php if ($wy && !$wy['is_current']): ?><div class="alert alert-warning py-2 small">You are viewing academic year <b><?= h($wy['name']) ?></b>, not the current year.</div><?php endif; ?>
      <?= flashes() ?>
      <?= $content ?>
    </div>
  </main>
</div>
<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
<?php
}

function render_bare(string $title, string $content): void
{
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/lib/icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/app.css?v=<?= APP_VERSION ?>" rel="stylesheet">
</head><body class="bare"><?= $content ?></body></html>
<?php
}

/** Small UI helpers */
function card_stat(string $label, string $value, string $icon, string $color = 'primary', string $sub = ''): string
{
    return '<div class="col-6 col-xl-3"><div class="stat card h-100"><div class="card-body"><div class="stat-icon bg-' . $color . '-subtle text-' . $color . '"><i class="bi bi-' . $icon . '"></i></div>'
        . '<div class="stat-label">' . h($label) . '</div><div class="stat-value">' . h($value) . '</div>'
        . ($sub ? '<div class="small text-muted">' . h($sub) . '</div>' : '') . '</div></div></div>';
}

function status_badge(string $s): string
{
    $map = ['active' => 'success', 'left' => 'secondary', 'passed_out' => 'info', 'promoted' => 'info', 'detained' => 'warning',
        'Paid' => 'success', 'Overdue' => 'danger', 'Up to date' => 'warning', 'Cancelled' => 'danger'];
    return '<span class="badge text-bg-' . ($map[$s] ?? 'light') . '">' . h(ucwords(str_replace('_', ' ', $s))) . '</span>';
}

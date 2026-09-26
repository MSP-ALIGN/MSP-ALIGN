<?php
use Align\Auth;

$u = Auth::user();
$nav = $nav ?? '';
$client = $client ?? null;
$clientNav = $clientNav ?? '';
$v = e(APP_VERSION);

$unassignedCount = $u ? (int) \Align\DB::value("SELECT COUNT(*) FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
    WHERE d.removed_at IS NULL AND COALESCE(o.device_type, d.device_type) = 'Unassigned' AND COALESCE(o.excluded, 0) = 0") : 0;
// Global menu, grouped by the vCIO workflow: know the client -> plan -> meet and report -> stay compliant.
$isAdmin = $u && Auth::can('admin');
$navSections = [
    '' => [
        ['dashboard', '/', 'Dashboard', 'fa-gauge-high', 'viewer'],
        ['clients', '/clients', 'Clients', 'fa-users', 'viewer'],
        ['contacts', '/contacts', 'Contacts', 'fa-address-book', 'viewer'],
    ],
    'PLANNING' => [
        ['projects', '/projects', 'Projects', 'fa-diagram-project', 'viewer'],
        ['budget', '/budget', 'Budgets', 'fa-coins', 'viewer'],
        ['licenses', '/licenses', 'Licensing', 'fa-key', 'viewer'],
        ['renewals', '/renewals', 'Renewals', 'fa-calendar-check', 'viewer'],
    ],
    'MEETINGS & REPORTS' => [
        ['meetings', '/meetings', 'Meetings', 'fa-handshake', 'viewer'],
        ['reports', '/reports', 'Reports', 'fa-print', 'viewer'],
    ],
    'COMPLIANCE' => [
        ['compliance', '/compliance', 'Compliance', 'fa-clipboard-check', 'viewer'],
        ['documents', '/documents', 'Documents', 'fa-file-lines', 'viewer'],
    ],
    'INTEGRATIONS' => [
        ['integrations', '/integrations', 'Integrations', 'fa-plug', 'admin', $isAdmin ? \Align\Integrations\Registry::problems() : 0],
        ['mapping', '/mapping', 'Client mapping', 'fa-link', 'tech'],
        ['sync', '/sync', 'Sync', 'fa-rotate', 'viewer'],
        ['unassigned', '/devices/unassigned', 'Unassigned hardware', 'fa-circle-question', 'viewer', $unassignedCount],
    ],
    'ADMIN' => [
        ['settings', '/settings', 'Settings', 'fa-gear', 'admin', $isAdmin && \Align\System\Agent::updateAvailable() ? 'new' : 0],
        ['users', '/users', 'Users', 'fa-user-shield', 'admin'],
        ['portal-users', '/portal-users', 'Client portal users', 'fa-door-open', 'tech'],
        ['audit', '/audit', 'Audit log', 'fa-clock-rotate-left', 'admin'],
    ],
    ' ' => [
        ['help', '/help', 'Help & how-to', 'fa-circle-info', 'viewer'],
    ],
];
// Client menu in workflow order: who they are and what they have -> compliance -> plan -> meet.
$clientMenu = $client ? [
    ['overview', '/clients/' . (int) $client['id'], 'Overview', 'fa-tachometer-alt'],
    ['contacts', '/clients/' . (int) $client['id'] . '/contacts', 'Contacts', 'fa-address-book'],
    ['devices', '/clients/' . (int) $client['id'] . '/devices', 'Devices & assets', 'fa-desktop'],
    ['licenses', '/clients/' . (int) $client['id'] . '/licenses', 'Licensing', 'fa-key'],
    ...(!empty($client['veeam_company_uid']) || \Align\Integrations\VeeamSpc::configured() ? [['backups', '/clients/' . (int) $client['id'] . '/backups', 'Backups', 'fa-database']] : []),
    ['compliance', '/clients/' . (int) $client['id'] . '/compliance', 'Compliance', 'fa-clipboard-check'],
    ['documents', '/clients/' . (int) $client['id'] . '/documents', 'Documents', 'fa-file-lines'],
    ['roadmap', '/clients/' . (int) $client['id'] . '/roadmap', 'Roadmap & projects', 'fa-road'],
    ['budget', '/clients/' . (int) $client['id'] . '/budget', 'Budget', 'fa-coins'],
    ['meetings', '/clients/' . (int) $client['id'] . '/meetings', 'Meetings', 'fa-handshake'],
    ...(Auth::can('tech') ? [['portal', '/clients/' . (int) $client['id'] . '/portal', 'Client portal', 'fa-door-open']] : []),
] : [];
$item = function (array $i, string $active) {
    [$key, $href, $label, $icon] = $i;
    $badge = !empty($i[5]) ? ' <span class="badge badge-' . (is_string($i[5]) ? 'info' : 'warning') . ' right">' . (is_string($i[5]) ? e($i[5]) : (int) $i[5]) . '</span>' : '';
    return '<li class="nav-item"><a href="' . e($href) . '" class="nav-link' . ($active === $key ? ' active' : '') . '">'
        . '<i class="nav-icon fas ' . e($icon) . '"></i><p>' . e($label) . $badge . '</p></a></li>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!empty($refresh)): ?><meta http-equiv="refresh" content="5"><?php endif; ?>
<?php if ($u): ?><meta name="align-idle" content="<?= \Align\Security::idleSeconds() ?>" data-ping="/session/ping" data-logout="/logout" data-login="/login" data-csrf="<?= e(csrf_token()) ?>"><?php endif; ?>
<title><?= e($title ?? '') ?> | <?= e(\Align\Branding::name()) ?></title>
<link rel="icon" href="<?= e(\Align\Branding::logoUrl()) ?>">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= $v ?>">
<script src="/vendor/jquery/jquery.min.js?v=<?= $v ?>" defer></script>
<script src="/vendor/bootstrap/bootstrap.bundle.min.js?v=<?= $v ?>" defer></script>
<script src="/vendor/adminlte/adminlte.min.js?v=<?= $v ?>" defer></script>
<?php if (!empty($calendar)): ?><script src="/vendor/fullcalendar/index.global.min.js?v=<?= $v ?>" defer></script><?php endif; ?>
<?php if (!empty($editor)): ?><link rel="stylesheet" href="/vendor/quill/quill.snow.css?v=<?= $v ?>"><script src="/vendor/quill/quill.js?v=<?= $v ?>" defer></script><script src="/assets/docs.js?v=<?= $v ?>" defer></script><?php endif; ?>
<script src="/assets/app.js?v=<?= $v ?>" defer></script>
<?php if ($brandCss = \Align\Branding::css()): ?><style><?= $brandCss ?></style><?php endif; ?>
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed text-sm">
<div class="wrapper">

  <nav class="main-header navbar navbar-expand navbar-dark navbar-primary">
    <ul class="navbar-nav">
      <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#" role="button" aria-label="Toggle menu"><i class="fas fa-bars"></i></a></li>
      <?php if ($client): ?>
        <li class="nav-item d-none d-sm-inline-block"><a href="/clients/<?= (int) $client['id'] ?>" class="nav-link font-weight-bold"><?= e($client['name']) ?></a></li>
      <?php endif; ?>
    </ul>
    <form class="form-inline ml-3 d-none d-md-flex" action="/clients" method="get">
      <div class="input-group input-group-sm">
        <input class="form-control form-control-navbar" type="search" name="q" placeholder="Search clients" aria-label="Search clients" value="<?= e($_GET['q'] ?? '') ?>">
        <div class="input-group-append"><button class="btn btn-navbar" type="submit" aria-label="Search"><i class="fas fa-search"></i></button></div>
      </div>
    </form>
    <ul class="navbar-nav ml-auto">
      <?php if (Auth::can('tech')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link" data-toggle="dropdown" href="#" title="Create"><i class="fas fa-plus"></i></a>
          <div class="dropdown-menu dropdown-menu-right">
            <a href="#" class="dropdown-item" data-toggle="modal" data-target="#modal-meeting"><i class="fas fa-fw fa-handshake mr-2"></i>Schedule meeting</a>
            <a href="/clients?add=1" class="dropdown-item"><i class="fas fa-fw fa-user-plus mr-2"></i>New client</a>
            <?php if ($client): ?><a href="/clients/<?= (int) $client['id'] ?>/devices?add=1" class="dropdown-item"><i class="fas fa-fw fa-desktop mr-2"></i>Add device to <?= e($client['name']) ?></a><?php endif; ?>
          </div>
        </li>
      <?php endif; ?>
      <li class="nav-item dropdown user-menu">
        <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">
          <?= user_avatar($u ?? [], 'user-initials') ?>
          <span class="d-none d-md-inline ml-1"><?= e($u['name'] ?? '') ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-right">
          <span class="dropdown-item-text small text-muted"><?= e($u['email'] ?? '') ?> · <?= e($u['role'] ?? '') ?></span>
          <a href="/help" class="dropdown-item"><i class="fas fa-fw fa-circle-info mr-2"></i>Help &amp; how-to</a>
          <div class="dropdown-divider"></div>
          <a href="/account" class="dropdown-item"><i class="fas fa-fw fa-user-gear mr-2"></i>Account &amp; 2FA</a>
          <form method="post" action="/logout"><?= csrf_field() ?><button class="dropdown-item"><i class="fas fa-fw fa-right-from-bracket mr-2"></i>Sign out</button></form>
        </div>
      </li>
    </ul>
  </nav>

  <aside class="main-sidebar sidebar-<?= \Align\Branding::sidebar() ?>-primary elevation-4">
    <a href="/" class="brand-link<?= \Align\Branding::logoOnly() ? ' brand-logo-only' : '' ?>" title="<?= e(\Align\Branding::name()) ?>">
      <img src="<?= e(\Align\Branding::logoUrl()) ?>" alt="<?= e(\Align\Branding::name()) ?>" class="brand-image">
      <?php if (!\Align\Branding::logoOnly()): ?><span class="brand-text font-weight-bold"><?= e(\Align\Branding::name()) ?></span><?php endif; ?>
    </a>
    <div class="sidebar">
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" data-widget="treeview" role="menu">
          <?php if ($client): ?>
            <li class="nav-item"><a href="/clients" class="nav-link"><i class="nav-icon fas fa-arrow-left"></i><p>All clients</p></a></li>
            <li class="nav-header text-truncate"><?= e(mb_strtoupper($client['name'])) ?></li>
            <?php foreach ($clientMenu as $i) echo $item($i, $clientNav); ?>
            <li class="nav-item has-treeview mt-2">
              <a href="#" class="nav-link"><i class="nav-icon fas fa-grip"></i><p>All tools<i class="right fas fa-angle-left"></i><?= $unassignedCount ? ' <span class="badge badge-warning ml-1" title="Unassigned hardware">' . (int) $unassignedCount . '</span>' : '' ?></p></a>
              <ul class="nav nav-treeview">
                <?php foreach ($navSections as $sec => $items) foreach ($items as $i) if (Auth::can($i[4]) && $i[0] !== 'clients') echo $item($i, ''); ?>
              </ul>
            </li>
          <?php else: ?>
            <?php foreach ($navSections as $sec => $items):
                $visible = array_filter($items, fn($i) => Auth::can($i[4]));
                if (!$visible) continue;
                if (trim($sec) !== '') echo '<li class="nav-header">' . e($sec) . '</li>'; elseif ($sec === ' ') echo '<li class="nav-header py-1"></li>';
                foreach ($visible as $i) echo $item($i, $nav);
            endforeach; ?>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  </aside>

  <div class="content-wrapper">
    <section class="content">
      <div class="container-fluid pt-3 pb-4">
        <?php foreach (take_flashes() as $f): $t = ['success' => 'success', 'error' => 'danger', 'info' => 'info', 'warning' => 'warning'][$f['type']] ?? 'info'; ?>
          <div class="alert alert-<?= $t ?> alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">&times;</button>
            <i class="fas fa-<?= $t === 'success' ? 'check' : ($t === 'danger' || $t === 'warning' ? 'exclamation-triangle' : 'info-circle') ?> mr-2"></i><?= e($f['message']) ?>
          </div>
        <?php endforeach; ?>
        <?php if (!str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/settings') && Auth::can('admin') && ($upd = \Align\System\Agent::updateAvailable())): ?>
          <div class="alert alert-info py-2 d-flex align-items-center flex-wrap" role="status">
            <i class="fas fa-circle-arrow-up mr-2"></i>
            <span class="mr-3">Mountaineer Align <b><?= e($upd['latest']) ?></b> is available. You have <?= $v ?>.</span>
            <a class="btn btn-sm btn-light ml-auto" href="/settings/system">See what's new and update</a>
          </div>
        <?php endif; ?>
        <?= $content ?>
      </div>
    </section>
  </div>

  <footer class="main-footer text-sm">
    <span class="text-muted"><?= e(\Align\Branding::name()) ?> v<?= $v ?><?= \Align\Settings::get('company_name') ? ' · ' . e(\Align\Settings::get('company_name')) : '' ?></span>
    <span class="float-right small"><a href="/terms" class="text-muted">Terms of use</a> · <a href="/license" class="text-muted">License</a> · <a href="<?= e(\Align\Controllers\LegalController::sourceUrl()) ?>" class="text-muted" target="_blank" rel="noopener">Source</a></span>
  </footer>
</div>
<?php if (Auth::can('tech') && empty($noMeetingModal)): ?>
  <?= \Align\View::fetch('partials/meeting_modal', [
      'modalClients' => $modalClients ?? \Align\DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'),
      'modalUsers' => \Align\Controllers\ClientController::users(),
      'presetClient' => $client['id'] ?? null,
  ]) ?>
<?php endif; ?>
</body>
</html>

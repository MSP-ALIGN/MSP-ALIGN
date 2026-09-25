<?php
use Align\Auth;

$u = Auth::user();
$nav = $nav ?? '';
$client = $client ?? null;
$clientNav = $clientNav ?? '';
$v = e(APP_VERSION);

$mainNav = [
    ['dashboard', '/', 'Dashboard', 'fa-gauge-high', 'viewer'],
    ['clients', '/clients', 'Clients', 'fa-users', 'viewer'],
    ['projects', '/projects', 'Projects', 'fa-diagram-project', 'viewer'],
    ['calendar', '/calendar', 'Calendar', 'fa-calendar-days', 'viewer'],
    ['meetings', '/meetings', 'Meetings', 'fa-handshake', 'viewer'],
    ['compliance', '/compliance', 'Compliance', 'fa-clipboard-check', 'viewer'],
    ['documents', '/documents', 'Documents', 'fa-file-lines', 'viewer'],
    ['reports', '/reports', 'Reports', 'fa-print', 'viewer'],
];
$unassignedCount = $u ? (int) \Align\DB::value("SELECT COUNT(*) FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
    WHERE d.removed_at IS NULL AND COALESCE(o.device_type, d.device_type) = 'Unassigned' AND COALESCE(o.excluded, 0) = 0") : 0;
$integrationNav = [
    ['unassigned', '/devices/unassigned', 'Unassigned hardware', 'fa-circle-question', 'viewer', $unassignedCount],
    ['mapping', '/mapping', 'Client mapping', 'fa-link', 'tech'],
    ['sync', '/sync', 'Sync', 'fa-rotate', 'viewer'],
];
$adminNav = [
    ['settings', '/settings', 'Settings', 'fa-gear', 'admin'],
    ['branding', '/settings/branding', 'Branding', 'fa-palette', 'admin'],
    ['frameworks', '/frameworks', 'Frameworks', 'fa-list-check', 'admin'],
    ['users', '/users', 'Users', 'fa-user-shield', 'admin'],
    ['audit', '/audit', 'Audit log', 'fa-clock-rotate-left', 'admin'],
];
$clientMenu = $client ? [
    ['overview', '/clients/' . (int) $client['id'], 'Overview', 'fa-tachometer-alt'],
    ['devices', '/clients/' . (int) $client['id'] . '/devices', 'Devices & assets', 'fa-desktop'],
    ['roadmap', '/clients/' . (int) $client['id'] . '/roadmap', '3-year roadmap', 'fa-road'],
    ['meetings', '/clients/' . (int) $client['id'] . '/meetings', 'Meetings', 'fa-handshake'],
    ['compliance', '/clients/' . (int) $client['id'] . '/compliance', 'Compliance', 'fa-clipboard-check'],
    ['documents', '/clients/' . (int) $client['id'] . '/documents', 'Documents', 'fa-file-lines'],
] : [];
$item = function (array $i, string $active) {
    [$key, $href, $label, $icon] = $i;
    $badge = !empty($i[5]) ? ' <span class="badge badge-warning right">' . (int) $i[5] . '</span>' : '';
    return '<li class="nav-item"><a href="' . e($href) . '" class="nav-link' . ($active === $key ? ' active' : '') . '">'
        . '<i class="nav-icon fas ' . e($icon) . '"></i><p>' . e($label) . $badge . '</p></a></li>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!empty($refresh)): ?><meta http-equiv="refresh" content="5"><?php endif; ?>
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
          <span class="user-initials"><?= e(initials($u['name'] ?? '')) ?></span>
          <span class="d-none d-md-inline ml-1"><?= e($u['name'] ?? '') ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-right">
          <span class="dropdown-item-text small text-muted"><?= e($u['email'] ?? '') ?> · <?= e($u['role'] ?? '') ?></span>
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
            <li class="nav-item"><a href="/clients" class="nav-link"><i class="nav-icon fas fa-arrow-left"></i><p>Back to clients</p></a></li>
            <li class="nav-header text-truncate"><?= e(mb_strtoupper($client['name'])) ?></li>
            <?php foreach ($clientMenu as $i) echo $item($i, $clientNav); ?>
            <li class="nav-header">GLOBAL</li>
          <?php endif; ?>
          <?php foreach ($mainNav as $i) if (Auth::can($i[4]) && !($client && $i[0] === 'clients')) echo $item($i, $client ? '' : $nav); ?>
          <?php if (Auth::can('viewer')): ?><li class="nav-header">INTEGRATIONS</li><?php endif; ?>
          <?php foreach ($integrationNav as $i) if (Auth::can($i[4])) echo $item($i, $nav); ?>
          <?php if (Auth::can('admin')): ?><li class="nav-header">ADMIN</li><?php endif; ?>
          <?php foreach ($adminNav as $i) if (Auth::can($i[4])) echo $item($i, $nav); ?>
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
        <?= $content ?>
      </div>
    </section>
  </div>

  <footer class="main-footer text-sm">
    <span class="text-muted"><?= e(\Align\Branding::name()) ?> v<?= $v ?><?= \Align\Settings::get('company_name') ? ' · ' . e(\Align\Settings::get('company_name')) : '' ?></span>
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

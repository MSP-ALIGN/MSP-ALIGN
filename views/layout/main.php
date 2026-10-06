<?php
/**
 * The staff layout: top bar, menu, flash messages, banners and $content (the page, already rendered and escaped).
 * Vars: $title, $content; optional $nav, $client (sets the client menu), $clientNav, $refresh, $calendar, $editor,
 * $contractsJs, $modalClients, $noMeetingModal.
 *
 * Security: everything printed from data is escaped with e(): the app and company names, user names and emails,
 * client names, flash messages and badges (badge counts are cast to int). The brand colour CSS comes from
 * Branding::css(), which only prints a validated #rrggbb. The source link is http(s) only (LegalController::
 * sourceUrl). Hrefs are fixed paths or built from integer ids. Menu items are filtered by role here only for
 * display: every route checks its own role.
 */
use Align\Auth;

$u = Auth::user();
// Counts, problem badges and the meeting form's client and staff lists only once sign-in is complete: not while a
// temporary password or 2FA set-up is pending (those users can only reach /account) (1.45)
$ready = $u && !$u['must_change_password'] && $u['totp_enabled'];
$nav = $nav ?? '';
$client = $client ?? null;
$clientNav = $clientNav ?? '';
$v = e(APP_VERSION);

$todoCount = $ready ? \Align\Workflow\Todo::count() : 0;
// Global menu (1.42), grouped by the vCIO workflow: know the clients -> plan -> meet and report -> stay compliant;
// setup tools live under Admin as pages with tabs (Integrations, People).
$isAdmin = $ready && Auth::can('admin');
$adminTabs = \Align\Workflow\Todo::adminTabs();
$firstTab = fn(string $group) => ($t = array_values(array_filter($adminTabs[$group], fn($t) => Auth::can($t['role'])))) ? $t[0]['href'] : null;
$navSections = [
    '' => [
        ['dashboard', '/', 'Dashboard', 'fa-gauge-high', 'viewer'],
        ['todo', '/todo', 'To do', 'fa-list-check', 'tech', $todoCount],
    ],
    'CLIENTS' => [
        ['clients', '/clients', 'Clients', 'fa-users', 'viewer'],
        ['contacts', '/contacts', 'Contacts', 'fa-address-book', 'viewer'],
        ['devices', '/devices', 'Devices & assets', 'fa-desktop', 'viewer'],
    ],
    'ONBOARDING' => [
        ...(($h = $firstTab('contracts')) ? [['contracts', $h, 'Contracts', 'fa-file-signature', 'tech', $ready && Auth::can('tech') ? \Align\Workflow\Todo::contractsWaiting() : 0]] : []),
        ...(($h = $firstTab('newclients')) ? [['newclients', $h, 'New clients', 'fa-mountain-sun', 'tech']] : []),
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
    'ADMIN' => [
        ...(($h = $firstTab('integrations')) ? [['integrations', $h, 'Integrations', 'fa-plug', 'viewer', $isAdmin ? \Align\Integrations\Registry::problems() : 0]] : []),
        ...(($h = $firstTab('people')) ? [['people', $h, 'People', 'fa-user-shield', 'viewer']] : []),
        ['settings', '/settings', 'Settings', 'fa-gear', 'admin', $isAdmin && \Align\System\Agent::updateAvailable() ? 'new' : 0],
        ['audit', '/audit', 'Audit log', 'fa-clock-rotate-left', 'admin'],
    ],
];
// Pages that belong to a menu item with tabs (their own nav key picks the tab)
$navGroup = ['mapping' => 'integrations', 'hosted-backups' => 'integrations', 'sync' => 'integrations', 'users' => 'people', 'portal-users' => 'people', 'unassigned' => 'devices',
    'contract-templates' => 'contracts', 'welcome' => 'newclients'];
$tabKey = $nav;
$nav = $navGroup[$nav] ?? $nav;
// Client menu in workflow order (1.42: grouped): what they have -> the plan -> meetings.
$cidM = $client ? (int) $client['id'] : 0;
$clientMenu = $client ? [
    ['overview', "/clients/$cidM", 'Overview', 'fa-tachometer-alt'],
    ...(($onb = \Align\DB::one('SELECT completed_at FROM client_onboardings WHERE client_id = ?', [$cidM])) && (!$onb['completed_at'] || strtotime($onb['completed_at']) > strtotime('-30 days')) || $clientNav === 'onboarding'
        ? [['onboarding', "/clients/$cidM/onboarding", 'Onboarding', 'fa-mountain-sun', 'viewer', $onb && !$onb['completed_at'] ? 'open' : null]] : []),
    'THEIR IT',
    ['contacts', "/clients/$cidM/contacts", 'Contacts', 'fa-address-book'],
    ['devices', "/clients/$cidM/devices", 'Devices & assets', 'fa-desktop'],
    ['licenses', "/clients/$cidM/licenses", 'Licensing', 'fa-key'],
    ...(\Align\Backup\Backup::has($client) || \Align\Providers\Providers::anyBackup() ? [['backups', "/clients/$cidM/backups", 'Backups', 'fa-database']] : []),
    ...(\Align\Service\Sla::enabled() && !empty($client['psa_id']) ? [['service', "/clients/$cidM/service-levels", 'Service levels', 'fa-stopwatch']] : []),
    'THE PLAN',
    ['roadmap', "/clients/$cidM/roadmap", 'Roadmap & projects', 'fa-road'],
    ['budget', "/clients/$cidM/budget", 'Budget', 'fa-coins'],
    ['alignment', "/clients/$cidM/alignment", 'Alignment', 'fa-bullseye'], // 2.3.0: measured against the MSP's own standards
    ['compliance', "/clients/$cidM/compliance", 'Compliance', 'fa-clipboard-check'],
    ['documents', "/clients/$cidM/documents", 'Documents', 'fa-file-lines'],
    'MEETINGS',
    ['meetings', "/clients/$cidM/meetings", 'Meetings', 'fa-handshake'],
    ['reports', "/clients/$cidM/reports", 'Reports', 'fa-print'],
    ...(Auth::can('tech') ? [['portal', "/clients/$cidM/portal", 'Client portal', 'fa-door-open']] : []),
] : [];
$item = function (array $i, string $active) {
    [$key, $href, $label, $icon] = $i;
    $badge = !empty($i[5]) ? ' <span class="nav-badge badge text-bg-' . (is_string($i[5]) ? 'info' : 'warning') . ' me-2">' . (is_string($i[5]) ? e($i[5]) : (int) $i[5]) . '</span>' : '';
    return '<li class="nav-item"><a href="' . e($href) . '" class="nav-link' . ($active === $key ? ' active' : '') . '"' . ($active === $key ? ' aria-current="page"' : '') . '>'
        . '<i class="nav-icon fas fa-fw ' . e($icon) . '"></i><p>' . e($label) . $badge . '</p></a></li>';
};
// Light / dark (1.43): each user picks under Account; "auto" follows the computer (set before the page draws)
$theme = in_array($u['theme'] ?? 'auto', ['light', 'dark'], true) ? $u['theme'] : 'auto';
?><!doctype html>
<html lang="en" data-bs-theme="<?= $theme === 'dark' ? 'dark' : 'light' ?>" data-theme-pref="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="/assets/theme.js?v=<?= $v ?>"></script>
<?php if (!empty($refresh)): ?><meta http-equiv="refresh" content="5"><?php endif; ?>
<?php if ($u): ?><meta name="align-idle" content="<?= \Align\Security::idleSeconds() ?>" data-ping="/session/ping" data-logout="/logout" data-login="/login" data-csrf="<?= e(csrf_token()) ?>"><?php endif; ?>
<title><?= \Align\Staging::on() ? '[TEST] ' : '' ?><?= e($title ?? '') ?> | <?= e(\Align\Branding::name()) ?></title>
<link rel="icon" href="<?= e(\Align\Branding::lightLogoUrl()) ?>"><?php // browser tabs are mostly light (2.2.4) ?>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= $v ?>">
<script src="/vendor/bootstrap/bootstrap.bundle.min.js?v=<?= $v ?>" defer></script>
<script src="/vendor/adminlte/adminlte.min.js?v=<?= $v ?>" defer></script>
<?php if (!empty($calendar)): ?><script src="/vendor/fullcalendar/index.global.min.js?v=<?= $v ?>" defer></script><?php endif; ?>
<?php if (!empty($editor)): ?><link rel="stylesheet" href="/vendor/quill/quill.snow.css?v=<?= $v ?>"><script src="/vendor/quill/quill.js?v=<?= $v ?>" defer></script><script src="/assets/docs.js?v=<?= $v ?>" defer></script><?php endif; ?>
<script src="/assets/app.js?v=<?= $v ?>" defer></script>
<?php if (!empty($contractsJs)): ?><script src="/assets/pdfview.js?v=<?= $v ?>" defer></script><script src="/assets/contracts.js?v=<?= $v ?>" defer></script><?php endif; ?>
<?php if ($brandCss = \Align\Branding::css()): ?><style><?= $brandCss ?></style><?php endif; ?>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-mini app-staff<?= \Align\Staging::on() ? ' is-staging' : '' ?>" data-fmt="<?= e(json_encode(\Align\Fmt::forJs(), JSON_UNESCAPED_UNICODE)) ?>">
<div class="app-wrapper">

  <nav class="app-header navbar navbar-expand">
    <div class="container-fluid">
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Toggle menu"><i class="fas fa-bars"></i></a></li>
        <?php if ($client): ?>
          <li class="nav-item d-none d-sm-inline-block"><a href="/clients/<?= (int) $client['id'] ?>" class="nav-link fw-semibold"><?= e($client['name']) ?></a></li>
        <?php endif; ?>
      </ul>
      <form class="d-none d-md-flex ms-2 app-search" action="/search" method="get" role="search">
        <div class="input-group input-group-sm navbar-search-wide">
          <span class="input-group-text"><i class="fas fa-search"></i></span>
          <input class="form-control" type="search" name="q" placeholder="Search clients, devices, serials, contacts, licenses" aria-label="Search" value="<?= e(($nav === 'search' || $nav === 'clients') ? query('q') : '') ?>">
        </div>
      </form>
      <ul class="navbar-nav ms-auto align-items-center">
        <?php if ($ready && Auth::can('tech')): // (the meeting form it opens is only on the page once sign-in is complete) ?>
          <li class="nav-item dropdown">
            <a class="nav-link" data-bs-toggle="dropdown" href="#" title="Create" aria-label="Create"><i class="fas fa-plus"></i></a>
            <div class="dropdown-menu dropdown-menu-end">
              <a href="#" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#modal-meeting"><i class="fas fa-fw fa-handshake me-2"></i>Schedule meeting</a>
              <a href="/clients?add=1" class="dropdown-item"><i class="fas fa-fw fa-user-plus me-2"></i>New client</a>
              <?php if ($client): ?><a href="/clients/<?= (int) $client['id'] ?>/devices?add=1" class="dropdown-item"><i class="fas fa-fw fa-desktop me-2"></i>Add device to <?= e($client['name']) ?></a><?php endif; ?>
            </div>
          </li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="/help" title="Help &amp; how-to" aria-label="Help"><i class="fas fa-circle-question"></i></a></li>
        <li class="nav-item dropdown user-menu">
          <a href="#" class="nav-link dropdown-toggle d-flex align-items-center" data-bs-toggle="dropdown">
            <?= user_avatar($u ?? [], 'user-initials') ?>
            <span class="d-none d-md-inline ms-2"><?= e($u['name'] ?? '') ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-end">
            <span class="dropdown-item-text small text-muted"><?= e($u['email'] ?? '') ?> · <?= e($u['role'] ?? '') ?></span>
            <a href="/help" class="dropdown-item"><i class="fas fa-fw fa-circle-info me-2"></i>Help &amp; how-to</a>
            <div class="dropdown-divider"></div>
            <a href="/account" class="dropdown-item"><i class="fas fa-fw fa-user-gear me-2"></i>Account &amp; 2FA</a>
            <a href="/account#appearance" class="dropdown-item"><i class="fas fa-fw fa-circle-half-stroke me-2"></i>Light or dark</a>
            <form method="post" action="/logout"><?= csrf_field() ?><button class="dropdown-item"><i class="fas fa-fw fa-right-from-bracket me-2"></i>Sign out</button></form>
          </div>
        </li>
      </ul>
    </div>
  </nav>

  <aside class="app-sidebar shadow<?= \Align\Branding::sidebar() === 'light' ? ' sidebar-light' : '' ?>" data-bs-theme="<?= \Align\Branding::sidebar() === 'light' ? 'light' : 'dark' ?>">
    <div class="sidebar-brand">
      <a href="/" class="brand-link<?= \Align\Branding::logoOnly() ? ' brand-logo-only' : '' ?>" title="<?= e(\Align\Branding::name()) ?>">
        <img src="<?= e(\Align\Branding::menuLogoUrl()) ?>" alt="<?= e(\Align\Branding::name()) ?>" class="brand-image<?= \Align\Branding::anyLogo() ? '' : ' is-builtin' ?>"><?php // built-in mark: on a white tile (2.2.2) ?>
        <?php if (!\Align\Branding::logoOnly()): ?><span class="brand-text fw-semibold"><?= e(\Align\Branding::name()) ?></span><?php endif; ?>
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2" aria-label="Main menu">
        <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="navigation" data-accordion="false">
          <?php if ($client): ?>
            <li class="nav-item"><a href="/clients" class="nav-link"><i class="nav-icon fas fa-fw fa-arrow-left"></i><p>All clients</p></a></li>
            <li class="nav-item sidebar-client">
              <a href="/clients/<?= (int) $client['id'] ?>" class="sidebar-client-card" title="<?= e($client['name']) ?>">
                <span class="sidebar-client-badge"><?php if ($clientLogo = client_logo_url($client)): ?><img src="<?= e($clientLogo) ?>" alt=""><?php else: ?><?= e(initials($client['name'])) ?><?php endif; ?></span>
                <span class="sidebar-client-text"><span class="sidebar-client-label">Client</span><span class="sidebar-client-name"><?= e($client['name']) ?></span></span>
              </a>
            </li>
            <?php foreach ($clientMenu as $i) echo is_string($i) ? '<li class="nav-header">' . e($i) . '</li>' : $item($i, $clientNav); ?>
            <li class="nav-item mt-2">
              <a href="#" class="nav-link"><i class="nav-icon fas fa-fw fa-grip"></i><p>All tools<?= $todoCount ? ' <span class="nav-badge badge text-bg-warning me-4" title="To do">' . (int) $todoCount . '</span>' : '' ?><i class="nav-arrow fas fa-angle-right"></i></p></a>
              <ul class="nav nav-treeview">
                <?php foreach ($navSections as $sec => $items) foreach ($items as $i) if (Auth::can($i[4]) && $i[0] !== 'clients') echo $item($i, ''); ?>
              </ul>
            </li>
          <?php else: ?>
            <?php foreach ($navSections as $sec => $items):
                $visible = array_filter($items, fn($i) => Auth::can($i[4]));
                if (!$visible) continue;
                if (trim($sec) !== '') echo '<li class="nav-header">' . e($sec) . '</li>';
                foreach ($visible as $i) echo $item($i, $nav);
            endforeach; ?>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  </aside>

  <main class="app-main">
    <div class="app-content">
      <div class="container-fluid pt-3 pb-4">
        <?php if (\Align\Staging::on()): ?>
          <div class="alert alert-warning py-2 mb-3" role="status"><i class="fas fa-flask me-2"></i><b>Test server.</b> Changes here don't reach
            <?= e(\Align\Providers\Providers::psaName()) ?> or anyone's inbox: email goes <?= \Align\Staging::mailTo() ? 'only to ' . e((string) \Align\Staging::mailTo()) : 'nowhere' ?>, and the client portal and API are off.</div>
        <?php endif; ?>
        <?php if ($u && \Align\Demo\Demo::loaded()): ?>
          <div class="alert alert-light border py-2 mb-3 d-flex flex-wrap align-items-center" role="status"><i class="fas fa-flask text-warning me-2"></i>
            <span class="me-auto"><b>Demo data.</b> The demo clients are made up. Remove them before adding real clients or connecting your PSA, RMM or backups.</span>
            <?php if (\Align\Auth::can('admin')): ?><a class="btn btn-xs btn-default" href="/settings#demo-data">Demo data settings</a><?php endif; ?></div>
        <?php endif; ?>
        <?php foreach (take_flashes() as $f): $t = ['success' => 'success', 'error' => 'danger', 'info' => 'info', 'warning' => 'warning'][$f['type']] ?? 'info'; ?>
          <div class="alert alert-<?= $t ?> alert-dismissible fade show">
            <i class="fas fa-<?= $t === 'success' ? 'check' : ($t === 'danger' || $t === 'warning' ? 'exclamation-triangle' : 'info-circle') ?> me-2"></i><?= e($f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endforeach; ?>
        <?php if (!str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/settings') && $isAdmin && ($upd = \Align\System\Agent::updateAvailable())): // not before 2FA is set up, like the menu badge ?>
          <div class="alert alert-info py-2 d-flex align-items-center flex-wrap" role="status">
            <i class="fas fa-circle-arrow-up me-2"></i>
            <span class="me-3">MSP Align <b><?= e($upd['latest']) ?></b> is available. You have <?= $v ?>.</span>
            <a class="btn btn-sm btn-light ms-auto" href="/settings/system">See what's new and update</a>
          </div>
        <?php endif; ?>
        <?php if (isset($adminTabs[$nav])):
            $tabs = array_filter($adminTabs[$nav], fn($t) => Auth::can($t['role'])); if (count($tabs) > 1): ?>
          <ul class="nav nav-tabs group-tabs mb-3">
            <?php foreach ($tabs as $k => $t): ?><li class="nav-item"><a class="nav-link<?= $k === $tabKey ? ' active' : '' ?>" href="<?= e($t['href']) ?>"><i class="fas <?= e($t['icon']) ?> me-1"></i><?= e($t['label']) ?><?= !empty($t['badge']) ? ' <span class="badge text-bg-warning">' . (int) $t['badge'] . '</span>' : '' ?></a></li><?php endforeach; ?>
          </ul>
        <?php endif; endif; ?>
        <?= $content ?>
      </div>
    </div>
  </main>

  <footer class="app-footer small">
    <span class="text-muted"><?= e(\Align\Branding::name()) ?> v<?= $v ?><?= \Align\Settings::get('company_name') ? ' · ' . e(\Align\Settings::get('company_name')) : '' ?></span>
    <span class="float-end"><a href="/terms" class="text-muted">Terms of use</a> · <a href="/license" class="text-muted">License</a> · <a href="<?= e(\Align\Controllers\LegalController::sourceUrl()) ?>" class="text-muted" target="_blank" rel="noopener">Source</a></span>
  </footer>
</div>
<?php if ($ready && Auth::can('tech') && empty($noMeetingModal)): ?>
  <?= \Align\View::fetch('partials/meeting_modal', [
      'modalClients' => $modalClients ?? \Align\DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'),
      'modalUsers' => \Align\Controllers\ClientController::users(),
      'presetClient' => $client['id'] ?? null,
  ]) ?>
<?php endif; ?>
</body>
</html>

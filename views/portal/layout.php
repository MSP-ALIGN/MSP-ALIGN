<?php
/**
 * Client portal layout: top navigation, only the sections this portal user may see. $pu is null on pages shown
 * before sign-in. Every outside value is escaped; the nav hrefs and icons are the fixed strings below, and the
 * brand CSS is built from a validated #rrggbb colour (Branding::css). $content is the already-rendered page.
 */
$pu = $pu ?? null;
$nav = $nav ?? '';
$v = e(APP_VERSION);
$items = $pu ? array_filter([
    ['home', '/portal', 'Home', 'fa-house', true],
    ['roadmap', '/portal/roadmap', 'Roadmap', 'fa-road', $pu['can_roadmap']],
    ['budget', '/portal/budget', 'Budget', 'fa-coins', $pu['can_budget']],
    ['licensing', '/portal/licensing', 'Licensing', 'fa-key', $pu['can_budget']],
    ['devices', '/portal/devices', 'Devices', 'fa-desktop', $pu['can_devices']],
    ['compliance', '/portal/compliance', 'Compliance', 'fa-clipboard-check', $pu['can_devices']],
    ['documents', '/portal/documents', 'Documents', 'fa-file-lines', $pu['can_documents']],
    ['contacts', '/portal/contacts', 'Contacts', 'fa-address-book', $pu['can_documents']],
    ['meetings', '/portal/meetings', 'Meetings', 'fa-handshake', $pu['can_documents']],
    ['requests', '/portal/requests', 'Requests', 'fa-user-plus', $pu['can_contacts'] && \Align\Onboarding\Requests::enabled()],
], fn($i) => $i[4]) : [];
// 1.42: the sections grouped into six tabs; a group with more than one page shows them as tabs under it
$groups = [
    ['home', 'Home', 'fa-house', ['home']],
    ['plan', 'Plan', 'fa-road', ['roadmap', 'budget']],
    ['tech', 'Your technology', 'fa-desktop', ['devices', 'licensing']],
    ['compliance', 'Compliance', 'fa-clipboard-check', ['compliance', 'documents']],
    ['meetings', 'Meetings', 'fa-handshake', ['meetings']],
    ['team', 'Your team', 'fa-user-group', ['contacts', 'requests']],
];
$byKey = array_column($items, null, 0);
$tabs = [];
$subTabs = [];
foreach ($groups as [$gk, $glabel, $gicon, $keys]) {
    $pages = array_values(array_filter(array_map(fn($k) => $byKey[$k] ?? null, $keys)));
    if (!$pages) {
        continue;
    }
    $active = in_array($nav, $keys, true);
    $tabs[] = [$gk, $pages[0][1], count($pages) === 1 ? $pages[0][2] : $glabel, count($pages) === 1 ? $pages[0][3] : $gicon, $active];
    if ($active && count($pages) > 1) {
        $subTabs = $pages;
    }
}
$clientLogo = $pu ? client_logo_url(['id' => $pu['client_id'], 'logo_file' => $pu['logo_file']]) : null;
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($pu): ?><meta name="align-idle" content="<?= \Align\Security::idleSeconds() ?>" data-ping="/portal/session/ping" data-logout="/portal/logout" data-login="/portal/login" data-csrf="<?= e(csrf_token()) ?>"><?php endif; ?>
<title><?= e($title ?? '') ?> | <?= e($pu['client_name'] ?? \Align\Branding::name()) ?></title>
<link rel="icon" href="<?= e(\Align\Branding::lightLogoUrl()) ?>">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= $v ?>">
<script src="/vendor/bootstrap/bootstrap.bundle.min.js?v=<?= $v ?>" defer></script>
<script src="/assets/app.js?v=<?= $v ?>" defer></script>
<?php if ($brandCss = \Align\Branding::css()): ?><style><?= $brandCss ?></style><?php endif; ?>
</head>
<body class="portal" data-fmt="<?= e(json_encode(\Align\Fmt::forJs(), JSON_UNESCAPED_UNICODE)) ?>">
<!-- 1.43: a friendlier client look — their logo up top, larger text, more room, plain-language sections -->
<header class="portal-top">
  <div class="container portal-container d-flex align-items-center">
    <a href="/portal" class="portal-brand d-flex align-items-center me-auto text-reset text-decoration-none min-w-0">
      <?php if ($clientLogo): ?>
        <span class="portal-brand-logo"><img src="<?= e($clientLogo) ?>" alt="<?= e($pu['client_name'] ?? '') ?>" class="portal-brand-img"></span>
      <?php else: ?>
        <img src="<?= e(\Align\Branding::lightLogoUrl()) ?>" alt="<?= e(\Align\Branding::name()) ?>" class="portal-brand-img portal-provider-img"><?php // the portal's top bar is white (2.2.4) ?>
      <?php endif; ?>
      <span class="portal-brand-text min-w-0">
        <span class="portal-brand-name"><?= e($pu['client_name'] ?? 'Client portal') ?></span>
        <span class="portal-brand-sub">IT portal<?= ($provider['company'] ?? '') !== '' ? ' · ' . e($provider['company']) : '' ?></span>
      </span>
    </a>
    <?php if ($pu): ?>
      <div class="dropdown ms-2">
        <a href="#" class="portal-account dropdown-toggle" data-bs-toggle="dropdown" aria-label="Account" aria-expanded="false"><span class="user-initials"><?= e(initials($pu['name'])) ?></span><span class="d-none d-md-inline ms-2"><?= e($pu['name']) ?></span></a>
        <div class="dropdown-menu dropdown-menu-end">
          <span class="dropdown-item-text small text-muted"><?= e($pu['email']) ?></span>
          <a href="/portal/account" class="dropdown-item<?= $nav === 'account' ? ' active' : '' ?>"><i class="fas fa-fw fa-user-gear me-2"></i>Account &amp; security</a>
          <a href="/portal/terms" class="dropdown-item<?= $nav === 'terms' ? ' active' : '' ?>"><i class="fas fa-fw fa-scale-balanced me-2"></i>Terms of use</a>
          <div class="dropdown-divider"></div>
          <form method="post" action="/portal/logout"><?= csrf_field() ?><button class="dropdown-item"><i class="fas fa-fw fa-right-from-bracket me-2"></i>Sign out</button></form>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($pu): ?>
    <!-- Sections on their own row, so every one fits with its icon; it scrolls sideways on a phone -->
    <nav class="portal-sections" aria-label="Portal sections">
      <div class="container portal-container">
        <ul class="nav">
          <?php foreach ($tabs as [$key, $href, $label, $icon, $active]): ?>
            <li class="nav-item"><a href="<?= $href ?>" class="nav-link<?= $active ? ' active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><i class="fas fa-fw <?= $icon ?> me-1"></i><?= e($label) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </nav>
  <?php endif; ?>
</header>

<main class="portal-main">
  <div class="container portal-container py-4">
    <?php if ($subTabs): ?>
      <ul class="nav nav-pills portal-subtabs mb-4">
        <?php foreach ($subTabs as [$key, $href, $label, $icon]): ?><li class="nav-item"><a class="nav-link<?= $nav === $key ? ' active' : '' ?>" href="<?= $href ?>"><i class="fas fa-fw <?= $icon ?> me-1"></i><?= e($label) ?></a></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php foreach (take_flashes() as $f): $t = ['success' => 'success', 'error' => 'danger', 'info' => 'info', 'warning' => 'warning'][$f['type']] ?? 'info'; ?>
      <div class="alert alert-<?= $t ?> alert-dismissible fade show">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endforeach; ?>
    <?= $content ?>
  </div>
</main>

<footer class="portal-footer">
  <div class="container portal-container d-flex flex-wrap justify-content-between gap-2">
    <span>Provided by <?= e(($provider['company'] ?? '') ?: \Align\Branding::name()) ?>
      <?php if (!empty($provider['phone'])): ?> · <?= e($provider['phone']) ?><?php endif; ?>
      <?php if (!empty($provider['email'])): ?> · <a href="mailto:<?= e($provider['email']) ?>"><?= e($provider['email']) ?></a><?php endif; ?>
    </span>
    <span>Only you and your IT provider can see this information. · <a href="/portal/terms">Terms of use</a> · <a href="/license">License</a> · <a href="<?= e(\Align\Controllers\LegalController::sourceUrl()) ?>" target="_blank" rel="noopener">Source</a></span>
  </div>
</footer>
</body>
</html>

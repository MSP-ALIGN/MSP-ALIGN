<?php
/** Client portal layout: top navigation, only the sections this portal user may see. */
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
$clientLogo = $pu ? client_logo_url(['id' => $pu['client_id'], 'logo_file' => $pu['logo_file']]) : null;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($pu): ?><meta name="align-idle" content="<?= \Align\Security::idleSeconds() ?>" data-ping="/portal/session/ping" data-logout="/portal/logout" data-login="/portal/login" data-csrf="<?= e(csrf_token()) ?>"><?php endif; ?>
<title><?= e($title ?? '') ?> | <?= e($pu['client_name'] ?? \Align\Branding::name()) ?></title>
<link rel="icon" href="<?= e(\Align\Branding::logoUrl()) ?>">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= $v ?>">
<script src="/vendor/jquery/jquery.min.js?v=<?= $v ?>" defer></script>
<script src="/vendor/bootstrap/bootstrap.bundle.min.js?v=<?= $v ?>" defer></script>
<script src="/vendor/adminlte/adminlte.min.js?v=<?= $v ?>" defer></script>
<script src="/assets/app.js?v=<?= $v ?>" defer></script>
<?php if ($brandCss = \Align\Branding::css()): ?><style><?= $brandCss ?></style><?php endif; ?>
</head>
<body class="hold-transition layout-top-nav text-sm portal" data-fmt="<?= e(json_encode(\Align\Fmt::forJs(), JSON_UNESCAPED_UNICODE)) ?>">
<div class="wrapper">
  <nav class="main-header navbar navbar-expand-xl navbar-dark navbar-primary">
    <div class="container">
      <a href="/portal" class="navbar-brand d-flex align-items-center">
        <img src="<?= e(\Align\Branding::logoUrl()) ?>" alt="<?= e(\Align\Branding::name()) ?>" class="brand-image portal-brand-img">
        <span class="brand-text font-weight-bold ml-2 text-truncate"><?= e($pu['client_name'] ?? 'Client portal') ?></span>
      </a>
      <?php if ($pu): ?>
        <button class="navbar-toggler order-1" type="button" data-toggle="collapse" data-target="#portal-nav" aria-controls="portal-nav" aria-expanded="false" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse order-3" id="portal-nav">
          <ul class="navbar-nav">
            <?php foreach ($items as [$key, $href, $label, $icon]): ?>
              <li class="nav-item"><a href="<?= $href ?>" class="nav-link<?= $nav === $key ? ' active' : '' ?>"><i class="fas fa-fw <?= $icon ?> mr-1 d-xl-none"></i><?= e($label) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <ul class="order-1 order-xl-3 navbar-nav navbar-no-expand ml-auto">
          <li class="nav-item dropdown">
            <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown"><span class="user-initials"><?= e(initials($pu['name'])) ?></span><span class="d-none d-md-inline ml-1"><?= e($pu['name']) ?></span></a>
            <div class="dropdown-menu dropdown-menu-right">
              <span class="dropdown-item-text small text-muted"><?= e($pu['email']) ?></span>
              <a href="/portal/account" class="dropdown-item<?= $nav === 'account' ? ' active' : '' ?>"><i class="fas fa-fw fa-user-gear mr-2"></i>Account &amp; security</a>
              <div class="dropdown-divider"></div>
              <form method="post" action="/portal/logout"><?= csrf_field() ?><button class="dropdown-item"><i class="fas fa-fw fa-right-from-bracket mr-2"></i>Sign out</button></form>
            </div>
          </li>
        </ul>
      <?php endif; ?>
    </div>
  </nav>

  <div class="content-wrapper">
    <div class="content pt-3 pb-4">
      <div class="container">
        <?php foreach (take_flashes() as $f): $t = ['success' => 'success', 'error' => 'danger', 'info' => 'info', 'warning' => 'warning'][$f['type']] ?? 'info'; ?>
          <div class="alert alert-<?= $t ?> alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">&times;</button>
            <?= e($f['message']) ?>
          </div>
        <?php endforeach; ?>
        <?= $content ?>
      </div>
    </div>
  </div>

  <footer class="main-footer text-sm">
    <div class="container d-flex flex-wrap justify-content-between">
      <span class="text-muted">Provided by <?= e(($provider['company'] ?? '') ?: \Align\Branding::name()) ?>
        <?php if (!empty($provider['phone'])): ?> · <?= e($provider['phone']) ?><?php endif; ?>
        <?php if (!empty($provider['email'])): ?> · <a href="mailto:<?= e($provider['email']) ?>"><?= e($provider['email']) ?></a><?php endif; ?>
      </span>
      <span class="text-muted">Only you and your IT provider can see this information. · <a href="/portal/terms" class="text-muted">Terms of use</a></span>
    </div>
  </footer>
</div>
</body>
</html>

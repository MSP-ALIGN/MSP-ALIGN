<?php
/** Client-facing layout for the onboarding page (no sign-in). Vars: $title, $company */
$company = $company ?? \Align\Controllers\WelcomeController::company();
$v = e(APP_VERSION);
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= e($title ?? 'Welcome') ?> | <?= e($company['name']) ?></title>
<link rel="icon" href="<?= e(\Align\Branding::logoUrl()) ?>">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= $v ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= $v ?>">
<?php if ($brandCss = \Align\Branding::css()): ?><style><?= $brandCss ?></style><?php endif; ?>
<script src="/vendor/bootstrap/bootstrap.bundle.min.js?v=<?= $v ?>" defer></script>
<script src="/assets/app.js?v=<?= $v ?>" defer></script>
<?php if (!empty($contractsJs)): ?><script src="/assets/pdfview.js?v=<?= $v ?>" defer></script><script src="/assets/contracts.js?v=<?= $v ?>" defer></script><?php endif; ?>
</head>
<body class="welcome-page" data-fmt="<?= e(json_encode(\Align\Fmt::forJs(), JSON_UNESCAPED_UNICODE)) ?>">
<header class="welcome-top">
  <div class="welcome-wrap d-flex align-items-center">
    <?php if (\Align\Branding::hasLogo()): ?><img src="<?= e(\Align\Branding::logoUrl()) ?>" alt="<?= e($company['name']) ?>" class="welcome-logo"><?php else: ?><b class="h5 mb-0"><?= e($company['name']) ?></b><?php endif; ?>
    <div class="ms-auto small text-end welcome-top-contact">
      <?php if ($company['phone']): ?><div><i class="fas fa-phone fa-fw me-1"></i><?= e($company['phone']) ?></div><?php endif; ?>
      <?php if ($company['email']): ?><div><i class="fas fa-envelope fa-fw me-1"></i><?= e($company['email']) ?></div><?php endif; ?>
    </div>
  </div>
</header>
<main class="welcome-wrap py-4">
  <?php foreach (take_flashes() as $f): $t = ['success' => 'success', 'error' => 'danger', 'info' => 'info', 'warning' => 'warning'][$f['type']] ?? 'info'; ?>
    <div class="alert alert-<?= $t ?> alert-dismissible fade show" role="alert"><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="welcome-wrap pb-4 small text-muted d-flex flex-wrap">
  <span class="me-auto"><?= e($company['name']) ?><?= $company['website'] ? ' · ' . e(preg_replace('#^https?://#', '', $company['website'])) : '' ?></span>
  <a href="/portal/terms" class="text-muted">Terms of use</a>
</footer>
</body>
</html>

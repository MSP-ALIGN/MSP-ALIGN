<?php
/**
 * Layout for the public pages (terms and licenses when not signed in). Vars: $title, $content.
 * Security: no sign-in, so nothing here may show client data; the app name and title are escaped.
 */
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '') ?> | <?= e(\Align\Branding::name()) ?></title>
<link rel="icon" href="<?= e(\Align\Branding::lightLogoUrl()) ?>">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
<?php if ($brandCss = \Align\Branding::css()): ?><style><?= $brandCss ?></style><?php endif; ?>
</head>
<body class="bg-light small">
<div class="container py-4" style="max-width:920px">
  <a href="<?= defined('IS_PORTAL') && IS_PORTAL ? '/portal/login' : '/login' ?>" class="d-inline-flex align-items-center mb-3 text-reset text-decoration-none">
    <img src="<?= e(\Align\Branding::lightLogoUrl()) ?>" alt="" style="height:32px" class="me-2"><b><?= e(\Align\Branding::name()) ?></b>
  </a>
  <?= $content ?>
  <p class="small text-muted mt-3"><a href="<?= defined('IS_PORTAL') && IS_PORTAL ? '/portal/login' : '/login' ?>">Back to sign in</a></p>
</div>
</body>
</html>

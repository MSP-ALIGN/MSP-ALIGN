<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '') ?> | <?= e(\Align\Branding::name()) ?></title>
<link rel="icon" href="<?= e(\Align\Branding::logoUrl()) ?>">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
<?php if ($brandCss = \Align\Branding::css()): ?><style><?= $brandCss ?></style><?php endif; ?>
</head>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
    <img src="<?= e(\Align\Branding::logoUrl()) ?>" alt="<?= e(\Align\Branding::name()) ?>" class="login-logo-img mb-2 d-block mx-auto<?= \Align\Branding::hasLogo() ? ' is-custom' : '' ?>">
    <?php if (!\Align\Branding::logoOnly()): ?><b><?= e(\Align\Branding::name()) ?></b><?php endif; ?>
  </div>
  <div class="card card-outline card-primary">
    <div class="card-body login-card-body">
      <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert-<?= $f['type'] === 'error' ? 'danger' : 'success' ?> py-2"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
</body>
</html>

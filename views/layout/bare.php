<?php
/**
 * The sign-in layout (staff and portal sign-in, 2FA, password set-up): logo, flash messages and $content.
 * Vars: $title, $content. Security: the app name, title and messages are escaped; flash types are fixed words from
 * code. The background URL is built by Branding from fixed parts (kind, hex, version) and the dim is an integer,
 * so neither can break out of the CSS (e() would not be enough inside <style>). No user data is shown here.
 */
?><!doctype html>
<html lang="en" data-bs-theme="light" data-theme-pref="<?= defined('IS_PORTAL') && IS_PORTAL ? 'light' : 'auto' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="/assets/theme.js?v=<?= e(APP_VERSION) ?>"></script>
<title><?= e($title ?? '') ?> | <?= e(\Align\Branding::name()) ?></title>
<link rel="icon" href="<?= e(\Align\Branding::faviconUrl()) ?>">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
<?php if ($brandCss = \Align\Branding::css()): ?><style><?= $brandCss ?></style><?php endif; ?>
<?php $bgKind = defined('IS_PORTAL') && IS_PORTAL ? 'portal' : 'staff'; $bgUrl = \Align\Branding::backgroundUrl($bgKind); ?>
<?php if ($bgUrl): ?><style>body.login-page.has-login-bg { --login-bg: url("<?= e($bgUrl) ?>"); --login-dim: <?= \Align\Branding::backgroundDim($bgKind) / 100 ?>; }</style><?php endif; ?>
</head>
<body class="login-page app-bare<?= $bgUrl ? ' has-login-bg' : '' ?>">
<?php if (\Align\Staging::on()): ?><div style="position:fixed;top:0;left:0;right:0;background:#ffc107;color:#000;text-align:center;font:bold 13px sans-serif;padding:6px;z-index:9999">Test server: a copy of <?= e(APP_NAME) ?>. Changes here don&#039;t reach real clients or tools.</div><?php endif; ?>
<div class="login-box">
  <div class="login-logo">
    <?php if (\Align\Branding::builtInWordmark()): // 2.2.2: the MSP Align logo, light or dark to match the page ?>
      <img src="/assets/logo.png?v=<?= e(APP_VERSION) ?>" alt="<?= e(\Align\Branding::name()) ?>" class="login-wordmark is-light d-block mx-auto" width="1032" height="277">
      <img src="/assets/logo-dark.png?v=<?= e(APP_VERSION) ?>" alt="<?= e(\Align\Branding::name()) ?>" class="login-wordmark is-dark mx-auto" width="1032" height="277">
    <?php else: ?>
      <?php // 2.2.4: the light mode logo on a light page, the dark mode logo in dark mode (the portal's sign-in is always light)
      $lightLogo = \Align\Branding::lightLogoUrl();
      $darkLogo = defined('IS_PORTAL') && IS_PORTAL ? $lightLogo : \Align\Branding::darkLogoUrl();
      $custom = \Align\Branding::anyLogo() ? ' is-custom' : ''; ?>
      <img src="<?= e($lightLogo) ?>" alt="<?= e(\Align\Branding::name()) ?>" class="login-logo-img mb-2 d-block mx-auto<?= $custom ?><?= $darkLogo !== $lightLogo ? ' on-light' : '' ?>">
      <?php if ($darkLogo !== $lightLogo): ?><img src="<?= e($darkLogo) ?>" alt="<?= e(\Align\Branding::name()) ?>" class="login-logo-img mb-2 mx-auto<?= $custom ?> on-dark"><?php endif; ?>
      <?php if (!\Align\Branding::logoOnly()): ?><b><?= e(\Align\Branding::name()) ?></b><?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="card card-outline card-primary">
    <div class="card-body login-card-body">
      <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert-<?= $f['type'] === 'error' ? 'danger' : e($f['type']) ?> py-2"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
  <p class="text-center small mt-2"><?php if (defined('IS_PORTAL') && IS_PORTAL): ?><a href="/portal/terms" class="text-muted">Terms of use</a><?php else: ?><a href="/terms" class="text-muted">Terms of use</a><?php endif; ?> · <a href="/license" class="text-muted">License</a> · <a href="<?= e(\Align\Controllers\LegalController::sourceUrl()) ?>" class="text-muted" target="_blank" rel="noopener">Source</a></p>
</div>
</body>
</html>

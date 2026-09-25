<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '') ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
</head>
<body class="bare">
<div class="auth-card">
  <div class="brand big"><img src="/assets/icon.svg" alt="" width="34" height="34"><span>Mountaineer<b>Align</b></span></div>
  <?php foreach (take_flashes() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</div>
</body>
</html>

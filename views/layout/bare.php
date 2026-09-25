<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '') ?> | <?= e(APP_NAME) ?></title>
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
    <img src="/assets/icon.svg" alt="" width="40" height="40" class="mb-2 d-block mx-auto">
    <b>Mountaineer</b> Align
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

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="15">
<title>Back in a few minutes | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="hold-transition login-page">
<div class="login-box" style="width:440px;max-width:94vw">
  <div class="card card-outline card-primary">
    <div class="card-body text-center py-4">
      <i class="fas fa-gear fa-spin fa-2x text-primary mb-3"></i>
      <h1 class="h5"><?= e(APP_NAME) ?> <?= e($message) ?></h1>
      <p class="text-muted mb-2">This usually takes a minute or two. This page refreshes on its own.</p>
      <?php if ($step !== ''): ?><p class="small mb-0"><span class="text-muted">Now:</span> <?= e($step) ?></p><?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>

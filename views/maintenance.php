<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="5">
<title>Please wait | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="hold-transition login-page">
<?php
/**
 * Shown to everyone while the agent updates or restores. @var string $message, $step, $action; int $percent, $elapsed
 * Security: public and rendered without the database, so it shows only the job's step (written by the agent,
 * escaped) and progress, nothing about clients or the server.
 */
$percent = max(1, min(99, (int) ($percent ?? 5)));
$elapsed = (int) ($elapsed ?? 0);
$took = $elapsed >= 60 ? intdiv($elapsed, 60) . ' min ' . ($elapsed % 60) . ' s' : $elapsed . ' s';
?>
<div class="login-box" style="width:480px;max-width:94vw">
  <div class="card card-outline card-primary">
    <div class="card-body py-4">
      <div class="text-center">
        <i class="fas fa-gear fa-spin fa-2x text-primary mb-3"></i>
        <h1 class="h5 mb-1"><?= e(APP_NAME) ?> <?= e($message) ?></h1>
        <p class="text-muted mb-3">Please wait. This usually takes a few minutes.</p>
      </div>
      <div class="progress progress-update mb-1" role="progressbar" aria-label="Progress" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: <?= $percent ?>%"></div>
      </div>
      <div class="d-flex small text-muted mb-3"><span class="me-auto"><?= $step !== '' ? e($step) : 'Starting' ?>…</span><span><?= $percent ?>% · <?= e($took) ?></span></div>
      <p class="small mb-0 text-center"><i class="fas fa-rotate me-1 text-muted"></i>This page refreshes on its own and opens <?= e(APP_NAME) ?> as soon as it's ready. You don't need to do anything.</p>
    </div>
  </div>
</div>
</body>
</html>

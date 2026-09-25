<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Report') ?></title>
<link rel="icon" href="<?= e(\Align\Branding::logoUrl()) ?>">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/print.css?v=<?= e(APP_VERSION) ?>">
<?php if (!empty($docPrint)): ?><?php endif; ?>
<script src="/assets/print.js?v=<?= e(APP_VERSION) ?>" defer></script>
<?php if ($brandCss = \Align\Branding::css()): ?><style><?= $brandCss ?></style><?php endif; ?>
</head>
<body class="report">
<div class="report-toolbar no-print">
  <div class="container-report d-flex flex-wrap align-items-center">
    <a href="<?= defined('IS_PORTAL') && IS_PORTAL ? '/portal' : '/' ?>" class="btn btn-sm btn-light mr-2" data-back><i class="fas fa-arrow-left mr-1"></i>Back</a>
    <form class="form-inline small mr-auto" method="get">
      <?php foreach (['costs' => 'Costs', 'inventory' => 'Full inventory', 'virtual' => 'Virtual machines', 'details' => 'Line items', 'notes' => 'Notes'] as $k => $l): if (!array_key_exists($k, $opt ?? []) || in_array($k, $opt['_hide'] ?? [], true)) continue; ?>
        <input type="hidden" name="<?= $k ?>" value="0">
        <div class="custom-control custom-checkbox mr-3"><input type="checkbox" class="custom-control-input" id="opt-<?= $k ?>" name="<?= $k ?>" value="1" <?= $opt[$k] ? 'checked' : '' ?> data-autosubmit-check><label class="custom-control-label text-white" for="opt-<?= $k ?>"><?= $l ?></label></div>
      <?php endforeach; ?>
    </form>
    <button class="btn btn-sm btn-primary" data-print><i class="fas fa-print mr-1"></i>Print / Save as PDF</button>
  </div>
</div>
<div class="container-report report-page">
  <header class="report-header">
    <div class="d-flex align-items-start">
      <?php if (\Align\Branding::hasLogo()): ?><img src="<?= e(\Align\Branding::logoUrl()) ?>" alt="" class="report-logo mr-3"><?php endif; ?>
      <div class="mr-auto">
        <div class="report-kicker"><?= e($brand['company']) ?></div>
        <h1 class="report-title"><?= e($reportTitle ?? $title) ?></h1>
        <?php if (!empty($reportSubtitle)): ?><div class="report-sub"><?= e($reportSubtitle) ?></div><?php endif; ?>
      </div>
      <?php if (!empty($client['id']) && ($clientLogo = client_logo_url($client))): ?><img src="<?= e($clientLogo) ?>" alt="<?= e($client['name']) ?>" class="report-client-logo ml-3"><?php endif; ?>
      <div class="text-right small report-meta">
        <div><b>Prepared</b> <?= e(date('F j, Y')) ?></div>
        <?php if ($brand['preparedBy']): ?><div><b>By</b> <?= e($brand['preparedBy']) ?></div><?php endif; ?>
        <?php if ($brand['phone']): ?><div><?= e($brand['phone']) ?></div><?php endif; ?>
        <?php if ($brand['email']): ?><div><?= e($brand['email']) ?></div><?php endif; ?>
        <?php if ($brand['website']): ?><div><?= e($brand['website']) ?></div><?php endif; ?>
      </div>
    </div>
  </header>
  <?= $content ?>
  <footer class="report-footer">
    <span><?= e($brand['footer'] ?? '') ?></span>
    <span class="text-nowrap"><?= e($brand['company']) ?> · <?= e(date('Y-m-d')) ?></span>
  </footer>
</div>
</body>
</html>

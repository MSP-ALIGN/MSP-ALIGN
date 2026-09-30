<?php
/**
 * Printable report layout. Vars: $title, $reportTitle, ?$reportSubtitle, ?$client, $brand, ?$opt,
 * ?$optLabels (extra toolbar checkboxes), ?$noMasthead (QBR pack draws its own cover), ?$docPrint.
 */
$opt = $opt ?? [];
$client = $client ?? null;
$portal = defined('IS_PORTAL') && IS_PORTAL;
$labels = ($optLabels ?? []) + ['costs' => 'Costs', 'inventory' => 'Full inventory', 'users' => 'Last user', 'virtual' => 'Virtual machines', 'details' => 'Line items', 'notes' => 'Notes'];
$brandColor = \Align\Branding::color();
$clientLogo = !empty($client['id']) ? client_logo_url($client) : null;
$footLeft = trim(($brand['company'] ?? '') . ($client ? ' · ' . $client['name'] : '') . ' · ' . ($reportTitle ?? $title ?? ''), ' ·');
$cssStr = fn(string $s) => '"' . str_replace(['\\', '"', "\n", '<'], ['\\\\', '\\"', ' ', '\\3c '], $s) . '"';
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= \Align\Staging::on() ? '[TEST] ' : '' ?><?= e($title ?? 'Report') ?></title>
<link rel="icon" href="<?= e(\Align\Branding::logoUrl()) ?>">
<link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/print.css?v=<?= e(APP_VERSION) ?>">
<script src="/assets/print.js?v=<?= e(APP_VERSION) ?>" defer></script>
<style>
:root { --brand: <?= e($brandColor) ?>; }
@page {
  @bottom-left { content: <?= $cssStr($footLeft) ?>; font-size: 8pt; color: #7b8594; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  @bottom-right { content: "Page " counter(page) " of " counter(pages); font-size: 8pt; color: #7b8594; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
}
<?php if (!empty($noMasthead)): ?>@page :first { @bottom-left { content: none; } @bottom-right { content: none; } }<?php endif; ?>
</style>
</head>
<body class="report">
<?php if (\Align\Staging::on()): ?><div style="background:#ffc107;color:#000;text-align:center;font:bold 12px sans-serif;padding:4px">TEST SERVER — not for clients</div><?php endif; ?>
<div class="report-toolbar no-print">
  <div class="tb-inner">
    <a href="<?= $portal ? '/portal' : '/' ?>" class="btn btn-sm btn-light" data-back><i class="fas fa-arrow-left me-1"></i>Back</a>
    <form class="tb-opts" method="get">
      <?php foreach ($_GET as $k => $v): if (!is_string($v) || array_key_exists($k, $labels) || ($k === 'period' && !empty($periodChoices))) continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <?php foreach ($labels as $k => $l): if (!array_key_exists($k, $opt) || in_array($k, $opt['_hide'] ?? [], true)) continue; ?>
        <input type="hidden" name="<?= e($k) ?>" value="0">
        <div class="form-check "><input type="checkbox" class="form-check-input" id="opt-<?= e($k) ?>" name="<?= e($k) ?>" value="1" <?= $opt[$k] ? 'checked' : '' ?> data-autosubmit-check><label class="form-check-label" for="opt-<?= e($k) ?>"><?= e($l) ?></label></div>
      <?php endforeach; ?>
      <?php if (!empty($periodChoices)): ?>
        <select name="period" class="form-select form-select-sm tb-period" aria-label="Period" data-autosubmit-select>
          <?php foreach ($periodChoices as $k => $l): ?><option value="<?= e($k) ?>" <?= (string) ($period ?? '') === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
    </form>
    <button class="btn btn-sm btn-primary" data-print><i class="fas fa-file-pdf me-1"></i>Print / Save as PDF</button>
  </div>
</div>
<div class="container-report report-page">
  <?php if (empty($noMasthead)): ?>
  <header class="masthead">
    <?php if (\Align\Branding::hasLogo()): ?><img src="<?= e(\Align\Branding::logoUrl()) ?>" alt="<?= e($brand['company']) ?>" class="provider-logo"><?php endif; ?>
    <div class="titles">
      <div class="kicker"><?= e($brand['company']) ?><?= $client ? ' · ' . e($client['name']) : '' ?></div>
      <h1><?= e($reportTitle ?? $title) ?></h1>
      <?php if (!empty($reportSubtitle)): ?><div class="sub"><?= e($reportSubtitle) ?></div><?php endif; ?>
    </div>
    <?php if ($clientLogo): ?><img src="<?= e($clientLogo) ?>" alt="<?= e($client['name']) ?>" class="client-logo"><?php endif; ?>
    <div class="meta">
      <div><b>Prepared</b> <?= e(\Align\Fmt::date(time(), 'long')) ?></div>
      <?php if (!empty($brand['preparedBy'])): ?><div><b>By</b> <?= e($brand['preparedBy']) ?></div><?php endif; ?>
      <?php if (!empty($brand['phone'])): ?><div><?= e($brand['phone']) ?></div><?php endif; ?>
      <?php if (!empty($brand['email'])): ?><div><?= e($brand['email']) ?></div><?php endif; ?>
    </div>
  </header>
  <div class="brand-rule"></div>
  <?php endif; ?>
  <?= $content ?>
  <footer class="report-footer">
    <span><?= e($brand['footer'] ?? '') ?: 'Confidential. Prepared for ' . e($client['name'] ?? 'internal use') . '.' ?></span>
    <span class="nowrap"><?= e($brand['company']) ?> · <?= e(date('Y-m-d')) ?></span>
  </footer>
</div>
</body>
</html>

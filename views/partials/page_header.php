<?php
/**
 * The page header every page shares (1.42): icon, title and count, an optional line of description, then on
 * the right one primary action, up to two secondary ones and a "more" menu.
 * @var string $icon; string $title; ?int|string $count; ?string $desc (HTML); ?string $primary (HTML, a button or link);
 *      string[] $secondary (HTML); string[] $more (HTML dropdown items); ?string $help (a /help anchor)
 */
$count = $count ?? null;
$secondary = $secondary ?? [];
$more = $more ?? [];
?>
<div class="page-head d-flex flex-wrap align-items-start mb-3">
  <div class="me-auto pe-3">
    <h1 class="h4 mb-0"><i class="fas fa-fw <?= e($icon) ?> text-secondary me-1"></i><?= e($title) ?><?php if ($count !== null && $count !== ''): ?> <span class="badge text-bg-secondary align-middle page-count"><?= e(is_int($count) ? num($count) : (string) $count) ?></span><?php endif; ?></h1>
    <?php if (!empty($desc)): ?><div class="text-muted small mt-1 page-desc"><?= $desc ?></div><?php endif; ?>
  </div>
  <div class="page-actions d-flex flex-wrap align-items-center mt-1">
    <?php foreach ($secondary as $b) echo $b; ?>
    <?= $primary ?? '' ?>
    <?php if (!empty($help)): ?><a class="btn btn-sm btn-default ms-1" href="/help#<?= e($help) ?>" title="How it works" aria-label="How it works"><i class="fas fa-circle-question"></i></a><?php endif; ?>
    <?php if ($more): ?>
      <div class="btn-group ms-1">
        <button class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown" aria-label="More actions"><i class="fas fa-ellipsis"></i></button>
        <div class="dropdown-menu dropdown-menu-end"><?php foreach ($more as $m) echo $m; ?></div>
      </div>
    <?php endif; ?>
  </div>
</div>

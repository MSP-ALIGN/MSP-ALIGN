<?php
/** "Showing 1–100 of 1,435 · Show 100 more" under a paged list (1.42). @var int $shown; int $total; string $moreUrl */
if ($total <= 0) {
    return;
}
?>
<div class="card-footer list-footer d-flex align-items-center small py-2">
  <span class="text-muted mr-auto">Showing <?= $shown < $total ? '1–' . num($shown) . ' of ' . num($total) : 'all ' . num($total) ?></span>
  <?php if ($shown < $total): ?><a class="btn btn-sm btn-default" href="<?= e($moreUrl) ?>" rel="nofollow">Show <?= num(min(\Align\Paging::STEP, $total - $shown)) ?> more</a><?php endif; ?>
</div>

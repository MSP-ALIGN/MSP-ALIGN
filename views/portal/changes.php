<?php
/**
 * 2.4.0 client portal: "Since your last review", what changed since the newest completed business review. Only the
 * parts this user may see are in $ch (PortalController::changes); money only with $costs.
 * @var ?array $base, $ch; bool $costs
 */
use Align\View;
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0 me-auto">Since your last review</h1>
  <?php if ($base): ?><a class="btn btn-sm btn-default" href="/portal/report/qbr" target="_blank" rel="noopener"><i class="fas fa-book-open me-1"></i>Business review pack</a><?php endif; ?>
</div>
<?php if (!$ch): ?>
  <div class="card card-body text-center py-5 text-muted">Once we've held a business review together, this page shows what has changed since: work finished, devices replaced, and what has come due.</div>
<?php else: ?>
  <p class="text-muted small">Since <b><?= e($base['label']) ?></b>, <?= (int) $base['days'] ?> days ago.</p>
  <?= View::fetch('changes/_body', ['ch' => $ch, 'costs' => $costs, 'staff' => false, 'cid' => 0]) ?>
<?php endif; ?>

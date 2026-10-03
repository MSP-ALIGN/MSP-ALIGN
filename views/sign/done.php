<?php
/**
 * After signing (or declining). @var array $c, $company; string $token, $doc; bool $pdfReady; int $daysLeft
 * Shown only after the emailed code (SignController::show). $doc is Render::html output (already escaped) and is
 * empty for a declined contract; everything else is escaped here. $token passed byToken()'s format check.
 */
$st = $c['status'];
?>
<div class="card sign-card mx-auto mb-4">
  <div class="card-body p-4 text-center">
    <?php if ($st === 'declined'): ?>
      <i class="fas fa-circle-xmark fa-3x text-secondary mb-3"></i>
      <h1 class="h4">You declined this contract</h1>
      <p class="text-muted mb-0"><?= e($company['name']) ?> has been told. If that was a mistake, please contact them<?= $company['phone'] ? ' at ' . e($company['phone']) : '' ?>.</p>
    <?php elseif ($st === 'completed'): ?>
      <i class="fas fa-circle-check fa-3x text-success mb-3"></i>
      <h1 class="h4">Signed by everyone</h1>
      <?php if ($pdfReady): ?>
        <p class="text-muted">"<?= e($c['title']) ?>" with <?= e($company['name']) ?> is complete. You can download the signed copy here for the next <?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?><?= \Align\Mail\Mail::ready() ? ', and we\'ve emailed it to you' : '' ?>.</p>
        <a class="btn btn-primary" href="/portal/sign/<?= e($token) ?>/pdf"><i class="fas fa-download me-1"></i>Download the signed PDF</a>
      <?php else: ?>
        <p class="text-muted mb-0">"<?= e($c['title']) ?>" with <?= e($company['name']) ?> is complete. The signed copy is being made; please check back here in a little while.</p>
      <?php endif; ?>
    <?php else: ?>
      <i class="fas fa-circle-check fa-3x text-success mb-3"></i>
      <h1 class="h4">Thank you, you've signed</h1>
      <p class="text-muted mb-0"><?= e($company['name']) ?> will countersign next. When they do, we'll email you the signed copy.</p>
    <?php endif; ?>
  </div>
</div>
<?php if ($doc): ?><div class="ct-paper sign-paper mx-auto"><?= $doc ?></div><?php endif; ?>

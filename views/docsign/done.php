<?php
/**
 * 2.34.0 After signing or declining a document (DocSignController::show): where it stands, and the signed copy once
 * everyone has signed. Shown only after the code (or from the portal). @var array $r, $s, $company; string $token;
 * bool $pdfReady; int $total, $signed. Everything is escaped; $token passed byToken()'s format check.
 */
$st = $r['status'];
?>
<div class="card sign-card mx-auto mb-4">
  <div class="card-body p-4 text-center">
    <?php if ($st === 'declined'): ?>
      <i class="fas fa-circle-xmark fa-3x text-secondary mb-3"></i>
      <h1 class="h4"><?= $s['status'] === 'declined' ? 'You declined this document' : 'This document was declined' ?></h1>
      <p class="text-muted mb-0"><?= e($company['name']) ?> has been told. If that was a mistake, please contact them<?= $company['phone'] ? ' at ' . e($company['phone']) : '' ?>.</p>
    <?php elseif ($st === 'completed'): ?>
      <i class="fas fa-circle-check fa-3x text-success mb-3"></i>
      <h1 class="h4">Signed by everyone</h1>
      <?php if ($pdfReady): ?>
        <p class="text-muted">"<?= e($r['title']) ?>" is complete. You can download the signed copy here<?= \Align\Mail\Mail::ready() ? ', and we\'ve emailed it to you' : '' ?>.</p>
        <a class="btn btn-primary" href="/portal/docsign/<?= e($token) ?>/pdf"><i class="fas fa-download me-1"></i>Download the signed PDF</a>
      <?php else: ?>
        <p class="text-muted mb-0">"<?= e($r['title']) ?>" is complete. The signed copy is being made; please check back here in a little while.</p>
      <?php endif; ?>
    <?php else: ?>
      <i class="fas fa-circle-check fa-3x text-success mb-3"></i>
      <h1 class="h4">Thank you, you've signed</h1>
      <p class="text-muted mb-0"><?php if ($st === 'countersign'): ?><?= e($company['name']) ?> will countersign next.<?php else: ?><?= $signed ?> of <?= $total ?> people have signed so far.<?php endif; ?>
        When everyone has signed, we'll email you the signed copy.</p>
    <?php endif; ?>
  </div>
</div>

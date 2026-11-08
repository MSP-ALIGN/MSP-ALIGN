<?php
/**
 * 2.34.0 After acknowledging (DocAckController::show): that it's recorded, with the name and time. Shown for
 * Acknowledging::DONE_DAYS after. @var array $r, $s, $company, $rec (the acknowledgment record); string $token
 * Everything is escaped.
 */
?>
<div class="card sign-card mx-auto mb-4">
  <div class="card-body p-4 text-center">
    <i class="fas fa-circle-check fa-3x text-success mb-3"></i>
    <h1 class="h4">Thank you, it's acknowledged</h1>
    <p class="text-muted mb-0">You acknowledged "<?= e($r['title']) ?>"<?= !empty($rec['name']) ? ' as ' . e($rec['name']) : '' ?> on <?= e(fmt_datetime($s['signed_at'])) ?>.
      <?= e($company['name']) ?> has the record. You can close this page.</p>
  </div>
</div>

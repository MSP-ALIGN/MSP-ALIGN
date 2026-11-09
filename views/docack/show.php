<?php
/**
 * 2.34.0 The document to read and acknowledge (DocAckController::show): the PDF that was sent (painted by PDF.js, no
 * scripts), then one box, "I have read and agree to this policy", with their name (pre-filled, editable).
 * @var array $r, $s, $company; string $token, $name (the name to show: theirs, or what they typed before an error);
 *   bool $viaPortal; int $size (the PDF's size)
 * Security: every value is escaped (here and in the partial). The server checks everything again
 * (DocAckController::acknowledge, Acknowledging::acknowledge). $token passed byToken()'s format check.
 */
?>
<div class="sign-intro mx-auto mb-3">
  <div class="text-muted small"><?= e($company['name']) ?> asks <?= e((string) $s['name']) ?> at <?= e((string) $r['client_name']) ?> to read this document</div>
  <h1 class="h4 mb-1"><?= e($r['title']) ?></h1>
  <div class="small text-muted">Read it through, then confirm at the bottom.<?= $viaPortal ? ' You opened it from the client portal.' : '' ?></div>
</div>
<div class="ct-paper sign-paper mx-auto">
  <?= \Align\View::fetch('documents/_file_body', ['src' => '/portal/docack/' . $token . '/file', 'f' => ['file_orig' => \Align\Docs\Signing::fileName((string) $r['title']), 'file_type' => 'pdf', 'file_size' => $size]]) ?>
</div>
<form method="post" action="/portal/docack/<?= e($token) ?>" id="docack-form" class="card sign-card sign-form mx-auto mt-4">
  <?= csrf_field() ?>
  <div class="card-body p-4">
    <h2 class="h5 mb-3"><i class="fas fa-user-check me-2"></i>Acknowledge</h2>
    <div class="mb-3" style="max-width:360px">
      <label for="ack-name" class="form-label">Your name</label>
      <input id="ack-name" name="name" class="form-control" value="<?= e($name) ?>" maxlength="190" required autocomplete="name">
    </div>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="agree" value="1" id="ack-agree" required>
      <label class="form-check-label" for="ack-agree"><b>I have read and agree to this policy</b></label>
    </div>
    <div class="small text-muted mt-2">We record your name, <?= e((string) $s['email']) ?>, the time, your IP address and browser, and which version you read.</div>
  </div>
  <div class="card-footer d-flex flex-wrap align-items-center gap-2 p-3">
    <span class="small me-auto text-muted">Questions? Contact <?= e($company['name']) ?><?= $company['phone'] ? ' at ' . e($company['phone']) : '' ?>.</span>
    <button class="btn btn-primary btn-lg"><i class="fas fa-check me-2"></i>Acknowledge</button>
  </div>
</form>

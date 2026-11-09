<?php
/**
 * 2.34.0 Confirm it's the signer of a document: a code emailed to them (DocSignController::show).
 * @var array $r, $s, $company; string $token, $masked; bool $codeSent, $mailReady; int $triesLeft
 * Shown to anyone with the link, so it shows only the title, your company and the masked address, never the
 * document. The tries left are a hint: Signing::checkCode counts them on the server. Everything is escaped.
 */
?>
<div class="card sign-card mx-auto">
  <div class="card-body p-4">
    <div class="text-muted small mb-1"><?= e($company['name']) ?> sent you a document to sign</div>
    <h1 class="h4 mb-3"><?= e($r['title']) ?></h1>
    <p>To keep it private, we'll email a 6-digit code to <b><?= e($masked) ?></b>. Enter it here to open the document.</p>
    <?php if (!$mailReady): ?><div class="alert alert-warning">We can't send codes right now. Please contact <?= e($company['name']) ?><?= $company['phone'] ? ' at ' . e($company['phone']) : '' ?>.</div><?php endif; ?>
    <?php if ($codeSent && $triesLeft > 0): ?>
      <form method="post" action="/portal/docsign/<?= e($token) ?>/verify" class="mb-3">
        <?= csrf_field() ?>
        <label for="code" class="form-label">Code from the email</label>
        <div class="d-flex gap-2" style="max-width:320px">
          <input id="code" name="code" class="form-control form-control-lg text-center sign-code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
          <button class="btn btn-primary">Open</button>
        </div>
      </form>
      <form method="post" action="/portal/docsign/<?= e($token) ?>/code"><?= csrf_field() ?><button class="btn btn-link p-0 small">Send a new code</button></form>
    <?php elseif ($mailReady): ?>
      <form method="post" action="/portal/docsign/<?= e($token) ?>/code"><?= csrf_field() ?><button class="btn btn-primary btn-lg"><i class="fas fa-envelope me-2"></i>Email me <?= $codeSent ? 'a new' : 'a' ?> code</button></form>
    <?php endif; ?>
    <p class="small text-muted mt-4 mb-0">Not you, or didn't expect this? Contact <?= e(rtrim($company['name'] . ($company['phone'] ? ' at ' . $company['phone'] : ''), '.')) ?>.</p>
  </div>
</div>

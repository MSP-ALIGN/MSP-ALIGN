<?php
/**
 * 2.34.0 The document to read and sign (DocSignController::show): the PDF that was sent (painted by PDF.js, no
 * scripts), then the signature pad (contracts/_sigpad: typed or drawn, name, title, consent) and Decline.
 * @var array $r, $s, $company, $kept; string $token; bool $viaPortal; int $others
 * Security: every value is escaped (here and in the partials); $kept is what the signer typed before an error. The
 * server checks everything again (DocSignController::sign, Signing::sign). $token passed byToken()'s format check.
 */
?>
<div class="sign-intro mx-auto mb-3">
  <div class="text-muted small"><?= e($company['name']) ?> sent this document to <?= e((string) $s['name']) ?> at <?= e((string) $r['client_name']) ?><?= $others ? ' (and ' . $others . ' other' . ($others === 1 ? '' : 's') . ')' : '' ?></div>
  <h1 class="h4 mb-1"><?= e($r['title']) ?></h1>
  <div class="small text-muted">Read it through, then sign at the bottom.<?= $viaPortal ? ' You opened it from the client portal.' : '' ?></div>
</div>
<div class="ct-paper sign-paper mx-auto">
  <?= \Align\View::fetch('documents/_file_body', ['src' => '/portal/docsign/' . $token . '/file', 'f' => ['file_orig' => \Align\Docs\Signing::fileName((string) $r['title']), 'file_type' => 'pdf',
      'file_size' => (int) (($p = \Align\Docs\Files::path($r['source_file'])) ? filesize($p) : 0)]]) ?>
</div>

<form method="post" action="/portal/docsign/<?= e($token) ?>" id="docsign-form" class="card sign-card sign-form mx-auto mt-4">
  <?= csrf_field() ?>
  <div class="card-body p-4" id="sign">
    <h2 class="h5 mb-3"><i class="fas fa-signature me-2"></i>Sign</h2>
    <?= \Align\View::fetch('contracts/_sigpad', ['p' => 'ds', 'sigName' => (string) ($kept['sig_name'] ?? $s['name']), 'sigTitle' => (string) ($kept['sig_title'] ?? $s['title']),
        'sigTyped' => (string) ($kept['sig_typed'] ?? ''), 'company' => $company['name'], 'what' => 'document']) ?>
  </div>
  <div class="card-footer d-flex flex-wrap align-items-center gap-2 p-3">
    <span class="small me-auto text-muted">You'll get the signed copy by email when everyone has signed.</span>
    <button type="button" class="btn btn-link btn-sm text-muted" data-bs-toggle="collapse" data-bs-target="#decline-box">Decline</button>
    <button class="btn btn-primary btn-lg"><i class="fas fa-pen-nib me-2"></i>Sign the document</button>
  </div>
</form>
<div class="collapse" id="decline-box">
  <form method="post" action="/portal/docsign/<?= e($token) ?>/decline" class="card sign-card mx-auto mt-3">
    <?= csrf_field() ?>
    <div class="card-body p-4">
      <h2 class="h6">Decline this document</h2>
      <label for="dc-reason" class="small">Tell <?= e($company['name']) ?> why (optional)</label>
      <textarea id="dc-reason" name="reason" class="form-control mb-2" rows="2" maxlength="1000"></textarea>
      <button class="btn btn-outline-danger btn-sm" data-confirm="Decline this document? Nobody will be able to sign it from their link afterwards." data-confirm-ok="Decline">Decline</button>
    </div>
  </form>
</div>

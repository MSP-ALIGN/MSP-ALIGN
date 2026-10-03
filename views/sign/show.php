<?php
use Align\Contracts\Contracts;

/**
 * The contract to read, fill in and sign. Inputs in the document belong to #sign-form (form="sign-form").
 * @var array $c, $company, $clientFields, $kept; string $token, $doc; bool $initials
 */
?>
<div class="sign-intro mx-auto mb-3">
  <div class="text-muted small"><?= e($company['name']) ?> sent this contract to <?= e((string) $c['signer_name']) ?> at <?= e(Contracts::party($c)) ?></div>
  <h1 class="h4 mb-1"><?= e($c['title']) ?></h1>
  <div class="small text-muted">Read it through<?= $clientFields ? ', fill in the highlighted boxes' : '' ?>, then sign at the bottom.</div>
</div>
<div class="ct-paper sign-paper mx-auto"><?= $doc ?></div>

<form method="post" action="/portal/sign/<?= e($token) ?>" id="sign-form" class="card sign-card sign-form mx-auto mt-4">
  <?= csrf_field() ?>
  <div class="card-body p-4" id="sign">
    <h2 class="h5 mb-3"><i class="fas fa-signature me-2"></i>Sign</h2>
    <?= \Align\View::fetch('contracts/_sigpad', ['p' => 'sg', 'sigName' => (string) ($kept['sig_name'] ?? $c['signer_name']), 'sigTitle' => (string) ($kept['sig_title'] ?? $c['signer_title']),
        'sigTyped' => (string) ($kept['sig_typed'] ?? ''), 'company' => $company['name']]) ?>
    <?php if ($initials): ?>
      <div class="mt-3" style="max-width:220px"><label for="sg-initials">Your initials</label>
        <input id="sg-initials" name="initials" class="form-control" maxlength="6" value="<?= e((string) ($kept['initials'] ?? '')) ?>" data-initials-main>
        <div class="form-text">Shown <?= $c['def']['style']['initials_footer'] ? 'at the bottom of each page' : 'where the contract asks for them' ?>.</div></div>
    <?php endif; ?>
  </div>
  <div class="card-footer d-flex flex-wrap align-items-center gap-2 p-3">
    <span class="small me-auto" data-cf-left></span>
    <button type="button" class="btn btn-link btn-sm text-muted" data-bs-toggle="collapse" data-bs-target="#decline-box">Decline</button>
    <button class="btn btn-primary btn-lg"><i class="fas fa-pen-nib me-2"></i>Sign the contract</button>
  </div>
</form>
<div class="collapse" id="decline-box">
  <form method="post" action="/portal/sign/<?= e($token) ?>/decline" class="card sign-card mx-auto mt-3">
    <?= csrf_field() ?>
    <div class="card-body p-4">
      <h2 class="h6">Decline this contract</h2>
      <label for="dc-reason" class="small">Tell <?= e($company['name']) ?> why (optional)</label>
      <textarea id="dc-reason" name="reason" class="form-control mb-2" rows="2" maxlength="1000"></textarea>
      <button class="btn btn-outline-danger btn-sm" data-confirm="Decline this contract? You won't be able to sign it from this link afterwards." data-confirm-ok="Decline">Decline</button>
    </div>
  </form>
</div>
<?php if ($clientFields): ?>
  <div class="sign-dock"><button type="button" class="btn btn-sm btn-light shadow-sm" data-cf-next><i class="fas fa-arrow-down me-1"></i>Next box to fill in</button></div>
<?php endif; ?>

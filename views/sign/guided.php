<?php
use Align\Contracts\Contracts;

/**
 * Signing a contract on the MSP's own PDF, step by step (like DocuSign or DocuSeal): Start, then Next takes the
 * signer to each box to fill in, initial or sign, and Finish asks for the consent and signs. The boxes on the page
 * are the form; contracts.js (guide) runs the steps and the adopt-signature and adopt-initials dialogs.
 * @var array $c, $company, $kept; string $token, $doc
 */
$kept = $kept ?? [];
?>
<div class="sign-intro mx-auto mb-2">
  <div class="text-muted small"><?= e($company['name']) ?> sent this contract to <?= e((string) $c['signer_name']) ?> at <?= e(Contracts::party($c)) ?></div>
  <h1 class="h4 mb-1"><?= e($c['title']) ?></h1>
  <div class="small text-muted">Read it through. Press <b>Start</b>, and <b>Next</b> takes you to each place to fill in, initial or sign.</div>
</div>
<div class="sign-guide" data-guide>
  <div class="sign-guide-inner mx-auto d-flex flex-wrap align-items-center gap-2">
    <div class="small me-auto" data-guide-status aria-live="polite"></div>
    <button type="button" class="btn btn-link btn-sm text-muted" data-bs-toggle="modal" data-bs-target="#decline-modal">Decline</button>
    <button type="button" class="btn btn-warning fw-semibold px-4" data-guide-next><i class="fas fa-play me-1" aria-hidden="true"></i><span>Start</span></button>
  </div>
</div>
<div class="sign-pdf mx-auto"><?= $doc ?></div>

<form method="post" action="/portal/sign/<?= e($token) ?>" id="sign-form" data-guided
      data-kept-name="<?= e((string) ($kept['sig_name'] ?? '')) ?>" data-kept-title="<?= e((string) ($kept['sig_title'] ?? '')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="sig_name" data-out="sig_name">
  <input type="hidden" name="sig_title" data-out="sig_title">
  <input type="hidden" name="sig_kind" value="type" data-out="sig_kind">
  <input type="hidden" name="sig_typed" data-out="sig_typed">
  <input type="hidden" name="sig_png" data-out="sig_png">
  <input type="hidden" name="initials" data-out="initials">
  <div data-out="initialed"></div>
</form>

<div class="modal fade" id="adopt-sig" tabindex="-1" aria-labelledby="adopt-sig-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="adopt-sig-title">Your signature</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div class="mb-3" style="max-width:420px"><label for="ad-name">Full name</label><input id="ad-name" class="form-control" maxlength="120" autocomplete="name" data-ad="name"></div>
      <div class="d-flex align-items-center mb-1">
        <span class="me-auto">Signature</span>
        <div class="btn-group btn-group-sm sig-tabs" role="group" aria-label="How to sign">
          <button type="button" class="btn btn-outline-secondary active" data-ad-tab="type" aria-pressed="true"><i class="fas fa-keyboard me-1"></i>Type</button>
          <button type="button" class="btn btn-outline-secondary" data-ad-tab="draw" aria-pressed="false"><i class="fas fa-signature me-1"></i>Draw</button>
        </div>
      </div>
      <div class="sig-area">
        <div class="sig-pane" data-ad-pane="type"><input class="form-control sig-typed" maxlength="120" placeholder="Type your signature" aria-label="Type your signature" data-ad="typed"></div>
        <div class="sig-pane d-none" data-ad-pane="draw">
          <canvas class="sig-canvas" width="900" height="240" aria-label="Draw your signature with the mouse or your finger" role="img" data-ad="canvas"></canvas>
          <button type="button" class="btn btn-xs btn-light sig-clear" data-ad="clear"><i class="fas fa-eraser me-1"></i>Clear</button>
        </div>
      </div>
      <div class="text-danger small mt-2" data-ad="error"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" data-ad="adopt">Adopt and sign</button></div>
  </div></div>
</div>

<div class="modal fade" id="adopt-ini" tabindex="-1" aria-labelledby="adopt-ini-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="adopt-ini-title">Your initials</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <label for="ai-initials">Initials</label>
      <input id="ai-initials" class="form-control sig-typed sig-initials" maxlength="6" data-ai="initials">
      <div class="form-text">Then click each <b>Initial</b> box to add them there.</div>
      <div class="text-danger small mt-2" data-ai="error"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" data-ai="adopt">Adopt and initial</button></div>
  </div></div>
</div>

<div class="modal fade" id="sign-finish" tabindex="-1" aria-labelledby="sign-finish-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="sign-finish-title">Finish signing</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <p class="small mb-2">Everything is filled in. Check the contract once more, then sign it.</p>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="consent" value="1" id="sg-consent" form="sign-form" required>
        <label class="form-check-label small" for="sg-consent">I agree to do business electronically with <?= e($company['name']) ?> and to sign this contract electronically.
          My electronic signature is as binding as my handwritten signature.</label>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Back to the contract</button><button class="btn btn-primary" form="sign-form"><i class="fas fa-pen-nib me-1"></i>Sign the contract</button></div>
  </div></div>
</div>

<div class="modal fade" id="decline-modal" tabindex="-1" aria-labelledby="decline-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="post" action="/portal/sign/<?= e($token) ?>/decline">
      <?= csrf_field() ?>
      <div class="modal-header"><h5 class="modal-title" id="decline-title">Decline this contract</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <label for="dc-reason" class="small">Tell <?= e($company['name']) ?> why (optional)</label>
        <textarea id="dc-reason" name="reason" class="form-control" rows="3" maxlength="1000"></textarea>
        <div class="form-text">You won't be able to sign it from this link afterwards.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Decline</button></div>
    </form>
  </div></div>
</div>

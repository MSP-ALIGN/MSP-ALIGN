<?php
/**
 * Signature: type it or draw it, with name, title and the consent to sign electronically. Used on the signing
 * page and for the provider's signature. contracts.js runs the pad; without JavaScript, typing still works.
 * @var string $p (id prefix); string $sigName, $sigTitle, $sigTyped; string $company (who it's with); ?string $photo (img URL)
 */
$sigTyped = $sigTyped ?? '';
$photo = $photo ?? null;
?>
<div class="sigpad" data-sigpad>
  <input type="hidden" name="sig_kind" value="type" data-sig-kind>
  <input type="hidden" name="sig_png" value="" data-sig-png>
  <div class="row g-2">
    <div class="col-sm-7 mb-2"><label for="<?= e($p) ?>-name">Full name</label>
      <input id="<?= e($p) ?>-name" name="sig_name" class="form-control" maxlength="120" required autocomplete="name" value="<?= e($sigName) ?>" data-sig-name></div>
    <div class="col-sm-5 mb-2"><label for="<?= e($p) ?>-title">Title</label>
      <input id="<?= e($p) ?>-title" name="sig_title" class="form-control" maxlength="190" autocomplete="organization-title" value="<?= e($sigTitle) ?>"></div>
  </div>
  <div class="d-flex align-items-center mb-1">
    <label class="me-auto mb-0">Signature</label>
    <div class="btn-group btn-group-sm sig-tabs" role="tablist">
      <button type="button" class="btn btn-outline-secondary active" data-sig-tab="type" aria-pressed="true"><i class="fas fa-keyboard me-1"></i>Type</button>
      <button type="button" class="btn btn-outline-secondary" data-sig-tab="draw" aria-pressed="false"><i class="fas fa-signature me-1"></i>Draw</button>
    </div>
  </div>
  <div class="sig-area<?= $photo ? ' has-photo' : '' ?>">
    <?php if ($photo): ?><img class="sig-photo" src="<?= e($photo) ?>" alt="" title="Your profile picture is shown next to your signature"><?php endif; ?>
    <div class="sig-pane" data-sig-pane="type">
      <input name="sig_typed" class="form-control sig-typed" maxlength="120" placeholder="Type your signature" aria-label="Type your signature" value="<?= e($sigTyped) ?>" data-sig-typed>
    </div>
    <div class="sig-pane d-none" data-sig-pane="draw">
      <canvas class="sig-canvas" width="900" height="240" aria-label="Draw your signature with the mouse or your finger" role="img" data-sig-canvas></canvas>
      <button type="button" class="btn btn-xs btn-light sig-clear" data-sig-clear><i class="fas fa-eraser me-1"></i>Clear</button>
    </div>
  </div>
  <div class="form-check mt-3">
    <input class="form-check-input" type="checkbox" name="consent" value="1" id="<?= e($p) ?>-consent" required>
    <label class="form-check-label small" for="<?= e($p) ?>-consent">I agree to do business electronically with <?= e($company) ?> and to sign this contract electronically.
      My electronic signature is as binding as my handwritten signature.</label>
  </div>
</div>

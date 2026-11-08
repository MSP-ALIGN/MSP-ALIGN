<?php
/**
 * 2.34.0 The "Send for signature" form's fields (documents/_signatures and the compliance report's send page): who
 * signs (the client's contacts with an email address), the emailed code, the countersignature, "Ask again when this
 * document changes", how long the links work and an optional message.
 * @var array $contacts (Signing::contacts); string $what (a sentence on what is sent). Every value is escaped; the
 * server keeps only the client's own contacts (Signing::pick) and checks every option again.
 */
use Align\Docs\Signing;
?>
<p class="small text-muted mb-2"><?= e($what) ?> Each person gets their own private link by email, and everyone signs at once.</p>
<fieldset class="mb-3">
  <legend class="form-label fs-6 mb-1">Who signs</legend>
  <?php if (!$contacts): ?>
    <div class="text-muted small">This client has no contacts with an email address. Add one on the client's Contacts page first.</div>
  <?php endif; ?>
  <div class="sign-pick" style="max-height:220px;overflow:auto">
    <?php foreach ($contacts as $c): ?>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="signers[]" value="<?= (int) $c['id'] ?>" id="sg-c<?= (int) $c['id'] ?>">
        <label class="form-check-label" for="sg-c<?= (int) $c['id'] ?>"><?= e($c['name']) ?><?= $c['title'] ? ', ' . e($c['title']) : '' ?> <span class="text-muted">&lt;<?= e($c['email']) ?>&gt;</span></label></div>
    <?php endforeach; ?>
  </div>
  <div class="form-text">Up to <?= Signing::MAX_SIGNERS ?> people.</div>
</fieldset>
<div class="form-check"><input class="form-check-input" type="checkbox" name="verify_code" value="1" id="sg-code" checked>
  <label class="form-check-label" for="sg-code">Ask for a code emailed to them before they can open it</label></div>
<div class="form-check"><input class="form-check-input" type="checkbox" name="countersign" value="1" id="sg-counter">
  <label class="form-check-label" for="sg-counter">I countersign after they've signed</label></div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="resign" value="1" id="sg-resign">
  <label class="form-check-label" for="sg-resign">Ask again when this document changes</label>
  <div class="form-text">When the signed document changes later, it shows <b>Needs signing again</b> with a <b>Send again</b> button. Nothing is emailed until you press it.</div></div>
<div class="row g-2">
  <div class="col-sm-4"><label for="sg-days">Links work for (days)</label><input id="sg-days" type="number" name="days" class="form-control" min="1" max="<?= Signing::MAX_DAYS ?>" value="<?= Signing::DEFAULT_DAYS ?>"></div>
  <div class="col-sm-8"><label for="sg-msg">Message <small class="text-muted">(optional)</small></label><textarea id="sg-msg" name="message" class="form-control" rows="2" maxlength="4000"></textarea></div>
</div>
<p class="small text-muted mt-2 mb-0">Reminders follow the contracts' schedule (Contract templates → settings).</p>

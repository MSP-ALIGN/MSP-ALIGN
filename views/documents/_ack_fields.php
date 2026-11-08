<?php
/**
 * 2.34.0 The fields of "Ask staff to acknowledge" and "Add people" (documents/_acknowledgments): the client's contacts
 * with an email address, a box to paste more addresses, and (when $full) "Ask again when this document changes", how
 * long the links work, a message and "Counts as evidence for" a compliance control.
 * @var string $p (a fixed id prefix from the caller); array $contacts (Signing::contacts), $controls
 *   (Acknowledging::controls); bool $full; array $skip (lower-cased addresses already on the request, not listed)
 * Every value is escaped. The server keeps only the client's own contacts and valid addresses (Acknowledging::recipients)
 * and a control of the client's own frameworks (Acknowledging::controlOk), and checks every option again.
 */
use Align\Docs\Acknowledging;
use Align\Docs\Signing;

$skip = array_fill_keys($skip ?? [], true);
$list = array_values(array_filter($contacts, fn($c) => !isset($skip[strtolower(trim((string) $c['email']))])));
?>
<fieldset class="mb-3">
  <legend class="form-label fs-6 mb-1">The client's contacts</legend>
  <?php if (!$list): ?><div class="text-muted small"><?= $contacts ? 'All of this client\'s contacts with an email address are already on it.' : 'This client has no contacts with an email address. Paste addresses below instead.' ?></div><?php endif; ?>
  <div class="ack-pick" style="max-height:200px;overflow:auto">
    <?php foreach ($list as $c): $cid = (int) $c['id']; ?>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="people[]" value="<?= $cid ?>" id="<?= e($p) ?>-c<?= $cid ?>">
        <label class="form-check-label" for="<?= e($p) ?>-c<?= $cid ?>"><?= e($c['name']) ?><?= $c['title'] ? ', ' . e($c['title']) : '' ?> <span class="text-muted">&lt;<?= e($c['email']) ?>&gt;</span></label></div>
    <?php endforeach; ?>
  </div>
</fieldset>
<div class="mb-3">
  <label for="<?= e($p) ?>-pasted" class="form-label">And/or paste staff email addresses</label>
  <textarea id="<?= e($p) ?>-pasted" name="pasted" class="form-control font-monospace small" rows="4" maxlength="<?= Acknowledging::MAX_PASTE ?>" placeholder="pat@example.com&#10;Sam Lee &lt;sam@example.com&gt;"></textarea>
  <div class="form-text">One per line or separated by commas, each an address or <b>Name &lt;address&gt;</b>. Invalid addresses and repeats are left out; up to <?= Acknowledging::MAX_PEOPLE ?> people. Pasted people who aren't contacts are kept on this request only (no contact is made).</div>
</div>
<?php if ($full): ?>
  <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="resign" value="1" id="<?= e($p) ?>-resign">
    <label class="form-check-label" for="<?= e($p) ?>-resign">Ask again when this document changes</label>
    <div class="form-text">When the document changes later, it shows <b>Needs acknowledging again</b> with buttons to ask everyone (or only those who acknowledged). Nothing is emailed until you press one.</div></div>
  <div class="mb-3">
    <label for="<?= e($p) ?>-ctl" class="form-label">Counts as evidence for <small class="text-muted">(optional)</small></label>
    <select id="<?= e($p) ?>-ctl" name="control_id" class="form-select">
      <option value="">— None —</option>
      <?php $fw = null; foreach ($controls as $c): if ($c['fw_name'] !== $fw): ?><?= $fw !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($c['fw_name']) ?>"><?php $fw = $c['fw_name']; endif; ?>
        <option value="<?= (int) $c['id'] ?>"><?= e(trim(($c['ref'] ?? '') . ' ' . $c['title'])) ?></option>
      <?php endforeach; ?><?= $fw !== null ? '</optgroup>' : '' ?>
    </select>
    <div class="form-text"><?= $controls ? 'A control of this client\'s frameworks. Align offers a button to set its evidence ("5 of 5 staff acknowledged …") and link this document; nothing is written to the checklist by itself.' : 'This client has no compliance frameworks assigned.' ?></div>
  </div>
  <div class="row g-2">
    <div class="col-sm-4"><label for="<?= e($p) ?>-days">Links work for (days)</label><input id="<?= e($p) ?>-days" type="number" name="days" class="form-control" min="1" max="<?= Signing::MAX_DAYS ?>" value="<?= Signing::DEFAULT_DAYS ?>"></div>
    <div class="col-sm-8"><label for="<?= e($p) ?>-msg">Message <small class="text-muted">(optional)</small></label><textarea id="<?= e($p) ?>-msg" name="message" class="form-control" rows="2" maxlength="4000"></textarea></div>
  </div>
  <p class="small text-muted mt-2 mb-0">Reminders follow the contracts' schedule (Contract templates → settings).</p>
<?php endif; ?>

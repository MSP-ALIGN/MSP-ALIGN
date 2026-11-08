<?php
/**
 * 2.34.0 Links made with "Create link only" (Docs\Signing::takeOnce), shown once to the staff member who made them,
 * each with a Copy button. Nothing was emailed; the database keeps only each token's hash (and an encrypted copy).
 * @var ?array $once ['kind' => 'signature' | 'acknowledge', 'links' => [name, email, url, until]]
 * Every value is escaped; the URLs were built by Align from the configured address.
 */
if (empty($once['links'])) {
    return;
}
$what = $once['kind'] === 'signature' ? 'Signing' : 'Acknowledgment';
?>
<div class="alert alert-success" data-links-once>
  <div class="fw-bold mb-1"><i class="fas fa-link me-1"></i><?= e($what) ?> <?= count($once['links']) === 1 ? 'link' : 'links' ?> (shown once)</div>
  <div class="small mb-2">Nothing was emailed. Send each person their own link from your own email; Align sends them no reminders.</div>
  <div style="max-height:320px;overflow:auto">
  <?php foreach ($once['links'] as $i => $l): ?>
    <div class="mb-2">
      <label class="small" for="once-link-<?= (int) $i ?>"><?= e($l['name']) ?> &lt;<?= e($l['email']) ?>&gt; · works until <?= e(fmt_date($l['until'])) ?></label>
      <div class="input-group input-group-sm"><input class="form-control select-all" id="once-link-<?= (int) $i ?>" readonly value="<?= e($l['url']) ?>" data-once-link>
        <button type="button" class="btn btn-light" data-copy="#once-link-<?= (int) $i ?>"><i class="fas fa-copy me-1"></i>Copy</button></div>
    </div>
  <?php endforeach; ?>
  </div>
</div>

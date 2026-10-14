<?php
/**
 * The contact window (add or edit), for techs and admins. @var ?array $k contact (null = new); $cid; $back;
 * 2.19.0 ?array $licenses (Assignments rows), $devices (Lifecycle::lastUsers rows), shown read-only when given
 * Details the PSA owns are read-only unless two-way sync pushes them; every value is escaped (also inside the
 * data-confirm texts). $back is checked again by the controller.
 */
$k = $k ?? null;
$fromPsa = $k && $k['source'] === 'psa';
$push = $fromPsa && \Align\Contacts\Contacts::canPush(['psa_id' => $k['client_psa_id'] ?? null]);
$id = $k ? 'modal-contact-' . (int) $k['id'] : 'modal-contact';
$ro = $fromPsa && !$push ? 'readonly' : '';
$roFixed = $fromPsa ? 'readonly' : '';
$tag = $fromPsa ? ' <span class="badge text-bg-light border fw-normal" title="Managed in ' . psa_name() . '">' . psa_name() . '</span>' : '';
$box = function (string $name, string $label, bool $locked) use ($k, $id) {
    return '<div class="form-check me-3"><input type="checkbox" class="form-check-input" id="' . $id . '-' . $name . '" name="' . $name . '" value="1"'
        . (!empty($k[$name]) ? ' checked' : '') . ($locked ? ' disabled' : '') . '><label class="form-check-label fw-normal" for="' . $id . '-' . $name . '">' . e($label) . '</label></div>';
};
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $k ? '/contacts/' . (int) $k['id'] : '/clients/' . (int) $cid . '/contacts' ?>" data-unsaved>
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-address-card me-2"></i><?= $k ? e($k['name']) : 'Add contact' ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($push): ?><div class="alert alert-light border small py-2"><i class="fas fa-circle-info me-1"></i>This contact comes from <?= e(psa_name()) ?>. Changes to name, title, department, email and phones are saved to <?= e(psa_name()) ?> too. Location and <?= e(psa_name()) ?> flags are managed in <?= e(psa_name()) ?>.</div>
          <?php elseif ($fromPsa): ?><div class="alert alert-light border small py-2"><i class="fas fa-circle-info me-1"></i>This contact comes from <?= e(psa_name()) ?>. Its details and <?= e(psa_name()) ?> flags update automatically, so edit those in <?= e(psa_name()) ?>. Decision maker, meeting invitee and notes are kept in Align.</div><?php endif; ?>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label>Name<?= $tag ?></label><input name="name" class="form-control" value="<?= e($k['name'] ?? '') ?>" <?= $ro ?: 'required' ?>></div>
            <div class="mb-3 col-md-4"><label>Title<?= $tag ?></label><input name="title" class="form-control" value="<?= e($k['title'] ?? '') ?>" <?= $ro ?>></div>
            <div class="mb-3 col-md-4"><label>Department<?= $tag ?></label><input name="department" class="form-control" value="<?= e($k['department'] ?? '') ?>" <?= $ro ?>></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label>Email<?= $tag ?></label><input type="email" name="email" class="form-control" value="<?= e($k['email'] ?? '') ?>" <?= $ro ?>></div>
            <div class="mb-3 col-md-3"><label>Phone<?= $tag ?></label><input name="phone" class="form-control" value="<?= e($k['phone'] ?? '') ?>" <?= $ro ?>></div>
            <div class="mb-3 col-md-2"><label>Ext.<?= $tag ?></label><input name="extension" class="form-control" value="<?= e($k['extension'] ?? '') ?>" <?= $ro ?>></div>
            <div class="mb-3 col-md-3"><label>Mobile<?= $tag ?></label><input name="mobile" class="form-control" value="<?= e($k['mobile'] ?? '') ?>" <?= $ro ?>></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label>Location<?= $tag ?></label><input name="location" class="form-control" value="<?= e($k['location'] ?? '') ?>" <?= $roFixed ?>></div>
            <div class="mb-3 col-md-8"><label><?= psa_on() || $fromPsa ? e(psa_name()) . ' flags' : 'Flags' ?><?= $tag ?></label>
              <div class="d-flex flex-wrap pt-2"><?= $box('is_primary', 'Primary', $fromPsa) ?><?= $box('is_important', 'Important', $fromPsa) ?><?= $box('is_billing', 'Billing', $fromPsa) ?><?= $box('is_technical', 'Technical', $fromPsa) ?></div></div>
          </div>
          <div class="border rounded p-2 mb-3 contract-box">
            <div class="small fw-bold text-muted text-uppercase mb-2"><i class="fas fa-user-tie me-1"></i>vCIO</div>
            <div class="d-flex flex-wrap"><?= $box('decision_maker', 'Decision maker (signs off on budget and projects)', false) ?><?= $box('qbr', 'Invite to business reviews / meetings', false) ?></div>
          </div>
          <?php if ($k && (isset($licenses) || isset($devices))): // 2.19.0 ?>
            <div class="row g-2 mb-3">
              <div class="col-md-6"><div class="small fw-bold text-muted text-uppercase mb-1"><i class="fas fa-key me-1"></i>Licenses</div>
                <?php if (empty($licenses)): ?><div class="small text-muted">None assigned. Assign licenses from the client's Licensing page.</div><?php else: ?>
                <ul class="list-unstyled small mb-0"><?php foreach ($licenses as $a): ?><li><a href="/licenses/<?= (int) $a['license_id'] ?>/people"><?= e($a['license_name']) ?></a> <span class="text-muted"><?= $a['source'] === 'm365' ? '· Microsoft 365' : '' ?></span></li><?php endforeach; ?></ul><?php endif; ?></div>
              <div class="col-md-6"><div class="small fw-bold text-muted text-uppercase mb-1"><i class="fas fa-desktop me-1"></i>Devices last signed in to</div>
                <?php if (empty($devices)): ?><div class="small text-muted">None found (matched on the RMM's last user).</div><?php else: ?>
                <ul class="list-unstyled small mb-0"><?php foreach ($devices as $d): ?><li><?= e((string) $d['name']) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
            </div>
          <?php endif; ?>
          <?php if ($fromPsa && $k['psa_notes']): ?><div class="mb-3"><label><?= e(psa_name()) ?> notes<?= $tag ?></label><textarea class="form-control" rows="2" readonly><?= e($k['psa_notes']) ?></textarea></div><?php endif; ?>
          <div class="mb-3 mb-0"><label>Notes <small class="text-muted">(Align)</small></label><textarea name="align_notes" class="form-control" rows="2" placeholder="Priorities, communication preferences, who they report to…"><?= e($k['align_notes'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($k): ?>
            <?php if ($k['archived_at']): ?><button class="btn btn-outline-success me-auto" name="action" value="restore" formnovalidate data-enter-skip<?= $push && ($k['archived_reason'] ?? '') === 'psa' && \Align\Providers\Providers::psaSupports('contacts.archive')
                ? ' data-confirm="' . e('Restore ' . $k['name'] . ' in Align and ' . psa_name() . '? This also re-enables their client portal login in ' . psa_name() . ' if they have one. Their Important, Billing and Technical flags are not restored (set them again in ' . psa_name() . ').') . '"' : '' ?>><i class="fas fa-rotate-left me-1"></i>Restore</button>
            <?php else: ?><button class="btn btn-outline-secondary me-auto" name="action" value="archive" formnovalidate data-confirm="<?= e($push && \Align\Providers\Providers::psaSupports('contacts.archive')
                ? 'Archive ' . $k['name'] . ' in Align and ' . psa_name() . '? ' . psa_name() . ' also clears their Important, Billing and Technical flags and archives their client portal login there.'
                : 'Archive ' . $k['name'] . '?') ?>"><i class="fas fa-box-archive me-1"></i>Archive</button><?php endif; ?>
            <?php if (!$fromPsa): ?><button class="btn btn-outline-danger me-2" name="action" value="delete" formnovalidate data-confirm="Delete <?= e($k['name']) ?> permanently?"><i class="fas fa-trash"></i></button><?php endif; ?>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

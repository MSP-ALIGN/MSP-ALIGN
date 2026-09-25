<?php
/** @var ?array $k contact (null = new); $cid; $back */
$k = $k ?? null;
$itflow = $k && $k['source'] === 'itflow';
$push = $itflow && \Align\Contacts\Contacts::canPush(['itflow_client_id' => $k['client_itflow_id'] ?? null]);
$id = $k ? 'modal-contact-' . (int) $k['id'] : 'modal-contact';
$ro = $itflow && !$push ? 'readonly' : '';
$roFixed = $itflow ? 'readonly' : '';
$tag = $itflow ? ' <span class="badge badge-light border font-weight-normal" title="Managed in ITFlow">ITFlow</span>' : '';
$box = function (string $name, string $label, bool $locked) use ($k, $id) {
    return '<div class="custom-control custom-checkbox mr-3"><input type="checkbox" class="custom-control-input" id="' . $id . '-' . $name . '" name="' . $name . '" value="1"'
        . (!empty($k[$name]) ? ' checked' : '') . ($locked ? ' disabled' : '') . '><label class="custom-control-label font-weight-normal" for="' . $id . '-' . $name . '">' . e($label) . '</label></div>';
};
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $k ? '/contacts/' . (int) $k['id'] : '/clients/' . (int) $cid . '/contacts' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-address-card mr-2"></i><?= $k ? e($k['name']) : 'Add contact' ?></h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <?php if ($push): ?><div class="alert alert-light border small py-2"><i class="fas fa-circle-info mr-1"></i>This contact comes from ITFlow. Changes to name, title, department, email and phones are saved to ITFlow too. Location and ITFlow flags are managed in ITFlow.</div>
          <?php elseif ($itflow): ?><div class="alert alert-light border small py-2"><i class="fas fa-circle-info mr-1"></i>This contact comes from ITFlow. Its details and ITFlow flags update automatically, so edit those in ITFlow. Decision maker, meeting invitee and notes are kept in Align.</div><?php endif; ?>
          <div class="form-row">
            <div class="form-group col-md-4"><label>Name<?= $tag ?></label><input name="name" class="form-control" value="<?= e($k['name'] ?? '') ?>" <?= $ro ?: 'required' ?>></div>
            <div class="form-group col-md-4"><label>Title<?= $tag ?></label><input name="title" class="form-control" value="<?= e($k['title'] ?? '') ?>" <?= $ro ?>></div>
            <div class="form-group col-md-4"><label>Department<?= $tag ?></label><input name="department" class="form-control" value="<?= e($k['department'] ?? '') ?>" <?= $ro ?>></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-4"><label>Email<?= $tag ?></label><input type="email" name="email" class="form-control" value="<?= e($k['email'] ?? '') ?>" <?= $ro ?>></div>
            <div class="form-group col-md-3"><label>Phone<?= $tag ?></label><input name="phone" class="form-control" value="<?= e($k['phone'] ?? '') ?>" <?= $ro ?>></div>
            <div class="form-group col-md-2"><label>Ext.<?= $tag ?></label><input name="extension" class="form-control" value="<?= e($k['extension'] ?? '') ?>" <?= $ro ?>></div>
            <div class="form-group col-md-3"><label>Mobile<?= $tag ?></label><input name="mobile" class="form-control" value="<?= e($k['mobile'] ?? '') ?>" <?= $ro ?>></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-4"><label>Location<?= $tag ?></label><input name="location" class="form-control" value="<?= e($k['location'] ?? '') ?>" <?= $roFixed ?>></div>
            <div class="form-group col-md-8"><label>ITFlow flags<?= $tag ?></label>
              <div class="d-flex flex-wrap pt-2"><?= $box('is_primary', 'Primary', $itflow) ?><?= $box('is_important', 'Important', $itflow) ?><?= $box('is_billing', 'Billing', $itflow) ?><?= $box('is_technical', 'Technical', $itflow) ?></div></div>
          </div>
          <div class="border rounded p-2 mb-3 contract-box">
            <div class="small font-weight-bold text-muted text-uppercase mb-2"><i class="fas fa-user-tie mr-1"></i>vCIO</div>
            <div class="d-flex flex-wrap"><?= $box('decision_maker', 'Decision maker (signs off on budget and projects)', false) ?><?= $box('qbr', 'Invite to business reviews / meetings', false) ?></div>
          </div>
          <?php if ($itflow && $k['itflow_notes']): ?><div class="form-group"><label>ITFlow notes<?= $tag ?></label><textarea class="form-control" rows="2" readonly><?= e($k['itflow_notes']) ?></textarea></div><?php endif; ?>
          <div class="form-group mb-0"><label>Notes <small class="text-muted">(Align)</small></label><textarea name="align_notes" class="form-control" rows="2" placeholder="Priorities, communication preferences, who they report to…"><?= e($k['align_notes'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($k): ?>
            <?php if ($k['archived_at']): ?><button class="btn btn-outline-success mr-auto" name="action" value="restore" formnovalidate><i class="fas fa-rotate-left mr-1"></i>Restore</button>
            <?php else: ?><button class="btn btn-outline-secondary mr-auto" name="action" value="archive" formnovalidate data-confirm="Archive <?= e($k['name']) ?>?"><i class="fas fa-box-archive mr-1"></i>Archive</button><?php endif; ?>
            <?php if (!$itflow): ?><button class="btn btn-outline-danger mr-2" name="action" value="delete" formnovalidate data-confirm="Delete <?= e($k['name']) ?> permanently?"><i class="fas fa-trash"></i></button><?php endif; ?>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check mr-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

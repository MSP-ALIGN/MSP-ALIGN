<?php
use Align\Vendors\Vendors;

/**
 * 2.8.0 The vendor template window, for techs and admins. @var ?array $t template (null = new); string $back
 * A template holds what's the same for every client; each client's account number, contact and services are on
 * the client's vendor. Every value is escaped; $back is checked again by the controller.
 */
$t = $t ?? null;
$edit = $t !== null;
$id = $edit ? 'modal-vtemplate-' . (int) $t['id'] : 'modal-vtemplate';
$in = fn(string $k, string $label, string $ph = '', string $col = 'col-md-6') => '<div class="mb-3 ' . $col . '"><label for="' . $id . '-' . $k . '">' . e($label) . '</label><input id="' . $id . '-' . $k
    . '" name="' . $k . '" class="form-control" value="' . e((string) ($t[$k] ?? '')) . '" placeholder="' . e($ph) . '" maxlength="' . (Vendors::SIZES[$k] ?? 190) . '"' . ($k === 'name' ? ' required' : '') . '></div>';
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $edit ? '/vendors/templates/' . (int) $t['id'] : '/vendors/templates' ?>" data-unsaved>
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-layer-group me-2"></i><?= $edit ? 'Edit the ' . e($t['name']) . ' template' : 'Add vendor template' ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">What's the same for every client. Each client's account number, contact and services go on the client's vendor, and a client can override any of these.</p>
          <div class="row g-2">
            <?= $in('name', 'Name', 'Comcast Business') ?>
            <div class="mb-3 col-md-6"><label for="<?= $id ?>-category">Category</label>
              <select id="<?= $id ?>-category" name="category" class="form-select"><?php foreach (Vendors::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= ($t['category'] ?? 'other') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="row g-2"><?= $in('support_phone', 'Support phone') ?><?= $in('support_email', 'Support email') ?></div>
          <div class="row g-2"><?= $in('website', 'Website / portal', 'https://…') ?><?= $in('hours', 'Support hours', '24/7, M-F 8-5…') ?></div>
          <div class="row g-2"><?= $in('sla', 'SLA / response time', '4 hours, next business day…') ?></div>
          <?php if ($edit && $t['psa_template_id']): ?><p class="small text-muted"><i class="fas fa-circle-info me-1"></i>Linked to a vendor template in <?= e(psa_name()) ?>: client vendors made from it there use this template here.</p><?php endif; ?>
          <div class="mb-0"><label for="<?= $id ?>-notes">Notes</label><textarea id="<?= $id ?>-notes" name="notes" class="form-control" rows="2" maxlength="5000" placeholder="How to open a ticket, escalation path… (never passwords or PINs)"><?= e($t['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($edit): ?><button class="btn btn-outline-danger me-auto" name="action" value="delete" formnovalidate data-confirm="Delete the <?= e($t['name']) ?> template? Vendors made from it keep their name and their own details."><i class="fas fa-trash"></i></button><?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i><?= $edit ? 'Save' : 'Add template' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

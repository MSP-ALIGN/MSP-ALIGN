<?php
use Align\Licensing\Licenses;

/** @var ?array $l  license (null = new); $cid client id for new; $back return path */
$l = $l ?? null;
$itflow = $l && $l['source'] === 'itflow';
$id = $l ? 'modal-license-' . (int) $l['id'] : 'modal-license';
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
$ro = $itflow ? 'readonly' : '';
$tag = $itflow ? ' <span class="badge badge-light border font-weight-normal" title="Managed in ITFlow">ITFlow</span>' : '';
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $l ? '/licenses/' . (int) $l['id'] : '/clients/' . (int) $cid . '/licenses' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-key mr-2"></i><?= $l ? 'Edit ' . e($l['name']) : 'Add license' ?></h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <?php if ($itflow): ?>
            <div class="alert alert-light border small py-2"><i class="fas fa-circle-info mr-1"></i>This license comes from ITFlow (Software). Its name, type, seats, vendor and dates are updated from ITFlow every few minutes, so change those in ITFlow. Price, billing, category and seats in use are kept in Align.</div>
          <?php endif; ?>
          <div class="form-row">
            <div class="form-group col-md-6"><label>Product<?= $tag ?></label><input name="name" class="form-control" value="<?= e($l['name'] ?? '') ?>" <?= $itflow ? 'readonly' : 'required' ?> placeholder="Microsoft 365 Business Premium"></div>
            <div class="form-group col-md-3"><label>Vendor<?= $tag ?></label><input name="vendor" class="form-control" value="<?= e($l['vendor'] ?? '') ?>" <?= $ro ?> placeholder="Pax8, Microsoft…"></div>
            <div class="form-group col-md-3"><label>Category</label>
              <select name="category" class="form-control"><?php foreach (Licenses::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $l['category'] ?? 'productivity') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-3"><label>License type<?= $tag ?></label>
              <?php if ($itflow): ?><input class="form-control" value="<?= e(Licenses::TYPES[$l['license_type']]) ?>" readonly>
              <?php else: ?><select name="license_type" class="form-control"><?php foreach (Licenses::TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $sel($k, $l['license_type'] ?? 'user') ?>><?= e($label) ?></option><?php endforeach; ?></select><?php endif; ?></div>
            <div class="form-group col-md-3"><label>Seats / licenses<?= $tag ?></label><input type="number" min="0" name="seats" class="form-control" value="<?= e($l['seats'] ?? '') ?>" <?= $ro ?> data-lic="seats"></div>
            <div class="form-group col-md-3"><label>In use <small class="text-muted">(optional)</small></label><input type="number" min="0" name="seats_used" class="form-control" value="<?= e($l['seats_used'] ?? '') ?>"></div>
            <div class="form-group col-md-3"><label>Kind<?= $tag ?></label><input name="software_type" class="form-control" value="<?= e($l['software_type'] ?? '') ?>" <?= $ro ?> placeholder="SaaS, Desktop…"></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-3"><label>Price</label>
              <div class="input-group"><div class="input-group-prepend"><span class="input-group-text">$</span></div><input type="number" min="0" step="0.01" name="unit_price" class="form-control" value="<?= e($l['unit_price'] ?? '') ?>" data-lic="price"></div></div>
            <div class="form-group col-md-3"><label>Priced</label>
              <select name="pricing" class="form-control" data-lic="pricing"><option value="per_seat" <?= $sel('per_seat', $l['pricing'] ?? 'per_seat') ?>>Per seat</option><option value="flat" <?= $sel('flat', $l['pricing'] ?? '') ?>>Flat (whole license)</option></select></div>
            <div class="form-group col-md-3"><label>Billed</label>
              <select name="billing_cycle" class="form-control" data-lic="cycle"><?php foreach (Licenses::CYCLES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $l['billing_cycle'] ?? 'monthly') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="form-group col-md-3"><label>Cost</label><div class="form-control-plaintext small license-calc" data-lic="out">—</div></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-3"><label>Purchased<?= $tag ?></label><input type="date" name="purchase_date" class="form-control" value="<?= e($l['purchase_date'] ?? '') ?>" <?= $ro ?>></div>
            <div class="form-group col-md-3"><label>Renews / expires<?= $tag ?></label><input type="date" name="expire_date" class="form-control" value="<?= e($l['expire_date'] ?? '') ?>" <?= $ro ?>></div>
            <div class="form-group col-md-3"><label>Version<?= $tag ?></label><input name="version" class="form-control" value="<?= e($l['version'] ?? '') ?>" <?= $ro ?>></div>
            <div class="form-group col-md-3 d-flex align-items-end"><div class="custom-control custom-checkbox mb-2">
              <input type="checkbox" class="custom-control-input" id="<?= $id ?>-renew" name="auto_renew" value="1" <?= ($l['auto_renew'] ?? 1) ? 'checked' : '' ?>>
              <label class="custom-control-label font-weight-normal" for="<?= $id ?>-renew">Auto-renews</label></div></div>
          </div>
          <?php if ($itflow && $l['notes']): ?><div class="form-group"><label>ITFlow notes<?= $tag ?></label><textarea class="form-control" rows="2" readonly><?= e($l['notes']) ?></textarea></div><?php endif; ?>
          <div class="form-group mb-0"><label>Notes <small class="text-muted">(Align)</small></label><textarea name="align_notes" class="form-control" rows="2" placeholder="SKU, term, reseller, who to contact at renewal…"><?= e($l['align_notes'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($l): ?>
            <?php if ($l['retired_at']): ?>
              <button class="btn btn-outline-success mr-auto" name="action" value="restore" formnovalidate><i class="fas fa-rotate-left mr-1"></i>Restore</button>
            <?php else: ?>
              <button class="btn btn-outline-secondary mr-auto" name="action" value="retire" formnovalidate data-confirm="Retire <?= e($l['name']) ?>? It stops counting toward costs; you can restore it."><i class="fas fa-box-archive mr-1"></i>Retire</button>
            <?php endif; ?>
            <?php if (!$itflow): ?><button class="btn btn-outline-danger mr-2" name="action" value="delete" formnovalidate data-confirm="Delete <?= e($l['name']) ?> permanently?"><i class="fas fa-trash"></i></button><?php endif; ?>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check mr-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

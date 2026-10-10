<?php
use Align\Licensing\Licenses;

/**
 * The license window, for techs and admins.
 * @var ?array $l  license (null = new; without an id = a new one filled in from a client's suggestion); $cid client id for new; $back return path
 * 2.6.0: a Microsoft 365 license (source m365) has its name, type, seats, seats in use and SKU read-only (from the
 * client's tenant); price, billing, dates and notes stay editable, and "use the price list's price again" appears
 * once it has its own price. It can be retired but not deleted (it would come back on the next sync).
 * 2.6.3: a Google Workspace license (source gws) is handled the same way (seats are the users assigned the edition).
 * Every value is escaped (a suggestion's text comes from a portal user); $back is checked again by the controller.
 */
$l = $l ?? null;
$edit = $l && !empty($l['id']);
$sub = !$edit && !empty($l['submission_id']) ? (int) $l['submission_id'] : 0;
$fromPsa = $edit && $l['source'] === 'psa';
$fromM365 = $edit && in_array($l['source'], ['m365', 'gws'], true); // 2.6.0: name, seats and seats in use come from the client's tenant (2.6.3: or Google Workspace)
$gws = $edit && $l['source'] === 'gws';
$cloud = $gws ? 'Google Workspace' : 'Microsoft 365';
$id = $edit ? 'modal-license-' . (int) $l['id'] : ($sub ? 'modal-suggestion-' . $sub : 'modal-license');
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
$ro = $fromPsa ? 'readonly' : '';
$tag = $fromPsa ? ' <span class="badge text-bg-light border fw-normal" title="Managed in ' . psa_name() . '">' . psa_name() . '</span>' : '';
// Microsoft 365 licenses: what Microsoft owns is read-only (dates, price and notes stay in Align)
$mro = $fromPsa || $fromM365 ? 'readonly' : '';
$mtag = $fromM365 ? ' <span class="badge text-bg-light border fw-normal" title="From the client\'s ' . $cloud . '">' . $cloud . '</span>' : $tag;
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $edit ? '/licenses/' . (int) $l['id'] : '/clients/' . (int) $cid . '/licenses' ?>" data-unsaved>
        <?php if ($sub): ?><input type="hidden" name="submission_id" value="<?= $sub ?>"><?php endif; ?>
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-key me-2"></i><?= $edit ? 'Edit ' . e($l['name']) : ($sub ? 'Add the suggested license' : 'Add license') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($fromPsa): ?>
            <div class="alert alert-light border small py-2"><i class="fas fa-circle-info me-1"></i>This license comes from <?= e(psa_name()) ?>. Its name, type, seats, vendor and dates are updated from <?= e(psa_name()) ?> every few minutes, so change those in <?= e(psa_name()) ?>. Price, billing, category and seats in use are kept in Align.</div>
          <?php endif; ?>
          <?php if ($gws): // 2.6.3 ?>
            <div class="alert alert-light border small py-2"><i class="fab fa-google me-1"></i>This edition comes from the client's Google Workspace. The users assigned it update every hour (seats and seats in use are both that number); the name comes from the price list (Integrations → Google Workspace (clients)). <?= $l['price_source'] === 'custom' ? 'This license has its own price.' : 'Its price follows the price list; change it here to give this client its own.' ?></div>
          <?php elseif ($fromM365): ?>
            <div class="alert alert-light border small py-2"><i class="fab fa-microsoft me-1"></i>This subscription comes from the client's Microsoft 365 tenant. Seats bought and assigned update every hour; the name comes from the price list (Integrations → Microsoft 365 (clients)). <?= $l['price_source'] === 'custom' ? 'This license has its own price.' : 'Its price follows the price list; change it here to give this client its own.' ?></div>
          <?php endif; ?>
          <div class="row g-2">
            <div class="mb-3 col-md-6"><label>Product<?= $mtag ?></label><input name="name" class="form-control" value="<?= e($l['name'] ?? '') ?>" <?= $mro ?: 'required' ?> placeholder="Microsoft 365 Business Premium"></div>
            <?php // 2.8.0: an Align license links to the client vendor its vendor name matches (suggested from the client's vendors)
                $vendorNames = \Align\Vendors\Vendors::names($edit ? (int) $l['client_id'] : (int) ($cid ?? 0)); ?>
            <div class="mb-3 col-md-3"><label>Vendor<?= $mtag ?></label><input name="vendor" class="form-control" value="<?= e($l['vendor'] ?? '') ?>" <?= $mro ?> placeholder="Pax8, Microsoft…" list="<?= $id ?>-vendors" autocomplete="off">
              <datalist id="<?= $id ?>-vendors"><?php foreach ($vendorNames as $vn): ?><option value="<?= e($vn) ?>"></option><?php endforeach; ?></datalist>
              <?php if ($edit && !empty($l['vendor_id'])): ?><div class="form-text"><i class="fas fa-link me-1"></i>Linked to the client's vendor</div>
              <?php elseif (!$mro && $vendorNames): ?><div class="form-text">Pick one of the client's vendors to link it</div><?php endif; ?></div>
            <div class="mb-3 col-md-3"><label>Category</label>
              <select name="category" class="form-select"><?php foreach (Licenses::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $l['category'] ?? 'productivity') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-3"><label>License type<?= $mtag ?></label>
              <?php if ($fromPsa || $fromM365): ?><input class="form-control" value="<?= e(Licenses::TYPES[$l['license_type']]) ?>" readonly>
              <?php else: ?><select name="license_type" class="form-select"><?php foreach (Licenses::TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $sel($k, $l['license_type'] ?? 'user') ?>><?= e($label) ?></option><?php endforeach; ?></select><?php endif; ?></div>
            <div class="mb-3 col-md-3"><label>Seats / licenses<?= $mtag ?></label><input type="number" min="0" name="seats" class="form-control" value="<?= e($l['seats'] ?? '') ?>" <?= $mro ?> data-lic="seats"></div>
            <div class="mb-3 col-md-3"><label>In use <?= $fromM365 ? $mtag : '<small class="text-muted">(optional)</small>' ?></label><input type="number" min="0" name="seats_used" class="form-control" value="<?= e($l['seats_used'] ?? '') ?>" <?= $fromM365 ? 'readonly' : '' ?>></div>
            <div class="mb-3 col-md-3"><label><?= $gws ? 'Google SKU' : ($fromM365 ? 'Microsoft SKU' : 'Kind') ?><?= $mtag ?></label><input name="software_type" class="form-control" value="<?= e($l['software_type'] ?? '') ?>" <?= $mro ?> placeholder="SaaS, Desktop…"></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-3"><label>Price</label>
              <div class="input-group"><span class="input-group-text"><?= e(\Align\Fmt::symbol()) ?></span><input type="number" min="0" step="0.01" name="unit_price" class="form-control" value="<?= e($l['unit_price'] ?? '') ?>" data-lic="price"></div></div>
            <div class="mb-3 col-md-3"><label>Priced</label>
              <select name="pricing" class="form-select" data-lic="pricing"><option value="per_seat" <?= $sel('per_seat', $l['pricing'] ?? 'per_seat') ?>>Per seat</option><option value="flat" <?= $sel('flat', $l['pricing'] ?? '') ?>>Flat (whole license)</option></select></div>
            <div class="mb-3 col-md-3"><label>Billed</label>
              <select name="billing_cycle" class="form-select" data-lic="cycle"><?php foreach (Licenses::CYCLES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $l['billing_cycle'] ?? 'monthly') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3 col-md-3"><label>Cost</label><div class="form-control-plaintext small license-calc" data-lic="out">—</div></div>
          </div>
          <?php if ($fromM365 && $l['price_source'] === 'custom'): ?>
            <div class="form-check mb-3 mt-n2"><input type="checkbox" class="form-check-input" id="<?= $id ?>-list" name="use_list_price" value="1"><label class="form-check-label fw-normal" for="<?= $id ?>-list">Use the price list's price again</label></div>
          <?php endif; ?>
          <div class="row g-2">
            <div class="mb-3 col-md-3"><label>Purchased<?= $tag ?></label><input type="date" name="purchase_date" class="form-control" value="<?= e($l['purchase_date'] ?? '') ?>" <?= $ro ?>></div>
            <div class="mb-3 col-md-3"><label>Renews / expires<?= $tag ?></label><input type="date" name="expire_date" class="form-control" value="<?= e($l['expire_date'] ?? '') ?>" <?= $ro ?>></div>
            <div class="mb-3 col-md-3"><label>Version<?= $tag ?></label><input name="version" class="form-control" value="<?= e($l['version'] ?? '') ?>" <?= $ro ?>></div>
            <div class="mb-3 col-md-3 d-flex align-items-end"><div class="form-check mb-2">
              <input type="checkbox" class="form-check-input" id="<?= $id ?>-renew" name="auto_renew" value="1" <?= ($l['auto_renew'] ?? 1) ? 'checked' : '' ?>>
              <label class="form-check-label fw-normal" for="<?= $id ?>-renew" title="If unticked, the budget stops this cost at the contract end (or expiry) date">Auto-renews</label></div></div>
          </div>
          <?= \Align\View::fetch('partials/contract_fields', ['r' => $l, 'withStart' => true, 'startField' => 'purchase_date']) ?>
          <?php if ($fromPsa && $l['notes']): ?><div class="mb-3"><label><?= e(psa_name()) ?> notes<?= $tag ?></label><textarea class="form-control" rows="2" readonly><?= e($l['notes']) ?></textarea></div><?php endif; ?>
          <div class="mb-3 mb-0"><label>Notes <small class="text-muted">(Align)</small></label><textarea name="align_notes" class="form-control" rows="2" placeholder="SKU, term, reseller, who to contact at renewal…"><?= e($l['align_notes'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($edit): ?>
            <?php if ($l['retired_at']): ?>
              <button class="btn btn-outline-success me-auto" name="action" value="restore" formnovalidate data-enter-skip><i class="fas fa-rotate-left me-1"></i>Restore</button>
            <?php else: ?>
              <button class="btn btn-outline-secondary me-auto" name="action" value="retire" formnovalidate data-confirm="Retire <?= e($l['name']) ?>? It stops counting toward costs; you can restore it."><i class="fas fa-box-archive me-1"></i>Retire</button>
            <?php endif; ?>
            <?php if (!$fromPsa && !$fromM365): ?><button class="btn btn-outline-danger me-2" name="action" value="delete" formnovalidate data-confirm="Delete <?= e($l['name']) ?> permanently?"><i class="fas fa-trash"></i></button><?php endif; ?>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

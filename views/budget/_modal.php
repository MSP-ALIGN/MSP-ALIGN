<?php
use Align\Budget\Budget;

/** @var ?array $m budget_lines row (null = new); $cid; $back. 2.9.0: the vendor field suggests the client's vendors. */
$m = $m ?? null;
$edit = $m && !empty($m['id']); // without an id: a new line filled in from a client's suggestion
$sub = !$edit && !empty($m['submission_id']) ? (int) $m['submission_id'] : 0;
$id = $edit ? 'modal-budget-' . (int) $m['id'] : ($sub ? 'modal-suggestion-' . $sub : 'modal-budget');
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $edit ? '/budget-lines/' . (int) $m['id'] : '/clients/' . (int) $cid . '/budget' ?>" data-unsaved>
        <?php if ($sub): ?><input type="hidden" name="submission_id" value="<?= $sub ?>"><?php endif; ?>
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-coins me-2"></i><?= $edit ? 'Edit budget line' : ($sub ? 'Add the suggested budget item' : 'Add budget line') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">For costs Align doesn't track on its own: internet, phones, cloud hosting, printing contracts, support agreements, training… Licensing, hardware replacements and projects are added automatically. A <b>Managed services</b> line here<?= psa_on() ? ' replaces the estimate from ' . e(psa_name()) . ' invoices' : ' is your agreement amount' ?>.</p>
          <div class="row g-2">
            <div class="mb-3 col-md-6"><label>Name</label><input name="name" class="form-control" required value="<?= e($m['name'] ?? '') ?>" placeholder="Fiber internet, Hosted VoIP, Azure…"></div>
            <div class="mb-3 col-md-3"><label>Category</label>
              <select name="category" class="form-select"><?php foreach (Budget::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $m['category'] ?? 'connectivity') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <?php // 2.9.0: linked to the client vendor its vendor name matches (suggested from the client's vendors)
                $vendorNames = \Align\Vendors\Vendors::names($edit ? (int) $m['client_id'] : (int) ($cid ?? 0)); ?>
            <div class="mb-3 col-md-3"><label>Vendor <small class="text-muted">(optional)</small></label><input name="vendor" class="form-control" value="<?= e($m['vendor'] ?? '') ?>" list="<?= $id ?>-vendors" autocomplete="off" maxlength="190">
              <datalist id="<?= $id ?>-vendors"><?php foreach ($vendorNames as $vn): ?><option value="<?= e($vn) ?>"></option><?php endforeach; ?></datalist>
              <?php if ($edit && !empty($m['vendor_id'])): ?><div class="form-text"><i class="fas fa-link me-1"></i>Linked to the client's vendor</div>
              <?php elseif ($vendorNames): ?><div class="form-text">Pick one of the client's vendors to link it</div><?php endif; ?></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-3"><label>Amount</label>
              <div class="input-group"><span class="input-group-text"><?= e(\Align\Fmt::symbol()) ?></span><input type="number" min="0" step="0.01" name="amount" class="form-control" required value="<?= e($m['amount'] ?? '') ?>"></div></div>
            <div class="mb-3 col-md-3"><label>How often</label>
              <select name="frequency" class="form-select"><?php foreach (Budget::FREQUENCIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $m['frequency'] ?? 'monthly') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3 col-md-3"><label>Purchase / start date</label><input type="date" name="start_date" class="form-control" value="<?= e($m['start_date'] ?? '') ?>"></div>
            <div class="mb-3 col-md-3"><label>Stop budgeting after</label><input type="date" name="end_date" class="form-control" value="<?= e($m['end_date'] ?? '') ?>"></div>
          </div>
          <p class="small text-muted mb-2">Annual costs are budgeted in the month of the purchase/start date each year (or the first month of the plan year if there's no date). One-time costs are budgeted on the purchase date. Leave the dates blank for an ongoing cost.</p>
          <?= \Align\View::fetch('partials/contract_fields', ['r' => $m, 'startField' => 'start_date']) ?>
          <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="<?= $id ?>-renew" name="auto_renew" value="1" <?= ($m['auto_renew'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label fw-normal" for="<?= $id ?>-renew">Renews automatically at contract end <small class="text-muted">(untick to stop budgeting this cost after the contract ends)</small></label>
          </div>
          <div class="mb-3 mb-0"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?= e($m['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($edit): ?><button class="btn btn-outline-danger me-auto" name="action" value="delete" formnovalidate data-confirm="Remove this budget line? It comes off the budget. This can't be undone."><i class="fas fa-trash me-1"></i>Remove</button><?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

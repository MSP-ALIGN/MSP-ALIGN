<?php
use Align\Budget\Budget;

/** @var ?array $m budget_lines row (null = new); $cid; $back */
$m = $m ?? null;
$id = $m ? 'modal-budget-' . (int) $m['id'] : 'modal-budget';
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $m ? '/budget-lines/' . (int) $m['id'] : '/clients/' . (int) $cid . '/budget' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-coins mr-2"></i><?= $m ? 'Edit budget line' : 'Add budget line' ?></h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">For costs Align doesn't track on its own: internet, phones, cloud hosting, printing contracts, support agreements, training… Licensing, hardware replacements and projects are added automatically. A <b>Managed services</b> line here<?= psa_on() ? ' replaces the estimate from ' . e(psa_name()) . ' invoices' : ' is your agreement amount' ?>.</p>
          <div class="form-row">
            <div class="form-group col-md-6"><label>Name</label><input name="name" class="form-control" required value="<?= e($m['name'] ?? '') ?>" placeholder="Fiber internet, Hosted VoIP, Azure…"></div>
            <div class="form-group col-md-3"><label>Category</label>
              <select name="category" class="form-control"><?php foreach (Budget::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $m['category'] ?? 'connectivity') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="form-group col-md-3"><label>Vendor <small class="text-muted">(optional)</small></label><input name="vendor" class="form-control" value="<?= e($m['vendor'] ?? '') ?>"></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-3"><label>Amount</label>
              <div class="input-group"><div class="input-group-prepend"><span class="input-group-text">$</span></div><input type="number" min="0" step="0.01" name="amount" class="form-control" required value="<?= e($m['amount'] ?? '') ?>"></div></div>
            <div class="form-group col-md-3"><label>How often</label>
              <select name="frequency" class="form-control"><?php foreach (Budget::FREQUENCIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $m['frequency'] ?? 'monthly') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="form-group col-md-3"><label>Purchase / start date</label><input type="date" name="start_date" class="form-control" value="<?= e($m['start_date'] ?? '') ?>"></div>
            <div class="form-group col-md-3"><label>Stop budgeting after</label><input type="date" name="end_date" class="form-control" value="<?= e($m['end_date'] ?? '') ?>"></div>
          </div>
          <p class="small text-muted mb-2">Annual costs are budgeted in the month of the purchase/start date each year (or the first month of the plan year if there's no date). One-time costs are budgeted on the purchase date. Leave the dates blank for an ongoing cost.</p>
          <?= \Align\View::fetch('partials/contract_fields', ['r' => $m, 'startField' => 'start_date']) ?>
          <div class="custom-control custom-checkbox mb-3">
            <input type="checkbox" class="custom-control-input" id="<?= $id ?>-renew" name="auto_renew" value="1" <?= ($m['auto_renew'] ?? 1) ? 'checked' : '' ?>>
            <label class="custom-control-label font-weight-normal" for="<?= $id ?>-renew">Renews automatically at contract end <small class="text-muted">(untick to stop budgeting this cost after the contract ends)</small></label>
          </div>
          <div class="form-group mb-0"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?= e($m['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($m): ?><button class="btn btn-outline-danger mr-auto" name="action" value="delete" formnovalidate data-confirm="Remove this budget line?"><i class="fas fa-trash mr-1"></i>Remove</button><?php endif; ?>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check mr-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
use Align\Budget\Contracts;

/** Contract fields for a license or budget line. $r = row or null; $uid = unique id prefix; $startField = name of the start/purchase input used to derive the end date. */
$r = $r ?? null;
$term = $r['contract_term_months'] ?? null;
$isCustom = $term !== null && !isset(Contracts::TERMS[(int) $term]);
$sel = fn($a) => $term !== null && !$isCustom && (int) $term === $a ? 'selected' : '';
?>
<div class="contract-box border rounded p-2 mb-3" data-contract data-start-field="<?= e($startField ?? 'start_date') ?>">
  <div class="small fw-bold text-muted text-uppercase mb-2"><i class="fas fa-file-signature me-1"></i>Contract</div>
  <div class="row g-2">
    <?php if (!empty($withStart)): ?>
    <div class="mb-3 col-md-3 mb-2"><label class="small">Contract start</label><input type="date" name="contract_start" class="form-control form-control-sm" value="<?= e($r['contract_start'] ?? '') ?>" data-c="start" placeholder="Purchase date"></div>
    <?php endif; ?>
    <div class="mb-3 col-md-3 mb-2"><label class="small">Term</label>
      <select name="contract_term_months" class="form-select form-select-sm" data-c="term">
        <option value="">None / not set</option>
        <?php foreach (Contracts::TERMS as $m => $label): ?><option value="<?= $m ?>" <?= $sel($m) ?>><?= e($label) ?></option><?php endforeach; ?>
        <option value="custom" <?= $isCustom ? 'selected' : '' ?>>Other (months)…</option>
      </select>
      <input type="number" min="1" max="240" name="contract_term_custom" class="form-control form-control-sm mt-1<?= $isCustom ? '' : ' d-none' ?>" value="<?= $isCustom ? (int) $term : '' ?>" placeholder="Months" data-c="custom"></div>
    <div class="mb-3 col-md-3 mb-2"><label class="small">Contract ends</label><input type="date" name="contract_end" class="form-control form-control-sm" value="<?= e($r['contract_end'] ?? '') ?>" data-c="end"></div>
    <div class="mb-3 col-md-<?= !empty($withStart) ? '3' : '2' ?> mb-2"><label class="small">Notice period</label>
      <div class="input-group input-group-sm"><input type="number" min="0" max="730" name="notice_days" class="form-control" value="<?= e($r['notice_days'] ?? '') ?>" data-c="notice"><span class="input-group-text">days</span></div></div>
    <div class="mb-3 col-md-<?= !empty($withStart) ? '3' : '4' ?> mb-2"><label class="small">Renegotiate / give notice by</label><input type="date" name="renegotiate_date" class="form-control form-control-sm" value="<?= e($r['renegotiate_date'] ?? '') ?>" data-c="reneg"></div>
  </div>
  <div class="small text-muted" data-c="hint">Leave the end date blank to work it out from the start date and term. Leave the renegotiate date blank to use the end date minus the notice period.</div>
</div>

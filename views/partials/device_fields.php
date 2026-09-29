<?php
use Align\Lifecycle\Lifecycle;

/** Device form fields. $d = existing evaluated device or null; $manual = hardware fields editable. */
$d = $d ?? null;
$manual = $manual ?? true;
$pullOnly = $pullOnly ?? false; // IP and location are maintained in the PSA for synced devices
$ro = $manual ? '' : 'readonly';
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
$type = $d ? ($d['o_type'] ?: ($d['device_type'] ?: $d['type'])) : 'Switch';
?>
<div class="form-row">
  <div class="form-group col-md-6">
    <label>Name</label>
    <input name="display_name" class="form-control" value="<?= e($d['display_name'] ?? '') ?>" <?= $manual ? 'required' : 'readonly' ?> placeholder="e.g. Front office switch">
  </div>
  <div class="form-group col-md-6">
    <label>Type<?= $manual ? '' : ' <small class="text-muted">(override what ' . e(\Align\Providers\Providers::rmmName($d['rmm_provider'] ?? null)) . ' reports)</small>' ?></label>
    <select name="device_type" class="form-control">
      <?php foreach (Lifecycle::TYPES as $t => [$class, , $virt]): ?>
        <option value="<?= e($t) ?>" <?= $sel($t, $type) ?>><?= e($t) ?> — <?= e(Lifecycle::CLASSES[$class]) ?><?= $virt ? ' (OS support only)' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<?php if ($manual): ?>
<div class="form-row">
  <div class="form-group col-md-4"><label>Manufacturer</label><input name="manufacturer" class="form-control" value="<?= e($d['manufacturer'] ?? '') ?>" placeholder="Ubiquiti, Fortinet, HP…"></div>
  <div class="form-group col-md-4"><label>Model</label><input name="model" class="form-control" value="<?= e($d['model'] ?? '') ?>"></div>
  <div class="form-group col-md-4"><label>Serial number</label><input name="serial" class="form-control" value="<?= e($d['serial'] ?? '') ?>"></div>
</div>
<div class="form-row">
  <div class="form-group col-md-4"><label>IP address<?= $pullOnly ? ' <small class="text-muted">(from ' . psa_name() . ')</small>' : '' ?></label><input name="ip_address" class="form-control" value="<?= e($d['ip_address'] ?? '') ?>" <?= $pullOnly ? 'readonly' : '' ?>></div>
  <div class="form-group col-md-4"><label>Location<?= $pullOnly ? ' <small class="text-muted">(from ' . psa_name() . ')</small>' : '' ?></label><input name="location" class="form-control" value="<?= e($d['location'] ?? '') ?>" placeholder="Server closet, 2nd floor…" <?= $pullOnly ? 'readonly' : '' ?>></div>
  <div class="form-group col-md-4"><label>Firmware / version</label><input name="firmware" class="form-control" value="<?= e($d['firmware'] ?? '') ?>"></div>
</div>
<div class="form-row">
  <div class="form-group col-md-8"><label>Operating system <small class="text-muted">(servers/hosts; used for OS support dates)</small></label><input name="os_name" class="form-control" value="<?= e($d['os_name'] ?? '') ?>" placeholder="Windows Server 2022 Standard"></div>
  <div class="form-group col-md-4"><label>OS build</label><input name="os_build" class="form-control" value="<?= e($d['os_build'] ?? '') ?>" placeholder="20348"></div>
</div>
<?php endif; ?>
<h6 class="text-muted text-uppercase small mt-2">Lifecycle</h6>
<div class="form-row">
  <div class="form-group col-md-3"><label>Purchase / in service</label><input type="date" name="purchase_date" class="form-control" value="<?= e($d['o_purchase'] ?? '') ?>"></div>
  <div class="form-group col-md-3"><label>Warranty / support ends</label><input type="date" name="warranty_end" class="form-control" value="<?= e($d['o_warranty'] ?? '') ?>"></div>
  <div class="form-group col-md-3"><label>Replacement cost</label>
    <div class="input-group"><div class="input-group-prepend"><span class="input-group-text"><?= e(\Align\Fmt::symbol()) ?></span></div><input type="number" min="0" step="1" name="replacement_cost" class="form-control" value="<?= e($d['o_cost'] ?? '') ?>" placeholder="policy"></div></div>
  <div class="form-group col-md-3"><label>Lifespan (years)</label><input type="number" min="1" max="29" name="lifespan_years" class="form-control" value="<?= e($d['o_lifespan'] ?? '') ?>" placeholder="policy"></div>
</div>
<?php $choices = \Align\Roadmap\Plan::choices(6); $curRep = $d['o_replace'] ?? null; if ($curRep && !isset($choices[$curRep])) { $choices = [$curRep => (\Align\Roadmap\Plan::quarterFor($curRep)['label'] ?? $curRep) . ' (passed)'] + $choices; } ?>
<div class="form-row">
  <div class="form-group col-md-5"><label>Replace in</label>
    <select name="replace_on" class="custom-select">
      <option value="">Automatic (end of life<?= !empty($d['eol_date']) ? ', ' . e(\Align\Roadmap\Plan::quarterFor($d['eol_date'])['label'] ?? '') : '' ?>)</option>
      <?php foreach ($choices as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel($k, $curRep) ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <small class="text-muted">Pick a quarter when the client wants to replace it earlier or later than its end of life. The roadmap and budget use it.</small></div>
  <div class="form-group col-md-7"><label>Reason <small class="text-muted">(optional)</small></label><input name="replace_note" class="form-control" maxlength="255" value="<?= e($d['o_replace_note'] ?? '') ?>" placeholder="e.g. Client deferred to next budget year"></div>
</div>
<div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?= e($d['o_notes'] ?? '') ?></textarea></div>
<div class="custom-control custom-checkbox">
  <input type="checkbox" class="custom-control-input" id="excluded-<?= (int) ($d['id'] ?? 0) ?>" name="excluded" value="1" <?= !empty($d['o_excluded']) ? 'checked' : '' ?>>
  <label class="custom-control-label font-weight-normal" for="excluded-<?= (int) ($d['id'] ?? 0) ?>">Exclude from lifecycle and budget (spare, lab, client-owned…)</label>
</div>

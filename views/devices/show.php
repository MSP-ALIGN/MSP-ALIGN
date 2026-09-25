<?php
use Align\Auth;

$canEdit = Auth::can('tech');
$ninjaBase = str_contains($ninjaInstance, '://') ? rtrim($ninjaInstance, '/') : 'https://' . $ninjaInstance;
$row = fn(string $k, string $v) => '<div class="kv"><dt>' . e($k) . '</dt><dd>' . $v . '</dd></div>';
?>
<header class="page-head">
  <div>
    <div class="crumbs"><a href="/clients">Clients</a><?php if ($d['client_id']): ?> / <a href="/clients/<?= (int) $d['client_id'] ?>"><?= e($d['client_name']) ?></a><?php endif; ?></div>
    <h1><?= e($d['name']) ?></h1>
    <div><?php require __DIR__ . '/../partials/status.php'; ?><?= $d['removed_at'] ? ' <span class="badge tone-muted">Removed from NinjaOne ' . e(fmt_date($d['removed_at'])) . '</span>' : '' ?></div>
  </div>
  <div class="row">
    <a class="btn" href="<?= e($ninjaBase . '/#/deviceDashboard/' . (int) $d['ninja_device_id'] . '/overview') ?>" target="_blank" rel="noopener">NinjaOne ↗</a>
    <?php if ($d['itflow_asset_id'] && $itflowUrl): ?>
      <a class="btn" href="<?= e(rtrim($itflowUrl, '/') . '/agent/asset.php?client_id=' . (int) $d['itflow_client_id'] . '&asset_id=' . (int) $d['itflow_asset_id']) ?>" target="_blank" rel="noopener">ITFlow asset ↗</a>
    <?php endif; ?>
  </div>
</header>

<div class="grid2">
  <div class="card">
    <h2>Hardware &amp; OS</h2>
    <dl>
      <?= $row('Type', e(ucfirst($d['device_class'])) . ($d['chassis'] ? ' <span class="muted small">(' . e(strtolower($d['chassis'])) . ')</span>' : '')) ?>
      <?= $row('Manufacturer', e($d['manufacturer'] ?? '—')) ?>
      <?= $row('Model', e($d['model'] ?? '—')) ?>
      <?= $row('Serial', e($d['serial'] ?? '—')) ?>
      <?= $row('Operating system', e($d['os_name'] ?? '—') . ($d['os_build'] ? ' <span class="muted small">build ' . e($d['os_build']) . '</span>' : '')) ?>
      <?= $row('OS support ends', $d['os_rule'] ? e(fmt_date($d['os_rule']['eos_date'])) . ' <span class="muted small">(' . e($d['os_rule']['label']) . ')</span>' : '<span class="muted">No matching rule — <a href="/settings/os">OS support dates</a></span>') ?>
      <?= $row('Last check-in', e(rel_time($d['last_contact']))) ?>
      <?= $row('ITFlow asset', $d['itflow_asset_id'] ? 'Linked (#' . (int) $d['itflow_asset_id'] . ')' : '<span class="muted">Not matched by serial or name</span>') ?>
    </dl>
  </div>

  <div class="card">
    <h2>Lifecycle</h2>
    <dl>
      <?= $row('In service since', e(fmt_date($d['start_date'])) . ($d['start_source'] ? ' <span class="muted small">' . e($d['start_source']) . '</span>' : '')) ?>
      <?= $row('Age', $d['age_years'] !== null ? e($d['age_years']) . ' years' : '—') ?>
      <?= $row('Lifespan policy', $d['lifespan'] ? (int) $d['lifespan'] . ' years' : '—') ?>
      <?= $row('End of life', e(fmt_date($d['eol_date']))) ?>
      <?= $row('Warranty ends', e(fmt_date($d['warranty_end'])) . ($d['warranty_source'] ? ' <span class="muted small">' . e($d['warranty_source']) . '</span>' : '')) ?>
      <?= $row('Est. replacement cost', $d['is_hardware'] ? money($d['replacement_cost']) : '—') ?>
      <?php if ($lookup): ?>
        <?= $row('Vendor lookup', e(ucfirst($lookup['vendor'])) . ': ' . e($lookup['status']) . ' · ' . e(rel_time($lookup['looked_up_at'])) . ($lookup['description'] ? '<div class="muted small">' . e($lookup['description']) . '</div>' : '') . ($lookup['message'] ? '<div class="muted small">' . e($lookup['message']) . '</div>' : '')) ?>
      <?php endif; ?>
    </dl>
  </div>
</div>

<div class="card">
  <h2>Overrides</h2>
  <p class="muted small">Values here win over ITFlow and vendor data. Leave blank to use the synced value.</p>
  <form method="post" action="/devices/<?= (int) $d['id'] ?>" class="form-grid">
    <?= csrf_field() ?>
    <label>Purchase / in-service date<input type="date" name="purchase_date" value="<?= e($d['o_purchase']) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
    <label>Warranty end<input type="date" name="warranty_end" value="<?= e($d['o_warranty']) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
    <label>Replacement cost ($)<input type="number" step="1" min="0" name="replacement_cost" value="<?= e($d['o_cost']) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
    <label>Lifespan (years)<input type="number" min="1" max="29" name="lifespan_years" value="<?= e($d['o_lifespan']) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
    <label class="wide">Notes<textarea name="notes" rows="2" <?= $canEdit ? '' : 'disabled' ?>><?= e($d['o_notes']) ?></textarea></label>
    <label class="check wide"><input type="checkbox" name="excluded" value="1" <?= $d['o_excluded'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> Exclude from lifecycle and budget (spare, lab, client-owned, etc.)</label>
    <?php if ($canEdit): ?><div class="wide"><button class="btn primary">Save overrides</button></div><?php endif; ?>
  </form>
</div>

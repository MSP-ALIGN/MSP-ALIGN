<?php
use Align\Integrations\NinjaOne;
use Align\Lifecycle\Lifecycle;

$secret = function (string $name, string $label) use ($secrets) {
    $has = $secrets[$name] ?? false;
    $h = '<div class="form-group"><label>' . e($label) . '</label>'
        . '<input type="password" name="' . e($name) . '" class="form-control" autocomplete="new-password" placeholder="' . ($has ? '•••••••• saved — leave blank to keep' : 'Not set') . '">';
    if ($has) {
        $h .= '<div class="custom-control custom-checkbox mt-1"><input type="checkbox" class="custom-control-input" id="clear_' . e($name) . '" name="clear_' . e($name) . '" value="1">'
            . '<label class="custom-control-label small font-weight-normal" for="clear_' . e($name) . '">Remove saved value</label></div>';
    }
    return $h . '</div>';
};
$num = fn(string $name, string $label, string $prefix = '', string $suffix = '') => '<div class="form-group"><label class="small">' . e($label) . '</label><div class="input-group input-group-sm">'
    . ($prefix ? '<div class="input-group-prepend"><span class="input-group-text">' . e($prefix) . '</span></div>' : '')
    . '<input type="number" step="any" name="' . e($name) . '" class="form-control" value="' . e($v[$name] ?? '') . '">'
    . ($suffix ? '<div class="input-group-append"><span class="input-group-text">' . e($suffix) . '</span></div>' : '') . '</div></div>';
?>
<div class="d-flex mb-3"><h1 class="h3 mb-0 mr-auto">Settings</h1><a class="btn btn-sm btn-default" href="/settings/os"><i class="fab fa-windows mr-1"></i>OS support dates</a></div>

<form method="post" action="/settings">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-lg-6">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-user-ninja mr-2"></i>NinjaOne</h3>
          <div class="card-tools"><button class="btn btn-xs btn-light" form="test-ninja">Test</button></div></div>
        <div class="card-body">
          <p class="small text-muted">Administration → Apps → API → Client app IDs → Add. Platform <b>API Services (machine-to-machine)</b>, scope <b>Monitoring</b>, grant type <b>Client credentials</b>. Align only reads from NinjaOne.</p>
          <div class="form-group"><label>Instance</label>
            <select name="ninja_instance" class="form-control">
              <?php foreach (NinjaOne::INSTANCES as $host => $label): ?><option value="<?= e($host) ?>" <?= $v['ninja_instance'] === $host ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
          <div class="form-group"><label>Client ID</label><input name="ninja_client_id" class="form-control" value="<?= e($v['ninja_client_id']) ?>" autocomplete="off"></div>
          <?= $secret('ninja_client_secret', 'Client secret') ?>
        </div>
      </div>
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-shield-halved mr-2"></i>Warranty lookups</h3>
          <div class="card-tools"><button class="btn btn-xs btn-light" form="test-dell">Test Dell</button> <button class="btn btn-xs btn-light" form="test-lenovo">Test Lenovo</button></div></div>
        <div class="card-body">
          <p class="small text-muted">Optional. Dell keys come from TechDirect (Warranty API); Lenovo issues a ClientID token to partners. Other brands use the ITFlow warranty date or a manual entry.</p>
          <div class="form-row">
            <div class="form-group col-md-6"><label>Dell client ID</label><input name="dell_client_id" class="form-control" value="<?= e($v['dell_client_id']) ?>" autocomplete="off"></div>
            <div class="col-md-6"><?= $secret('dell_client_secret', 'Dell client secret') ?></div>
          </div>
          <div class="form-row">
            <div class="col-md-6"><?= $secret('lenovo_client_id', 'Lenovo ClientID') ?></div>
            <div class="col-md-6"><?= $num('warranty_recheck_days', 'Re-check warranties every', '', 'days') ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-screwdriver-wrench mr-2"></i>ITFlow</h3>
          <div class="card-tools"><button class="btn btn-xs btn-light" form="test-itflow">Test</button></div></div>
        <div class="card-body">
          <p class="small text-muted">Admin → API Keys → Create. The key runs as the ITFlow user you choose, so that user needs read access to Clients and Support (assets). It also needs write access to Support if you turn on write-back.</p>
          <div class="form-group"><label>ITFlow URL</label><input type="url" name="itflow_url" class="form-control" value="<?= e($v['itflow_url']) ?>" placeholder="https://itflow.example.com"></div>
          <?= $secret('itflow_api_key', 'API key') ?>
          <div class="form-group"><label>Bring in ITFlow assets that NinjaOne doesn't manage</label>
            <input type="hidden" name="itflow_import_present" value="1">
            <?php $imp = array_filter(explode(',', (string) ($v['itflow_import_types'] ?? ''))); ?>
            <div class="row small">
              <?php foreach (\Align\Integrations\Itflow::IMPORT_CATEGORIES as $k => $label): ?>
                <div class="col-sm-6"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="imp-<?= $k ?>" name="itflow_import[]" value="<?= $k ?>" <?= in_array($k, $imp, true) ? 'checked' : '' ?>><label class="custom-control-label font-weight-normal" for="imp-<?= $k ?>"><?= e($label) ?></label></div></div>
              <?php endforeach; ?>
            </div>
            <small class="text-muted">Assets already matched to a NinjaOne or hand-added device (by serial or name) are skipped, so nothing is counted twice. UPS units are recognized by make/model (APC, CyberPower, Eaton, Tripp Lite, Vertiv…) even when typed "Other".</small>
          </div>
          <div class="form-group mb-0"><label>Write warranty dates back to ITFlow assets</label>
            <select name="itflow_writeback" class="form-control">
              <option value="off" <?= $v['itflow_writeback'] === 'off' ? 'selected' : '' ?>>Off — read only</option>
              <option value="fill_empty" <?= $v['itflow_writeback'] === 'fill_empty' ? 'selected' : '' ?>>Fill empty fields only</option>
              <option value="overwrite" <?= $v['itflow_writeback'] === 'overwrite' ? 'selected' : '' ?>>Keep ITFlow in sync (overwrite)</option>
            </select></div>
        </div>
      </div>
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-handshake mr-2"></i>Meetings &amp; flags</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="col-md-6"><?= $num('meeting_default_minutes', 'Default meeting length', '', 'min') ?></div>
            <div class="col-md-6"><?= $num('eol_plan_months', 'Flag "plan replacement" within', '', 'months') ?></div>
            <div class="col-md-6"><?= $num('warranty_warn_days', 'Flag warranty expiring within', '', 'days') ?></div>
            <div class="col-md-6"><?= $num('stale_days', 'Mark stale after no check-in for', '', 'days') ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-6">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-road mr-2"></i>3-year plan</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group col-md-6"><label>Budget year starts in</label>
              <select name="fiscal_year_start" class="form-control"><option value="1" <?= (int) ($v['fiscal_year_start'] ?? 1) === 1 ? 'selected' : '' ?>>January</option><option value="2" <?= (int) ($v['fiscal_year_start'] ?? 1) === 2 ? 'selected' : '' ?>>February</option><option value="3" <?= (int) ($v['fiscal_year_start'] ?? 1) === 3 ? 'selected' : '' ?>>March</option><option value="4" <?= (int) ($v['fiscal_year_start'] ?? 1) === 4 ? 'selected' : '' ?>>April</option><option value="5" <?= (int) ($v['fiscal_year_start'] ?? 1) === 5 ? 'selected' : '' ?>>May</option><option value="6" <?= (int) ($v['fiscal_year_start'] ?? 1) === 6 ? 'selected' : '' ?>>June</option><option value="7" <?= (int) ($v['fiscal_year_start'] ?? 1) === 7 ? 'selected' : '' ?>>July</option><option value="8" <?= (int) ($v['fiscal_year_start'] ?? 1) === 8 ? 'selected' : '' ?>>August</option><option value="9" <?= (int) ($v['fiscal_year_start'] ?? 1) === 9 ? 'selected' : '' ?>>September</option><option value="10" <?= (int) ($v['fiscal_year_start'] ?? 1) === 10 ? 'selected' : '' ?>>October</option><option value="11" <?= (int) ($v['fiscal_year_start'] ?? 1) === 11 ? 'selected' : '' ?>>November</option><option value="12" <?= (int) ($v['fiscal_year_start'] ?? 1) === 12 ? 'selected' : '' ?>>December</option></select>
              <small class="text-muted">January = calendar years (Q1 2027). Any other month uses fiscal years (FY2027 Q1).</small></div>
            <div class="form-group col-md-6"><label>Plan begins with</label>
              <select name="plan_start" class="form-control">
                <option value="current" <?= ($v['plan_start'] ?? 'current') === 'current' ? 'selected' : '' ?>>This budget year</option>
                <option value="next" <?= ($v['plan_start'] ?? '') === 'next' ? 'selected' : '' ?>>Next budget year</option>
              </select>
              <small class="text-muted">Use "next" when you're planning next year's budget late in the year. Overdue items land in the first quarter shown.</small></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-print mr-2"></i>Report branding</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group col-md-6"><label>Company name</label><input name="company_name" class="form-control" value="<?= e($v['company_name']) ?>"></div>
            <div class="form-group col-md-6"><label>Phone</label><input name="company_phone" class="form-control" value="<?= e($v['company_phone']) ?>"></div>
            <div class="form-group col-md-6"><label>Email</label><input name="company_email" class="form-control" value="<?= e($v['company_email']) ?>"></div>
            <div class="form-group col-md-6"><label>Website</label><input name="company_website" class="form-control" value="<?= e($v['company_website']) ?>"></div>
          </div>
          <div class="form-group mb-0"><label>Report footer</label><textarea name="report_footer" class="form-control" rows="2"><?= e($v['report_footer']) ?></textarea></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-recycle mr-2"></i>Lifecycle policy</h3></div>
    <div class="card-body">
      <p class="small text-muted">Used for end-of-life dates and the replacement budget. Per-device values override these.</p>
      <div class="table-responsive">
        <table class="table table-sm table-borderless mb-0">
          <thead class="text-dark"><tr><th>Category</th><th>Lifespan</th><th>Replacement cost</th><th class="small text-muted font-weight-normal">Device types</th></tr></thead>
          <tbody>
          <?php foreach (Lifecycle::CLASSES as $class => $label):
              $types = array_map(fn($k) => Lifecycle::TYPES[$k][2] ? "$k (OS only)" : $k, array_keys(array_filter(Lifecycle::TYPES, fn($t) => $t[0] === $class))); ?>
            <tr>
              <td class="align-middle font-weight-bold"><?= e($label) ?></td>
              <td class="w-25"><div class="input-group input-group-sm"><input type="number" name="lifespan_<?= $class ?>" class="form-control" value="<?= e($v["lifespan_$class"] ?? '') ?>"><div class="input-group-append"><span class="input-group-text">yrs</span></div></div></td>
              <td class="w-25"><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text">$</span></div><input type="number" name="cost_<?= $class ?>" class="form-control" value="<?= e($v["cost_$class"] ?? '') ?>"></div></td>
              <td class="small text-muted align-middle"><?= e(implode(', ', $types)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save settings</button>
  <span class="small text-muted ml-2">Save before testing — Test uses the saved values.</span>
</form>
<?php foreach (['ninja', 'itflow', 'dell', 'lenovo'] as $t): ?>
  <form method="post" action="/settings/test" id="test-<?= $t ?>" class="d-none"><?= csrf_field() ?><input type="hidden" name="target" value="<?= $t ?>"></form>
<?php endforeach; ?>

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

  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-recycle mr-2"></i>Lifecycle policy</h3></div>
    <div class="card-body">
      <p class="small text-muted">Used for end-of-life dates and the replacement budget. Per-device values override these.</p>
      <div class="table-responsive">
        <table class="table table-sm table-borderless mb-0">
          <thead class="text-dark"><tr><th>Category</th><th>Lifespan</th><th>Replacement cost</th><th class="small text-muted font-weight-normal">Device types</th></tr></thead>
          <tbody>
          <?php foreach (Lifecycle::CLASSES as $class => $label):
              $types = array_keys(array_filter(Lifecycle::TYPES, fn($t) => $t[0] === $class)); ?>
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

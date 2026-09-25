<?php
use Align\Integrations\NinjaOne;

$secret = function (string $name, string $label, string $help = '') use ($secrets) {
    $has = $secrets[$name] ?? false;
    $h = '<label>' . e($label)
        . '<input type="password" name="' . e($name) . '" autocomplete="new-password" placeholder="' . ($has ? '•••••••• saved — leave blank to keep' : 'Not set') . '">';
    if ($help) {
        $h .= '<span class="hint">' . $help . '</span>';
    }
    $h .= '</label>';
    if ($has) {
        $h .= '<label class="check"><input type="checkbox" name="clear_' . e($name) . '" value="1"> Remove saved value</label>';
    }
    return $h;
};
$num = fn(string $name, string $label, string $suffix = '') => '<label>' . e($label)
    . '<span class="with-suffix"><input type="number" step="any" name="' . e($name) . '" value="' . e($v[$name]) . '">'
    . ($suffix ? '<span>' . e($suffix) . '</span>' : '') . '</span></label>';
?>
<header class="page-head">
  <h1>Settings</h1>
  <a class="btn" href="/settings/os">OS support dates</a>
</header>

<form method="post" action="/settings" id="settings-form">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card-head"><h2>NinjaOne</h2><button class="btn small" form="test-ninja">Test</button></div>
    <p class="muted small">Administration → Apps → API → Client app IDs → Add. Platform: <b>API Services (machine-to-machine)</b>, scope <b>Monitoring</b>, grant type <b>Client credentials</b>. Align only reads from NinjaOne.</p>
    <div class="form-grid">
      <label>Instance
        <select name="ninja_instance">
          <?php foreach (NinjaOne::INSTANCES as $host => $label): ?>
            <option value="<?= e($host) ?>" <?= $v['ninja_instance'] === $host ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Client ID<input name="ninja_client_id" value="<?= e($v['ninja_client_id']) ?>" autocomplete="off"></label>
      <?= $secret('ninja_client_secret', 'Client secret') ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>ITFlow</h2><button class="btn small" form="test-itflow">Test</button></div>
    <p class="muted small">In ITFlow: Admin → API Keys → Create. The key runs as the ITFlow user you pick, so that user needs read access to Clients and Support (assets), and write access to Support if you turn on write-back below.</p>
    <div class="form-grid">
      <label>ITFlow URL<input type="url" name="itflow_url" value="<?= e($v['itflow_url']) ?>" placeholder="https://itflow.example.com"></label>
      <?= $secret('itflow_api_key', 'API key') ?>
      <label>Write warranty dates back to ITFlow assets
        <select name="itflow_writeback">
          <option value="off" <?= $v['itflow_writeback'] === 'off' ? 'selected' : '' ?>>Off — read only</option>
          <option value="fill_empty" <?= $v['itflow_writeback'] === 'fill_empty' ? 'selected' : '' ?>>Fill empty fields only</option>
          <option value="overwrite" <?= $v['itflow_writeback'] === 'overwrite' ? 'selected' : '' ?>>Keep ITFlow in sync (overwrite)</option>
        </select>
        <span class="hint">Updates warranty expiry and purchase date on matched ITFlow assets.</span>
      </label>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Warranty lookups</h2>
      <span><button class="btn small" form="test-dell">Test Dell</button> <button class="btn small" form="test-lenovo">Test Lenovo</button></span></div>
    <p class="muted small">Optional. Serial numbers come from NinjaOne. Dell keys come from TechDirect (Warranty API); Lenovo issues a ClientID token to partners. Other brands use the ITFlow warranty date or a manual override.</p>
    <div class="form-grid">
      <label>Dell client ID<input name="dell_client_id" value="<?= e($v['dell_client_id']) ?>" autocomplete="off"></label>
      <?= $secret('dell_client_secret', 'Dell client secret') ?>
      <?= $secret('lenovo_client_id', 'Lenovo ClientID') ?>
      <?= $num('warranty_recheck_days', 'Re-check warranties every', 'days') ?>
    </div>
  </div>

  <div class="card">
    <h2>Lifecycle policy</h2>
    <p class="muted small">Used to calculate end-of-life dates and the replacement budget. Per-device overrides win.</p>
    <div class="form-grid four">
      <?= $num('lifespan_desktop', 'Desktop lifespan', 'yrs') ?>
      <?= $num('lifespan_laptop', 'Laptop lifespan', 'yrs') ?>
      <?= $num('lifespan_server', 'Server lifespan', 'yrs') ?>
      <?= $num('lifespan_network', 'Network lifespan', 'yrs') ?>
      <?= $num('cost_desktop', 'Desktop replacement', '$') ?>
      <?= $num('cost_laptop', 'Laptop replacement', '$') ?>
      <?= $num('cost_server', 'Server replacement', '$') ?>
      <?= $num('cost_network', 'Network replacement', '$') ?>
      <?= $num('eol_plan_months', 'Flag "plan replacement" within', 'months') ?>
      <?= $num('warranty_warn_days', 'Flag warranty expiring within', 'days') ?>
      <?= $num('stale_days', 'Mark stale after no check-in for', 'days') ?>
    </div>
  </div>

  <p><button class="btn primary">Save settings</button></p>
</form>

<?php foreach (['ninja', 'itflow', 'dell', 'lenovo'] as $t): ?>
  <form method="post" action="/settings/test" id="test-<?= $t ?>" class="hidden"><?= csrf_field() ?><input type="hidden" name="target" value="<?= $t ?>"></form>
<?php endforeach; ?>
<p class="muted small">Save your changes before testing — Test uses the saved values.</p>

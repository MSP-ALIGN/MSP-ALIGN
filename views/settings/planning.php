<?php
use Align\Lifecycle\Lifecycle;

$num = fn(string $name, string $label, string $prefix = '', string $suffix = '') => '<div class="form-group"><label class="small">' . e($label) . '</label><div class="input-group input-group-sm">'
    . ($prefix ? '<div class="input-group-prepend"><span class="input-group-text">' . e($prefix) . '</span></div>' : '')
    . '<input type="number" step="any" name="' . e($name) . '" class="form-control" value="' . e($v[$name] ?? '') . '">'
    . ($suffix ? '<div class="input-group-append"><span class="input-group-text">' . e($suffix) . '</span></div>' : '') . '</div></div>';
?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'planning']) ?>
<form method="post" action="/settings">
  <?= csrf_field() ?>
  <input type="hidden" name="_tab" value="planning">
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
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-handshake mr-2"></i>Meetings, flags &amp; warranties</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="col-md-6"><?= $num('meeting_default_minutes', 'Default meeting length', '', 'min') ?></div>
            <div class="col-md-6"><?= $num('eol_plan_months', 'Flag "plan replacement" within', '', 'months') ?></div>
            <div class="col-md-6"><?= $num('warranty_warn_days', 'Flag warranty expiring within', '', 'days') ?></div>
            <div class="col-md-6"><?= $num('stale_days', 'Mark stale after no check-in for', '', 'days') ?></div>
            <div class="col-md-6"><?= $num('warranty_recheck_days', 'Re-check vendor warranties every', '', 'days') ?></div>
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
  <button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save</button>
  <span class="small text-muted ml-2">Windows versions and their end-of-support dates are under <a href="/settings/os">OS support dates</a>.</span>
</form>

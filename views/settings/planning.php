<?php
/**
 * Settings → Planning & lifecycle (admins). @var array $v saved values.
 * Security: every value is escaped; the per-class device counts in the confirm texts are integers from the database.
 */
use Align\Lifecycle\Lifecycle;

$num = fn(string $name, string $label, string $prefix = '', string $suffix = '') => '<div class="mb-3"><label class="small">' . e($label) . '</label><div class="input-group input-group-sm">'
    . ($prefix ? '<span class="input-group-text">' . e($prefix) . '</span>' : '')
    . '<input type="number" step="any" name="' . e($name) . '" class="form-control" value="' . e($v[$name] ?? '') . '">'
    . ($suffix ? '<span class="input-group-text">' . e($suffix) . '</span>' : '') . '</div></div>';
?>
<?php
// Changes here recalculate plans for many devices: say how many before saving (2.0.1)
$perClass = [];
foreach (\Align\DB::all("SELECT COALESCE(NULLIF(o.device_type, ''), d.device_type) AS t, COUNT(*) AS n FROM devices d
    JOIN clients c ON c.id = d.client_id LEFT JOIN device_overrides o ON o.device_id = d.id
    WHERE d.removed_at IS NULL AND d.retired_at IS NULL AND c.is_archived = 0 AND c.planning_excluded = 0 AND (o.device_id IS NULL OR o.excluded = 0) GROUP BY t") as $r) {
    $cl = Lifecycle::types()[(string) $r['t']][0] ?? null;
    if ($cl !== null) {
        $perClass[$cl] = ($perClass[$cl] ?? 0) + (int) $r['n'];
    }
}
$rules = [['changed' => 'fiscal_year_start,plan_start', 'title' => 'Change the planning settings?', 'ok' => 'Save',
    'text' => 'Every client\'s roadmap and budget is laid out on the new quarters.']];
foreach (Lifecycle::CLASSES as $class => $label) {
    $n = $perClass[$class] ?? 0;
    $rules[] = ['changed' => "lifespan_$class,cost_$class", 'title' => 'Change the lifecycle policy?', 'ok' => 'Save',
        'text' => "$label: end-of-life dates and replacement costs are recalculated for " . ($n === 1 ? '1 device' : "$n devices") . ' (except ones with their own values).'];
}
?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'planning']) ?>
<form method="post" action="/settings" data-unsaved data-confirm-rules="<?= e(json_encode($rules)) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="_tab" value="planning">
  <div class="row">
    <div class="col-lg-6">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-road me-2"></i>3-year plan</h3></div>
        <div class="card-body">
          <div class="row g-2">
            <div class="mb-3 col-md-6"><label>Budget year starts in</label>
              <select name="fiscal_year_start" class="form-select"><option value="1" <?= (int) ($v['fiscal_year_start'] ?? 1) === 1 ? 'selected' : '' ?>>January</option><option value="2" <?= (int) ($v['fiscal_year_start'] ?? 1) === 2 ? 'selected' : '' ?>>February</option><option value="3" <?= (int) ($v['fiscal_year_start'] ?? 1) === 3 ? 'selected' : '' ?>>March</option><option value="4" <?= (int) ($v['fiscal_year_start'] ?? 1) === 4 ? 'selected' : '' ?>>April</option><option value="5" <?= (int) ($v['fiscal_year_start'] ?? 1) === 5 ? 'selected' : '' ?>>May</option><option value="6" <?= (int) ($v['fiscal_year_start'] ?? 1) === 6 ? 'selected' : '' ?>>June</option><option value="7" <?= (int) ($v['fiscal_year_start'] ?? 1) === 7 ? 'selected' : '' ?>>July</option><option value="8" <?= (int) ($v['fiscal_year_start'] ?? 1) === 8 ? 'selected' : '' ?>>August</option><option value="9" <?= (int) ($v['fiscal_year_start'] ?? 1) === 9 ? 'selected' : '' ?>>September</option><option value="10" <?= (int) ($v['fiscal_year_start'] ?? 1) === 10 ? 'selected' : '' ?>>October</option><option value="11" <?= (int) ($v['fiscal_year_start'] ?? 1) === 11 ? 'selected' : '' ?>>November</option><option value="12" <?= (int) ($v['fiscal_year_start'] ?? 1) === 12 ? 'selected' : '' ?>>December</option></select>
              <small class="text-muted">January = calendar years (Q1 2027). Any other month uses fiscal years (FY2027 Q1).</small></div>
            <div class="mb-3 col-md-6"><label>Plan begins with</label>
              <select name="plan_start" class="form-select">
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
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-handshake me-2"></i>Meetings, flags &amp; warranties</h3></div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-md-6"><?= $num('meeting_default_minutes', 'Default meeting length', '', 'min') ?></div>
            <div class="col-md-6"><?= $num('eol_plan_months', 'Flag "plan replacement" within', '', 'months') ?></div>
            <div class="col-md-6"><?= $num('warranty_warn_days', 'Flag warranty expiring within', '', 'days') ?></div>
            <div class="col-md-6"><?= $num('stale_days', 'Mark stale after no check-in for', '', 'days') ?></div>
            <div class="col-md-6"><?= $num('warranty_recheck_days', 'Re-check vendor warranties every', '', 'days') ?></div>
            <div class="col-12"><input type="hidden" name="spares_in_seats_present" value="1">
              <div class="form-check"><input class="form-check-input" type="checkbox" name="spares_in_seats" value="1" id="spares-in-seats"<?= \Align\Contracts\Contracts::sparesAreSeats() ? ' checked' : '' ?>>
                <label class="form-check-label fw-normal" for="spares-in-seats">Count spare devices (tech closet) in contract seat counts <span class="small text-muted d-block">Untick if you don't bill for computers on the shelf.</span></label></div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-recycle me-2"></i>Lifecycle policy</h3></div>
    <div class="card-body">
      <p class="small text-muted">Used for end-of-life dates and the replacement budget. Per-device values override these.</p>
      <div class="table-responsive">
        <table class="table table-sm table-borderless mb-0">
          <thead class="text-dark"><tr><th>Category</th><th>Lifespan</th><th>Replacement cost</th><th class="small text-muted fw-normal">Device types</th></tr></thead>
          <tbody>
          <?php foreach (Lifecycle::CLASSES as $class => $label):
              $types = array_map(fn($k) => Lifecycle::types()[$k][2] ? "$k (OS only)" : $k, array_keys(array_filter(Lifecycle::types(), fn($t) => $t[0] === $class))); ?>
            <tr>
              <td class="align-middle fw-bold"><?= e($label) ?></td>
              <td class="w-25"><div class="input-group input-group-sm"><input type="number" name="lifespan_<?= $class ?>" class="form-control" value="<?= e($v["lifespan_$class"] ?? '') ?>"><span class="input-group-text">yrs</span></div></td>
              <td class="w-25"><div class="input-group input-group-sm"><span class="input-group-text"><?= e(\Align\Fmt::symbol()) ?></span><input type="number" name="cost_<?= $class ?>" class="form-control" value="<?= e($v["cost_$class"] ?? '') ?>"></div></td>
              <td class="small text-muted align-middle"><?= e(implode(', ', $types)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
  <span class="small text-muted ms-2">Windows versions and their end-of-support dates are under <a href="/settings/os">OS support dates</a>; the health score is under <a href="/settings/checks">Health &amp; checks</a>.</span>
</form>

<?php
// 2.25.0 Asset types an admin adds (AssetTypeController; Align\Lifecycle\AssetTypes). Every value is escaped; the
// category and icon lists are fixed in code.
$own = \Align\Lifecycle\AssetTypes::all();
$classSelect = function (string $sel): string {
    $o = '';
    foreach (Lifecycle::CLASSES as $k => $l) {
        $o .= '<option value="' . e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $o;
};
$iconSelect = function (string $sel): string {
    $o = '';
    foreach (\Align\Lifecycle\AssetTypes::ICONS as $k => $l) {
        $o .= '<option value="' . e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $o;
};
?>
<div class="card card-dark mt-3" id="asset-types">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-tags me-2"></i>Your own asset types</h3></div>
  <div class="card-body">
    <p class="small text-muted">Add a type for anything the built-in ones don't cover (tablets, scanners, medical devices…). It plans under the
      category you pick, using that category's lifespan and replacement cost above, and shows in every device's type list.
      A <?= e(psa_name()) ?> asset whose type has exactly the same name comes in as this type. Built-in types are listed in the table above.</p>
    <?php if ($own): ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-3">
        <thead><tr><th style="width:2rem"></th><th>Name</th><th>Category</th><th>Icon</th><th>Virtual</th><th class="text-end">Devices</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($own as $t): $fid = 'at-' . (int) $t['id']; ?>
          <tr>
            <td><i class="fas fa-fw <?= e(isset(\Align\Lifecycle\AssetTypes::ICONS[$t['icon']]) ? $t['icon'] : 'fa-tag') ?> text-secondary" aria-hidden="true"></i></td>
            <td><input form="<?= $fid ?>" name="name" class="form-control form-control-sm" maxlength="<?= \Align\Lifecycle\AssetTypes::MAX_NAME ?>" value="<?= e($t['name']) ?>" required aria-label="Name"></td>
            <td><select form="<?= $fid ?>" name="class" class="form-select form-select-sm" aria-label="Category"><?= $classSelect((string) $t['class']) ?></select></td>
            <td><select form="<?= $fid ?>" name="icon" class="form-select form-select-sm" aria-label="Icon"><?= $iconSelect((string) $t['icon']) ?></select></td>
            <td><input form="<?= $fid ?>" type="checkbox" class="form-check-input" name="is_virtual" value="1"<?= $t['is_virtual'] ? ' checked' : '' ?> aria-label="Virtual (operating system only)"></td>
            <td class="text-end"><?= (int) $t['devices'] ?></td>
            <td class="text-end text-nowrap">
              <form method="post" action="/settings/asset-types/<?= (int) $t['id'] ?>" id="<?= $fid ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-default">Save</button></form>
              <form method="post" action="/settings/asset-types/<?= (int) $t['id'] ?>/delete" class="d-inline"><?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-danger" data-confirm="<?= e('Remove "' . $t['name'] . '"?' . ($t['devices'] ? ' Its ' . (int) $t['devices'] . ' device' . ((int) $t['devices'] === 1 ? '' : 's') . ' go back to their own type, or to Unassigned to sort again.' : '')) ?>" data-confirm-ok="Remove">Remove</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <form method="post" action="/settings/asset-types" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <div class="col-md-4"><label class="small" for="at-new-name">New type</label><input id="at-new-name" name="name" class="form-control form-control-sm" maxlength="<?= \Align\Lifecycle\AssetTypes::MAX_NAME ?>" placeholder="e.g. Tablet" required></div>
      <div class="col-md-3"><label class="small" for="at-new-class">Plans under</label><select id="at-new-class" name="class" class="form-select form-select-sm"><?= $classSelect('other') ?></select></div>
      <div class="col-md-2"><label class="small" for="at-new-icon">Icon</label><select id="at-new-icon" name="icon" class="form-select form-select-sm"><?= $iconSelect('fa-tag') ?></select></div>
      <div class="col-md-2"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="is_virtual" value="1" id="at-new-virtual"><label class="form-check-label small" for="at-new-virtual">Virtual (OS only)</label></div></div>
      <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div>
    </form>
  </div>
</div>

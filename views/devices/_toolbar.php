<?php
/**
 * Device list toolbar (1.42): 7 view tabs, then search, Type and More menus, Columns and CSV.
 * @var string $base (list path); string $filter; string $class; string $q; bool $bkOn; ?string $export (CSV link); ?array $counts (by view key)
 * @var array $keep query parameters every link keeps (the client picked on the all-clients list); string[] $extraMenus HTML before Type
 * @var array $dfilters active Filters panel values (DeviceFilters::fromQuery); array $dopts its choices (DeviceFilters::options)
 * $filter, $class, $q and $dfilters come from the query string: they only go into escaped, URL-encoded links, form
 * values and comparisons. 2.2.2: the Filters button opens a panel (a GET form, no script); active filters show as
 * chips that each remove one.
 */
use Align\Lifecycle\DeviceFilters;
use Align\Lifecycle\Lifecycle;

$views = ['' => 'All', 'attention' => 'Needs attention', 'replace' => 'Replace / plan', 'os' => 'OS support', 'warranty' => 'Warranty', 'stale' => 'Stale', 'unassigned' => 'Unassigned'];
$more = ['virtual' => 'Virtual', ...(psa_on() ? ['psa' => 'From ' . psa_name()] : []), 'manual' => 'Added by hand', 'noplan' => 'No in-service date'];
$counts = $counts ?? [];
$keep = $keep ?? [];
$df = $dfilters ?? [];
$dopts = $dopts ?? [];
$link = fn(array $over) => $base . (($qs = http_build_query(array_filter(array_merge($keep, ['filter' => $filter, 'class' => $class, 'q' => $q], DeviceFilters::query($df), $over), fn($v) => $v !== '' && $v !== null))) ? "?$qs" : '');
$tabs = [];
foreach ($views as $k => $label) {
    $tabs[] = [$label, $link(['filter' => $k]), $filter === $k, $counts[$k] ?? null];
}
$menu = function (string $label, array $items, string $param, string $current, string $allLabel) use ($link): string {
    $active = $current !== '' && isset($items[$current]);
    $h = '<div class="btn-group"><button class="btn btn-sm ' . ($active ? 'btn-secondary' : 'btn-default') . ' dropdown-toggle" data-bs-toggle="dropdown">' . e($active ? $items[$current] : $label) . '</button><div class="dropdown-menu dropdown-menu-end">';
    $h .= '<a class="dropdown-item' . (!$active ? ' active' : '') . '" href="' . e($link([$param => ''])) . '">' . e($allLabel) . '</a><div class="dropdown-divider"></div>';
    foreach ($items as $k => $l) {
        $h .= '<a class="dropdown-item' . ($current === $k ? ' active' : '') . '" href="' . e($link([$param => $k])) . '">' . e($l) . '</a>';
    }
    return $h . '</div></div>';
};
$optCols = ['type' => 'Type', ...($bkOn ? ['backup' => 'Backup'] : []), 'serial' => 'Serial', 'warranty' => 'Warranty', 'cost' => 'Est. cost'];
$colsMenu = '<div class="btn-group"><button class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-table-columns me-1"></i>Columns</button>'
    . '<div class="dropdown-menu dropdown-menu-end px-3 py-2" data-columns="device-table"><div class="small text-muted mb-1">Also show</div>';
foreach ($optCols as $k => $l) {
    $colsMenu .= '<div class="form-check "><input type="checkbox" class="form-check-input" id="col-' . $k . '" data-col="' . $k . '"><label class="form-check-label fw-normal" for="col-' . $k . '">' . e($l) . '</label></div>';
}
$colsMenu .= '</div></div>';
$filtersBtn = $dopts || $df ? '<button type="button" class="btn btn-sm ' . ($df ? 'btn-secondary' : 'btn-default') . '" data-bs-toggle="collapse" data-bs-target="#device-filters" aria-expanded="false" aria-controls="device-filters">'
    . '<i class="fas fa-filter me-1"></i>Filters' . ($df ? ' <span class="badge text-bg-light ms-1">' . count($df) . '</span>' : '') . '</button>' : '';
$menus = [
    ...($extraMenus ?? []),
    ...($filtersBtn !== '' ? [$filtersBtn] : []),
    $menu('Type', Lifecycle::CLASSES, 'class', $class, 'All types'),
    // "More" holds the views that used to be buttons; a view picked here keeps the tab row on All
    str_replace('dropdown-toggle"', 'dropdown-toggle" title="More views"', $menu('More', $more, 'filter', isset($more[$filter]) ? $filter : '', 'Any source')),
    $colsMenu,
    ...(!empty($export) ? ['<a class="btn btn-sm btn-default" href="' . e($export) . '" title="Download as CSV"><i class="fas fa-file-csv"></i><span class="visually-hidden">CSV</span></a>'] : []),
];
echo \Align\View::fetch('partials/toolbar', [
    'tabs' => $tabs,
    'search' => ['action' => $base, 'value' => $q, 'hidden' => $keep + ['filter' => $filter, 'class' => $class] + DeviceFilters::query($df), 'table' => 'device-table', 'placeholder' => 'Search name, serial, user, model'],
    'menus' => $menus,
]);
if ($dopts || $df): ?>
  <div class="collapse border-bottom" id="device-filters">
    <form method="get" action="<?= e($base) ?>" class="card-body py-3" data-device-filters>
      <?php foreach (array_filter($keep + ['filter' => $filter, 'class' => $class, 'q' => $q], fn($v) => $v !== '' && $v !== null) as $hk => $hv): ?><input type="hidden" name="<?= e((string) $hk) ?>" value="<?= e((string) $hv) ?>"><?php endforeach; ?>
      <div class="row g-2">
        <?php foreach (DeviceFilters::KEYS as $k => $label):
            if ($k === 'model' && !isset($df['make'])) continue;
            $choices = $dopts[$k] ?? [];
            if (isset($df[$k]) && !isset($choices[$df[$k]])) $choices[$df[$k]] = DeviceFilters::label($k, $df[$k]) . ' (0)'; // a value from an old link stays visible
            if (!$choices) continue; ?>
          <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1" for="df-<?= e($k) ?>"><?= e($label) ?></label>
            <select class="form-select form-select-sm" name="<?= e($k) ?>" id="df-<?= e($k) ?>">
              <option value="">Any</option>
              <?php foreach ($choices as $v => $l): ?><option value="<?= e((string) $v) ?>"<?= isset($df[$k]) && $df[$k] === (string) $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
        <?php if (!isset($df['make']) && !empty($dopts['make'])): ?><div class="col-sm-6 col-lg-3 small text-muted d-flex align-items-end pb-1">Pick a make to filter by model too.</div><?php endif; ?>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <button class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Apply filters</button>
        <?php if ($df): ?><a class="btn btn-sm btn-light" href="<?= e($link(array_fill_keys(array_keys($df), ''))) ?>">Clear filters</a><?php endif; ?>
      </div>
    </form>
  </div>
  <?php if ($df): ?>
    <div class="card-body py-2 border-bottom d-flex flex-wrap gap-2 align-items-center small" data-filter-chips>
      <span class="text-muted me-1">Filtered by</span>
      <?php foreach ($df as $k => $v): $chip = DeviceFilters::KEYS[$k] . ': ' . preg_replace('/ \([\d,]+\)$/', '', $dopts[$k][$v] ?? DeviceFilters::label($k, $v)); ?>
        <a class="badge rounded-pill text-bg-light border text-decoration-none fw-normal" href="<?= e($link([$k => '', ...($k === 'make' ? ['model' => ''] : [])])) ?>" aria-label="<?= e('Remove filter ' . $chip) ?>"><?= e($chip) ?><i class="fas fa-xmark ms-2"></i></a>
      <?php endforeach; ?>
      <a class="ms-1" href="<?= e($link(array_fill_keys(array_keys($df), ''))) ?>">Clear all</a>
    </div>
  <?php endif;
endif;

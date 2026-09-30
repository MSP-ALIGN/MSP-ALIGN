<?php
/**
 * Device list toolbar (1.42): 7 view tabs, then search, Type and More menus, Columns and CSV.
 * @var string $base (list path); string $filter; string $class; string $q; bool $bkOn; ?string $export (CSV link); ?array $counts (by view key)
 * @var array $keep query parameters every link keeps (the client picked on the all-clients list); string[] $extraMenus HTML before Type
 */
use Align\Lifecycle\Lifecycle;

$views = ['' => 'All', 'attention' => 'Needs attention', 'replace' => 'Replace / plan', 'os' => 'OS support', 'warranty' => 'Warranty', 'stale' => 'Stale', 'unassigned' => 'Unassigned'];
$more = ['virtual' => 'Virtual', ...(psa_on() ? ['psa' => 'From ' . psa_name()] : []), 'manual' => 'Added by hand', 'noplan' => 'No in-service date'];
$counts = $counts ?? [];
$keep = $keep ?? [];
$link = fn(array $over) => $base . (($qs = http_build_query(array_filter(array_merge($keep, ['filter' => $filter, 'class' => $class, 'q' => $q], $over), fn($v) => $v !== '' && $v !== null))) ? "?$qs" : '');
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
$menus = [
    ...($extraMenus ?? []),
    $menu('Type', Lifecycle::CLASSES, 'class', $class, 'All types'),
    // "More" holds the views that used to be buttons; a view picked here keeps the tab row on All
    str_replace('dropdown-toggle"', 'dropdown-toggle" title="More views"', $menu('More', $more, 'filter', isset($more[$filter]) ? $filter : '', 'Any source')),
    $colsMenu,
    ...(!empty($export) ? ['<a class="btn btn-sm btn-default" href="' . e($export) . '" title="Download as CSV"><i class="fas fa-file-csv"></i><span class="visually-hidden">CSV</span></a>'] : []),
];
echo \Align\View::fetch('partials/toolbar', [
    'tabs' => $tabs,
    'search' => ['action' => $base, 'value' => $q, 'hidden' => $keep + ['filter' => $filter, 'class' => $class], 'table' => 'device-table', 'placeholder' => 'Search name, serial, user, model'],
    'menus' => $menus,
]);

<?php
/**
 * Every client's devices (1.42). @var array $devices (this page); int $matched; int $total; int $limit; string $q, $filter, $class; int $clientId; array $clients, $tiles, $counts, $dfilters, $dopts (Filters panel)
 * Query values only go into escaped, URL-encoded links; client names are escaped.
 */
$base = '/devices';
// What the Client menu's links keep (2.2.2: the Filters panel values too)
$keep = array_filter(['client' => $clientId ?: '', 'q' => $q, 'class' => $class], fn($v) => $v !== '') + \Align\Lifecycle\DeviceFilters::query($dfilters);
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-desktop', 'title' => 'Devices & assets', 'count' => $matched !== $total ? num($matched) . ' of ' . num($total) : $total,
    'desc' => 'Every client\'s computers, servers and network gear. Search by name, serial, last user or model; open a device to change its type, dates or replacement plan.',
    'secondary' => ['<a class="btn btn-sm btn-default" href="/devices/unassigned"><i class="fas fa-circle-question me-1"></i>Unassigned hardware' . ($counts['unassigned'] ? ' <span class="badge text-bg-warning">' . num($counts['unassigned']) . '</span>' : '') . '</a>'],
    'help' => \Align\Auth::can('tech') ? 'guide-lifecycle' : null,
]);
// A tile opens its view for the same client and filters (the tile counts follow the filters, so the list matches)
$tileLink = fn(string $f) => $base . '?' . http_build_query(array_filter(['client' => $clientId ?: '', 'filter' => $f] + \Align\Lifecycle\DeviceFilters::query($dfilters)));
echo \Align\View::fetch('partials/tiles', ['tiles' => [
    ['label' => 'Needs attention', 'value' => $tiles['attention'], 'tone' => $tiles['attention'] ? 'danger' : 'success', 'href' => $tileLink('attention'), 'active' => $filter === 'attention'],
    ['label' => 'Replace / plan', 'value' => $tiles['replace'], 'tone' => 'warning', 'href' => $tileLink('replace'), 'active' => $filter === 'replace', 'title' => 'Past or near end of life, or with a replacement planned'],
    ['label' => 'OS support ending or ended', 'value' => $tiles['os'], 'tone' => 'danger', 'href' => $tileLink('os'), 'active' => $filter === 'os'],
    ['label' => 'Warranty ending, ended or unknown', 'value' => $tiles['warranty'], 'tone' => 'secondary', 'href' => $tileLink('warranty'), 'active' => $filter === 'warranty'],
    ['label' => 'Stale (no check-in)', 'value' => $tiles['stale'], 'tone' => 'secondary', 'href' => $tileLink('stale'), 'active' => $filter === 'stale'],
]]);
$clientMenu = '<div class="btn-group"><button class="btn btn-sm ' . ($clientId ? 'btn-secondary' : 'btn-default') . ' dropdown-toggle" data-bs-toggle="dropdown">'
    . e($clientId ? (array_column($clients, 'name', 'id')[$clientId] ?? 'Client') : 'Client') . '</button><div class="dropdown-menu dropdown-menu-end">'
    . '<a class="dropdown-item' . (!$clientId ? ' active' : '') . '" href="' . e($base . '?' . http_build_query(array_diff_key($keep, ['client' => 1]) + ['filter' => $filter])) . '">All clients</a><div class="dropdown-divider"></div>';
foreach ($clients as $c) {
    $clientMenu .= '<a class="dropdown-item' . ((int) $c['id'] === $clientId ? ' active' : '') . '" href="' . e($base . '?' . http_build_query(['client' => $c['id']] + $keep + ['filter' => $filter])) . '">' . e($c['name']) . '</a>';
}
$clientMenu .= '</div></div>';
?>
<div class="card">
  <?php // The CSV link carries everything the list is narrowed by, so the file holds what is on screen ?>
  <?= \Align\View::fetch('devices/_toolbar', ['base' => $base, 'keep' => $clientId ? ['client' => $clientId] : [], 'filter' => $filter, 'class' => $class, 'q' => $q, 'bkOn' => false, 'dfilters' => $dfilters, 'dopts' => $dopts,
      'export' => '/devices/export?' . http_build_query(array_filter(['client' => $clientId ?: '', 'filter' => $filter, 'class' => $class, 'q' => $q] + \Align\Lifecycle\DeviceFilters::query($dfilters))), 'counts' => $counts, 'extraMenus' => [$clientMenu]]) ?>
  <div class="card-body p-0">
    <?= \Align\View::fetch('devices/_table', ['devices' => $devices, 'showClient' => true]) ?>
  </div>
  <?= \Align\View::fetch('partials/list_footer', ['shown' => count($devices), 'total' => $matched, 'moreUrl' => \Align\Paging::moreUrl($limit)]) ?>
</div>

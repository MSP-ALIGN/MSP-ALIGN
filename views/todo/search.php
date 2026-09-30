<?php
/** @var string $q; array $res */
$n = count($res['clients']) + count($res['devices']) + count($res['contacts']) + count($res['licenses']);
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-magnifying-glass', 'title' => $q !== '' ? 'Search results' : 'Search', 'count' => $q !== '' ? $n : null,
    'desc' => $q !== '' ? 'Clients, devices (name, serial, last user, model, IP), contacts (name, email, phone) and licenses matching <b>' . e($q) . '</b>.' : 'Type at least two letters in the search box at the top.',
]);
$section = function (string $icon, string $title, int $count, string $body, ?string $more = null) {
    return '<div class="card mb-3"><div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw ' . e($icon) . ' text-secondary me-1"></i>' . e($title) . ' <span class="badge text-bg-secondary">' . $count . '</span></h3>'
        . ($more ? '<div class="card-tools small">' . $more . '</div>' : '') . '</div><div class="card-body p-0">' . $body . '</div></div>';
};
?>
<form method="get" action="/search" class="mb-3" role="search"><div class="input-group" style="max-width:560px">
  <input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Search clients, devices, serials, contacts, licenses" aria-label="Search" autofocus>
  <button class="btn btn-primary"><i class="fas fa-search"></i></button></div></form>
<?php if ($q !== '' && !$n): ?><div class="card card-body text-muted">Nothing matches. Try part of a name, a serial number or an email address.</div><?php endif; ?>
<?php
if ($res['clients']) {
    $b = '<ul class="list-group list-group-flush">';
    foreach ($res['clients'] as $c) {
        $b .= '<li class="list-group-item py-2"><a class="fw-bold" href="/clients/' . (int) $c['id'] . '">' . e($c['name']) . '</a>'
            . ($c['industry'] ? ' <span class="small text-muted">· ' . e($c['industry']) . '</span>' : '')
            . ($c['is_archived'] ? ' <span class="badge text-bg-dark">archived</span>' : ($c['planning_excluded'] ? ' <span class="badge text-bg-secondary">removed from planning</span>' : '')) . '</li>';
    }
    echo $section('fa-users', 'Clients', count($res['clients']), $b . '</ul>');
}
if ($res['devices']) {
    echo $section('fa-desktop', 'Devices', count($res['devices']), \Align\View::fetch('devices/_table', ['devices' => $res['devices'], 'showClient' => true, 'tableId' => 'search-devices']),
        !empty($res['moreDevices']) ? '<a href="/devices?q=' . e(rawurlencode($q)) . '">See all matching devices →</a>' : null);
}
if ($res['contacts']) {
    $b = '<table class="table table-sm table-hover mb-0"><thead><tr><th>Name</th><th>Client</th><th>Email</th><th>Phone</th></tr></thead><tbody>';
    foreach ($res['contacts'] as $k) {
        $b .= '<tr><td><a class="fw-bold" href="/clients/' . (int) $k['client_id'] . '/contacts">' . e($k['name']) . '</a>' . ($k['title'] ? '<div class="small text-muted">' . e($k['title']) . '</div>' : '') . '</td>'
            . '<td class="small">' . e($k['client_name']) . '</td><td class="small">' . ($k['email'] ? '<a href="mailto:' . e($k['email']) . '">' . e($k['email']) . '</a>' : '') . '</td><td class="small">' . e((string) $k['phone']) . '</td></tr>';
    }
    echo $section('fa-address-book', 'Contacts', count($res['contacts']), $b . '</tbody></table>');
}
if ($res['licenses']) {
    $b = '<table class="table table-sm table-hover mb-0"><thead><tr><th>License</th><th>Client</th><th class="text-end">Seats</th><th>Renews</th></tr></thead><tbody>';
    foreach ($res['licenses'] as $l) {
        $b .= '<tr><td><a class="fw-bold" href="/clients/' . (int) $l['client_id'] . '/licenses">' . e($l['name']) . '</a>' . ($l['vendor'] ? '<div class="small text-muted">' . e($l['vendor']) . '</div>' : '') . '</td>'
            . '<td class="small">' . e($l['client_name']) . '</td><td class="text-end">' . ($l['seats'] !== null ? (int) $l['seats'] : '—') . '</td><td class="small">' . e(fmt_date($l['expire_date'])) . '</td></tr>';
    }
    echo $section('fa-key', 'Licenses', count($res['licenses']), $b . '</tbody></table>');
}

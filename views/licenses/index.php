<?php
use Align\Licensing\Licenses;

$t = $totals;
$qs = fn(array $over) => '/licenses?' . http_build_query(array_filter(array_merge(['filter' => $filter, 'client' => $clientId ?: '', 'category' => $category, 'q' => $q], $over), fn($v) => $v !== '' && $v !== null));
$filters = ['' => 'All', 'unpriced' => 'Needs a price', 'renewals' => 'Renewing ≤ 90 days', 'over' => 'Over-assigned'];
?>
<?php
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-key', 'title' => 'Licensing', 'count' => $matched !== $t['count'] ? num($matched) . ' of ' . num($t['count']) : $t['count'],
    'desc' => 'Software and subscriptions across every client in planning, with what each costs a month and a year. Open a license to set its price, billing and contract.',
    'secondary' => ['<a class="btn btn-sm btn-default" href="/renewals"><i class="fas fa-calendar-check mr-1"></i>Renewals</a>'],
]);
echo \Align\View::fetch('partials/tiles', ['tiles' => [
    ['label' => 'Monthly, all clients', 'value' => money_exact($t['monthly']), 'tone' => 'dark'],
    ['label' => 'Annual', 'value' => money($t['annual']), 'tone' => 'dark'],
    ['label' => 'Need a price', 'value' => (int) $t['unpriced'], 'tone' => $t['unpriced'] ? 'warning' : 'success', 'href' => $qs(['filter' => 'unpriced']), 'active' => $filter === 'unpriced'],
    ['label' => 'Renewing ≤ 90 days', 'value' => count($t['renewals']), 'tone' => $t['renewals'] ? 'warning' : 'success', 'href' => $qs(['filter' => 'renewals']), 'active' => $filter === 'renewals'],
]]);
$tabs = [];
foreach ($filters as $k => $label) {
    $tabs[] = [$label, $qs(['filter' => $k]), $filter === $k];
}
$cats = array_map(fn($c) => $c[0], Licenses::CATEGORIES);
?>
<div class="row">
  <div class="col-xl-9">
    <div class="card">
      <?= \Align\View::fetch('partials/toolbar', ['tabs' => $tabs,
          'search' => ['action' => '/licenses', 'value' => $q, 'hidden' => ['filter' => $filter, 'client' => $clientId ?: '', 'category' => $category], 'table' => 'licenses-table', 'placeholder' => 'Search product, vendor, client'],
          'menus' => [toolbar_menu('Client', $clients, $clientId ?: '', fn($v) => $qs(['client' => $v]), 'All clients'), toolbar_menu('Category', $cats, $category, fn($v) => $qs(['category' => $v]), 'All categories')]]) ?>
      <div class="card-body p-0">
        <?= \Align\View::fetch('licenses/_table', ['licenses' => $licenses, 'showClient' => true, 'back' => $back]) ?>
      </div>
      <?= \Align\View::fetch('partials/list_footer', ['shown' => count($licenses), 'total' => $matched, 'moreUrl' => \Align\Paging::moreUrl($limit)]) ?>
    </div>
  </div>
  <div class="col-xl-3">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-users mr-2"></i>By client (monthly)</h3></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($byClient as $c): ?>
          <li class="list-group-item d-flex py-2"><a class="mr-auto" href="/clients/<?= (int) $c['id'] ?>/licenses"><?= e($c['name']) ?></a>
            <span class="text-nowrap"><?= money_exact($c['monthly']) ?><?= $c['unpriced'] ? ' <span class="badge badge-warning" title="Licenses without a price">' . (int) $c['unpriced'] . '</span>' : '' ?></span></li>
        <?php endforeach; ?>
        <?php if (!$byClient): ?><li class="list-group-item text-muted">No licenses yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php
use Align\Contacts\Contacts;

/** @var array $contacts (this page); int $matched; int $total; array $counts; int $limit; string $q; string $role; string $back */
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-address-book', 'title' => 'Contacts', 'count' => $matched !== $total ? num($matched) . ' of ' . num($total) : $total,
    'desc' => 'Everyone at every client in planning. Open a contact to change their roles or notes' . (psa_on() ? '; details from ' . e(psa_name()) . ' update with each sync.' : '.'),
    'secondary' => \Align\Auth::can('tech') ? ['<a class="btn btn-sm btn-default" href="/clients/import?kind=contacts"><i class="fas fa-file-import mr-1"></i>Import</a>'] : [],
]);
$link = fn(string $r) => '/contacts' . (($qs = http_build_query(array_filter(['role' => $r, 'q' => $q]))) ? "?$qs" : '');
$tabs = [['All', $link(''), $role === '']];
foreach (Contacts::ROLES as $col => [$label]) {
    $tabs[] = [$label, $link($col), $role === $col, $counts[$col] ?? null];
}
?>
<div class="card">
  <?= \Align\View::fetch('partials/toolbar', ['tabs' => $tabs, 'search' => ['action' => '/contacts', 'value' => $q, 'hidden' => ['role' => $role], 'table' => 'contacts-table', 'placeholder' => 'Search name, email, phone, client']]) ?>
  <div class="card-body p-0"><?= \Align\View::fetch('contacts/_table', ['contacts' => $contacts, 'showClient' => true, 'back' => $back]) ?></div>
  <?= \Align\View::fetch('partials/list_footer', ['shown' => count($contacts), 'total' => $matched, 'moreUrl' => \Align\Paging::moreUrl($limit)]) ?>
</div>

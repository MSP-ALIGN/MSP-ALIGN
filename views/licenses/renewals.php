<?php
$past = array_filter($dates, fn($d) => $d['urgency'] === 'past');
$soon = array_filter($dates, fn($d) => $d['urgency'] === 'soon');
$annual = array_sum(array_map(fn($d) => $d['kind'] === 'renegotiate' ? $d['annual'] : 0, $dates));
?>
<?php
$dayBtns = '<div class="btn-group btn-group-sm">';
foreach ([30 => '30 days', 90 => '90 days', 180 => '6 months', 365 => '12 months'] as $d => $label) {
    $dayBtns .= '<a class="btn ' . ($days === $d ? 'btn-primary' : 'btn-default') . '" href="?days=' . $d . '">' . $label . '</a>';
}
$dayBtns .= '</div>';
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-calendar-check', 'title' => 'Renewals & contracts', 'count' => count($dates),
    'desc' => 'Contract ends, renegotiate-by dates and license renewals across every client, soonest first.',
    'secondary' => [$dayBtns, '<a class="btn btn-sm btn-default" href="/licenses"><i class="fas fa-key me-1"></i>Licensing</a>'],
]);
echo \Align\View::fetch('partials/tiles', ['tiles' => [
    ['label' => 'Passed in the last 30 days', 'value' => count($past), 'tone' => count($past) ? 'danger' : 'success'],
    ['label' => 'Next 90 days', 'value' => count($soon), 'tone' => count($soon) ? 'warning' : 'success'],
    ['label' => 'Annual value up for renegotiation', 'value' => money($annual), 'tone' => 'dark'],
]]);
?>
<?= \Align\View::fetch('partials/contract_dates', ['dates' => $dates, 'title' => 'Coming up', 'showClient' => true, 'limit' => 500]) ?>
<p class="small text-muted">Dates come from the contract details on licenses and budget lines (renegotiate-by, contract end) and from license expiry dates<?= psa_on() ? ' synced from ' . e(psa_name()) : '' ?>. They also appear on the calendar.</p>

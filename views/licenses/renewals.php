<?php
/**
 * Renewals and contract dates across clients. @var array $dates; int $days (one of 30, 90, 180, 365, checked by the controller)
 * 2.9.0: array $byVendor (each vendor's dates: key, name, count, annual, next, clients), string $vendor (the vendor
 * shown, as Vendors::key, or ''), $vendorName; int $clientId (one client, or 0), string $clientName. Everything is
 * escaped; the filters go into links with http_build_query.
 */
$past = array_filter($dates, fn($d) => $d['urgency'] === 'past');
$soon = array_filter($dates, fn($d) => $d['urgency'] === 'soon');
$annual = array_sum(array_map(fn($d) => $d['kind'] === 'renegotiate' ? $d['annual'] : 0, $dates));
// a link with these filters, changed by $over (a null in $over drops that filter)
$url = fn(array $over) => '/renewals?' . http_build_query(array_filter(array_replace(['days' => $days, 'vendor' => $vendor, 'client' => $clientId ?: null], $over), fn($v) => $v !== '' && $v !== null));
?>
<?php
$dayBtns = '<div class="btn-group btn-group-sm">';
foreach ([30 => '30 days', 90 => '90 days', 180 => '6 months', 365 => '12 months'] as $d => $label) {
    $dayBtns .= '<a class="btn ' . ($days === $d ? 'btn-primary' : 'btn-default') . '" href="' . e($url(['days' => $d])) . '">' . $label . '</a>';
}
$dayBtns .= '</div>';
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-calendar-check', 'title' => 'Renewals & contracts', 'count' => count($dates),
    'desc' => 'Contract ends, renegotiate-by dates, license renewals and domain expiry dates across every client, soonest first.',
    'secondary' => [$dayBtns, '<a class="btn btn-sm btn-default" href="/licenses"><i class="fas fa-key me-1"></i>Licensing</a>'],
]);
?>
<?php if (!empty($noVendorDates)): ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-circle-info me-1"></i>That vendor has no dates in this period<?= $clientId ? ' for this client' : '' ?>, so every vendor's are shown. Try a longer period.</div>
<?php endif; ?>
<?php if ($vendor !== '' || $clientId): ?>
  <div class="alert alert-light border small py-2 d-flex flex-wrap align-items-center gap-2" id="renewal-filters"><i class="fas fa-filter text-muted"></i>
    <?php if ($clientId): ?><span>Client: <b><?= e($clientName ?: '#' . $clientId) ?></b> <a href="<?= e($url(['client' => null])) ?>" aria-label="Show every client" title="Every client"><i class="fas fa-xmark"></i></a></span><?php endif; ?>
    <?php if ($vendor !== ''): ?><span>Vendor: <b><?= e($vendorName) ?></b> <a href="<?= e($url(['vendor' => null])) ?>" aria-label="Show every vendor" title="Every vendor"><i class="fas fa-xmark"></i></a></span><?php endif; ?>
  </div>
<?php endif; ?>
<?= \Align\View::fetch('partials/tiles', ['tiles' => [
    ['label' => 'Passed in the last 30 days', 'value' => count($past), 'tone' => count($past) ? 'danger' : 'success'],
    ['label' => 'Next 90 days', 'value' => count($soon), 'tone' => count($soon) ? 'warning' : 'success'],
    ['label' => 'Annual value up for renegotiation', 'value' => money($annual), 'tone' => 'dark'],
]]) ?>
<div class="row">
  <div class="col-xl-7"><?= \Align\View::fetch('partials/contract_dates', ['dates' => $dates, 'title' => $vendor !== '' ? 'Coming up with ' . $vendorName : 'Coming up', 'showClient' => !$clientId, 'showVendor' => $vendor === '', 'limit' => 500]) ?></div>
  <div class="col-xl-5">
    <div class="card card-dark" id="renewals-by-vendor">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-store me-2"></i>By vendor</h3></div>
      <?php if (!$byVendor): ?><div class="card-body small text-muted">Nothing in this period.</div>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm mb-0 small">
        <thead><tr><th>Vendor</th><th class="text-end">Dates</th><th class="text-end">Clients</th><th>Next</th></tr></thead>
        <tbody>
        <?php foreach ($byVendor as $b): ?>
          <tr<?= $b['key'] !== '' && $b['key'] === $vendor ? ' class="table-active"' : '' ?>>
            <td><?php if ($b['key'] === ''): ?><span class="text-muted">No vendor</span><?php else: ?><a href="<?= e($url(['vendor' => $b['key']])) ?>"><?= e($b['name']) ?></a><?php endif; ?>
              <?php if ($b['annual'] > 0): ?><div class="text-muted"><?= money($b['annual']) ?>/yr to renegotiate</div><?php endif; ?></td>
            <td class="text-end"><?= (int) $b['count'] ?></td>
            <td class="text-end"><?= count($b['clients']) ?></td>
            <td class="text-nowrap"><?= e(fmt_date($b['next'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<p class="small text-muted">Dates come from the contract details on licenses and budget lines (renegotiate-by, contract end) and from license expiry dates<?= psa_on() ? ' synced from ' . e(psa_name()) : '' ?>; domain expiry dates come from the domains' public registration records (RDAP). They also appear on the calendar. A date's vendor is the client vendor its license or budget line is linked to, else the vendor name on it; the same vendor at several clients counts once here.</p>

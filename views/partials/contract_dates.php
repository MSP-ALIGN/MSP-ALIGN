<?php
/**
 * @var array $dates Contracts::upcoming(); $title; $showClient; $limit; bool $showVendor (2.9.0: who each date is with;
 *      staff pages only, the portal leaves it out)
 */
$showClient = $showClient ?? false;
$showVendor = $showVendor ?? false;
$limit = $limit ?? 12;
$icons = ['renegotiate' => 'fa-handshake-angle', 'contract_end' => 'fa-file-signature', 'expires' => 'fa-rotate', 'domain' => 'fa-globe']; // 2.10.0 domain
?>
<div class="card <?= e($cardClass ?? 'card-dark') ?>">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-calendar-check me-2"></i><?= e($title ?? 'Upcoming contract dates') ?></h3>
    <?php if (!empty($moreLink)): ?><div class="card-tools"><a href="<?= e($moreLink) ?>" class="btn btn-tool">All</a></div><?php endif; ?></div>
  <?php if (!$dates): ?>
    <div class="card-body small text-muted"><?= e($emptyText ?? 'No contract end, renegotiation or renewal dates in the next 12 months. Add terms and dates on licenses and budget lines to track them here.') ?></div>
  <?php else: ?>
  <ul class="list-group list-group-flush small">
    <?php foreach (array_slice($dates, 0, $limit) as $d): $tone = ['past' => 'danger', 'soon' => 'warning', 'later' => 'secondary'][$d['urgency']]; ?>
      <li class="list-group-item py-2 d-flex">
        <div class="text-nowrap me-3 text-<?= $tone ?> fw-bold" style="min-width:92px"><?= e(fmt_date($d['date'])) ?><div class="fw-normal text-muted"><?= e(days_from_now($d['date'])) ?></div></div>
        <div class="me-auto">
          <i class="fas <?= $icons[$d['kind']] ?> me-1 text-muted"></i><b><?= e($d['label']) ?>:</b> <a href="<?= e($d['link']) ?>"><?= e($d['name']) ?></a>
          <div class="text-muted"><?= e(implode(' · ', array_filter([
              $showClient ? $d['client_name'] : null,
              $showVendor ? ($d['vendor'] ?? null) : null,
              $d['term'] ? $d['term'] . ' term' : null,
              $d['annual'] ? money($d['annual']) . '/yr' : null,
              $d['kind'] !== 'renegotiate' && $d['auto_renew'] !== null ? ($d['auto_renew'] ? 'auto-renews' : 'not renewing') : null, // a domain's is unknown
          ]))) ?></div>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<?php
use Align\Licensing\Licenses;

/** @var array $licenses, $totals */
$groups = [];
foreach ($licenses as $l) {
    $groups[$l['category']][] = $l;
}
uksort($groups, fn($a, $b) => array_search($a, array_keys(Licenses::CATEGORIES)) <=> array_search($b, array_keys(Licenses::CATEGORIES)));
?>
<div class="mb-3"><h1 class="h4 mb-0"><i class="fas fa-key mr-2 text-secondary"></i>Licensing</h1>
  <div class="small text-muted">Software subscriptions and licenses your IT provider manages for you.</div></div>

<div class="row">
  <div class="col-md-3 col-6"><div class="info-box"><span class="info-box-icon bg-primary"><i class="fas fa-rotate"></i></span><div class="info-box-content"><span class="info-box-text">Monthly</span><span class="info-box-number"><?= money_exact($totals['monthly']) ?></span></div></div></div>
  <div class="col-md-3 col-6"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-calendar"></i></span><div class="info-box-content"><span class="info-box-text">Annual</span><span class="info-box-number"><?= money($totals['annual']) ?></span></div></div></div>
  <div class="col-md-3 col-6"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-cubes"></i></span><div class="info-box-content"><span class="info-box-text">Products</span><span class="info-box-number"><?= (int) $totals['count'] ?></span></div></div></div>
  <div class="col-md-3 col-6"><div class="info-box"><span class="info-box-icon bg-warning"><i class="fas fa-calendar-check"></i></span><div class="info-box-content"><span class="info-box-text">Renewing soon</span><span class="info-box-number"><?= count($totals['renewals']) ?></span></div></div></div>
</div>

<div class="card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0 license-table">
      <thead><tr><th>Product</th><th>Type</th><th class="text-right">Licenses</th><th>Billed</th><th class="text-right">Monthly</th><th class="text-right">Annual</th><th>Renews</th></tr></thead>
      <tbody>
      <?php if (!$licenses): ?><tr><td colspan="7" class="text-center text-muted py-4">No licenses have been added yet.</td></tr><?php endif; ?>
      <?php foreach ($groups as $cat => $rows): [$label, $icon, $tone] = Licenses::CATEGORIES[$cat] ?? Licenses::CATEGORIES['other']; ?>
        <tr class="proj-quarter"><th colspan="4"><i class="fas <?= e($icon) ?> text-<?= e($tone) ?> mr-1"></i><?= e($label) ?></th>
          <th class="text-right"><?= money(array_sum(array_column($rows, 'monthly'))) ?></th><th class="text-right"><?= money(array_sum(array_column($rows, 'annual'))) ?></th><th></th></tr>
        <?php foreach ($rows as $l): ?>
          <tr>
            <td><b><?= e($l['name']) ?></b><?php if ($l['vendor']): ?><div class="small text-muted"><?= e($l['vendor']) ?></div><?php endif; ?>
              <?php if ($cs = \Align\Budget\Contracts::summary($l)): ?><div class="small text-muted"><i class="fas fa-file-signature mr-1"></i><?= e($cs) ?></div><?php endif; ?></td>
            <td class="small"><?= e(Licenses::TYPES[$l['license_type']]) ?></td>
            <td class="text-right text-nowrap"><?= $l['seats'] !== null ? (int) $l['seats'] : '—' ?><?php if ($l['seats_used'] !== null): ?><div class="small text-muted"><?= (int) $l['seats_used'] ?> in use</div><?php endif; ?></td>
            <td class="small"><?= e(Licenses::CYCLES[$l['billing_cycle']][0]) ?></td>
            <td class="text-right text-nowrap"><?= $l['billing_cycle'] === 'one_time' ? '<span class="small text-muted">' . money($l['cycle_cost']) . ' once</span>' : ($l['priced'] ? money_exact($l['monthly']) : '—') ?></td>
            <td class="text-right text-nowrap"><?= $l['priced'] && $l['billing_cycle'] !== 'one_time' ? money_exact($l['annual']) : '—' ?></td>
            <td class="small text-nowrap"><?php if ($l['expire_date']): ?><span class="<?= $l['renewal'] === 'soon' ? 'text-warning font-weight-bold' : '' ?>"><?= e(fmt_date($l['expire_date'])) ?></span>
              <div class="text-muted"><?= $l['auto_renew'] ? 'auto-renews' : 'manual renewal' ?></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

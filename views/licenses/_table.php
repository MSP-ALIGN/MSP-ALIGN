<?php
use Align\Auth;
use Align\Licensing\Licenses;

/** @var array $licenses enriched; $showClient bool; $back string */
$showClient = $showClient ?? false;
$canEdit = Auth::can('tech');
$groups = [];
foreach ($licenses as $l) {
    $groups[$showClient ? '' : $l['category']][] = $l;
}
if (!$showClient) {
    uksort($groups, fn($a, $b) => array_search($a, array_keys(Licenses::CATEGORIES)) <=> array_search($b, array_keys(Licenses::CATEGORIES)));
}
$cols = $showClient ? 9 : 8;
?>
<div class="table-responsive">
<table class="table table-sm table-hover mb-0 license-table" id="licenses-table">
  <thead><tr>
    <th>Product</th><?php if ($showClient): ?><th>Client</th><?php endif; ?><th>Type</th><th class="text-right">Seats</th><th class="text-right">Price</th><th>Billed</th>
    <th class="text-right">Monthly</th><th class="text-right">Annual</th><th>Renews</th>
  </tr></thead>
  <tbody>
  <?php if (!$licenses): ?><tr><td colspan="<?= $cols ?>" class="text-center text-muted py-4">No licenses yet.</td></tr><?php endif; ?>
  <?php foreach ($groups as $cat => $rows):
      if (!$showClient): [$label, $icon, $tone] = Licenses::CATEGORIES[$cat] ?? Licenses::CATEGORIES['other']; ?>
    <tr class="proj-quarter"><th colspan="<?= $cols - 3 ?>"><i class="fas <?= e($icon) ?> text-<?= e($tone) ?> mr-1"></i><?= e($label) ?></th>
      <th class="text-right"><?= money(array_sum(array_column(array_filter($rows, fn($r) => !$r['retired_at']), 'monthly'))) ?></th>
      <th class="text-right"><?= money(array_sum(array_column(array_filter($rows, fn($r) => !$r['retired_at']), 'annual'))) ?></th><th></th></tr>
    <?php endif; ?>
    <?php foreach ($rows as $l): ?>
      <tr class="<?= $l['retired_at'] ? 'text-muted' : '' ?>">
        <td>
          <?php if ($canEdit): ?><a href="#" class="font-weight-bold" data-lazy-modal="/licenses/<?= (int) $l['id'] ?>/form?back=<?= e(rawurlencode($back)) ?>" data-target="#modal-license-<?= (int) $l['id'] ?>"><?= e($l['name']) ?></a><?php else: ?><b><?= e($l['name']) ?></b><?php endif; ?>
          <?php if ($l['source'] === 'psa'): ?><span class="badge badge-light border" title="Synced from <?= e(psa_name()) ?>"><?= e(psa_name()) ?></span><?php endif; ?>
          <?php if ($l['retired_at']): ?><span class="badge badge-secondary">retired<?= $l['retired_reason'] === 'psa' ? ' in ' . psa_name() : '' ?></span><?php endif; ?>
          <?php if ($l['vendor'] || $l['version']): ?><div class="small text-muted"><?= e(implode(' · ', array_filter([$l['vendor'], $l['version']]))) ?></div><?php endif; ?>
          <?php if ($cs = \Align\Budget\Contracts::summary($l)): $u = \Align\Budget\Contracts::urgency($l['renegotiate_date'] ?: $l['contract_end']); ?>
            <div class="small <?= $u === 'past' ? 'text-danger' : ($u === 'soon' ? 'text-warning font-weight-bold' : 'text-muted') ?>"><i class="fas fa-file-signature mr-1"></i><?= e($cs) ?></div>
          <?php endif; ?>
        </td>
        <?php if ($showClient): ?><td class="small"><a href="/clients/<?= (int) $l['client_id'] ?>/licenses"><?= e($l['client_name']) ?></a></td><?php endif; ?>
        <td class="small"><?= e(Licenses::TYPES[$l['license_type']]) ?></td>
        <td class="text-right text-nowrap"><?= $l['seats'] !== null ? (int) $l['seats'] : '—' ?><?php if ($l['seats_used'] !== null): ?><div class="small <?= $l['over'] ? 'text-danger font-weight-bold' : 'text-muted' ?>" title="In use"><?= (int) $l['seats_used'] ?> in use</div><?php endif; ?></td>
        <td class="text-right text-nowrap"><?php if ($l['priced']): ?><?= money_exact((float) $l['unit_price']) ?><div class="small text-muted"><?= $l['pricing'] === 'per_seat' ? 'per seat' : 'flat' ?></div><?php else: ?><span class="badge badge-warning">needs price</span><?php endif; ?></td>
        <td class="small"><?= e(Licenses::CYCLES[$l['billing_cycle']][0]) ?></td>
        <td class="text-right text-nowrap"><?= $l['billing_cycle'] === 'one_time' ? '<span class="small text-muted">' . money($l['cycle_cost']) . ' once</span>' : ($l['priced'] ? money_exact($l['monthly']) : '—') ?></td>
        <td class="text-right text-nowrap"><?= $l['priced'] && $l['billing_cycle'] !== 'one_time' ? money_exact($l['annual']) : '—' ?></td>
        <td class="small text-nowrap"><?php if ($l['expire_date']): ?>
          <span class="<?= $l['renewal'] === 'expired' ? 'text-danger font-weight-bold' : ($l['renewal'] === 'soon' ? 'text-warning font-weight-bold' : '') ?>"><?= e(fmt_date($l['expire_date'])) ?></span>
          <div class="text-muted"><?= $l['renewal'] === 'expired' ? 'expired' : ($l['auto_renew'] ? 'auto-renews' : 'manual renewal') ?></div>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

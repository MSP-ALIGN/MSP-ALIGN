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
    <th>Product</th><?php if ($showClient): ?><th>Client</th><?php endif; ?><th>Type</th><th class="text-end">Seats</th><th class="text-end">Price</th><th>Billed</th>
    <th class="text-end">Monthly</th><th class="text-end">Annual</th><th>Renews</th>
  </tr></thead>
  <tbody>
  <?php if (!$licenses): ?><tr><td colspan="<?= $cols ?>" class="text-center text-muted py-4">No licenses yet.</td></tr><?php endif; ?>
  <?php foreach ($groups as $cat => $rows):
      if (!$showClient): [$label, $icon, $tone] = Licenses::CATEGORIES[$cat] ?? Licenses::CATEGORIES['other']; ?>
    <tr class="proj-quarter"><th colspan="<?= $cols - 3 ?>"><i class="fas <?= e($icon) ?> text-<?= e($tone) ?> me-1"></i><?= e($label) ?></th>
      <th class="text-end"><?= money(array_sum(array_column(array_filter($rows, fn($r) => !$r['retired_at']), 'monthly'))) ?></th>
      <th class="text-end"><?= money(array_sum(array_column(array_filter($rows, fn($r) => !$r['retired_at']), 'annual'))) ?></th><th></th></tr>
    <?php endif; ?>
    <?php foreach ($rows as $l): ?>
      <tr class="<?= $l['retired_at'] ? 'text-muted' : '' ?>">
        <td>
          <?php if ($canEdit): ?><a href="#" class="fw-bold" data-lazy-modal="/licenses/<?= (int) $l['id'] ?>/form?back=<?= e(rawurlencode($back)) ?>" data-bs-target="#modal-license-<?= (int) $l['id'] ?>"><?= e($l['name']) ?></a><?php else: ?><b><?= e($l['name']) ?></b><?php endif; ?>
          <?php if ($l['source'] === 'psa'): ?><span class="badge text-bg-light border" title="Synced from <?= e(psa_name()) ?>"><?= e(psa_name()) ?></span><?php endif; ?>
          <?php if ($l['retired_at']): ?><span class="badge text-bg-secondary">retired<?= $l['retired_reason'] === 'psa' ? ' in ' . psa_name() : '' ?></span><?php endif; ?>
          <?php if ($l['vendor'] || $l['version']): ?><div class="small text-muted"><?= e(implode(' · ', array_filter([$l['vendor'], $l['version']]))) ?></div><?php endif; ?>
          <?php if ($cs = \Align\Budget\Contracts::summary($l)): $u = \Align\Budget\Contracts::urgency($l['renegotiate_date'] ?: $l['contract_end']); ?>
            <div class="small <?= $u === 'past' ? 'text-danger' : ($u === 'soon' ? 'text-warning fw-bold' : 'text-muted') ?>"><i class="fas fa-file-signature me-1"></i><?= e($cs) ?></div>
          <?php endif; ?>
        </td>
        <?php if ($showClient): ?><td class="small"><a href="/clients/<?= (int) $l['client_id'] ?>/licenses"><?= e($l['client_name']) ?></a></td><?php endif; ?>
        <td class="small"><?= e(Licenses::TYPES[$l['license_type']]) ?></td>
        <td class="text-end text-nowrap"><?= $l['seats'] !== null ? (int) $l['seats'] : '—' ?><?php if ($l['seats_used'] !== null): ?><div class="small <?= $l['over'] ? 'text-danger fw-bold' : 'text-muted' ?>" title="In use"><?= (int) $l['seats_used'] ?> in use</div><?php endif; ?></td>
        <td class="text-end text-nowrap"><?php if ($l['priced']): ?><?= money_exact((float) $l['unit_price']) ?><div class="small text-muted"><?= $l['pricing'] === 'per_seat' ? 'per seat' : 'flat' ?></div><?php else: ?><span class="badge text-bg-warning">needs price</span><?php endif; ?></td>
        <td class="small"><?= e(Licenses::CYCLES[$l['billing_cycle']][0]) ?></td>
        <td class="text-end text-nowrap"><?= $l['billing_cycle'] === 'one_time' ? '<span class="small text-muted">' . money($l['cycle_cost']) . ' once</span>' : ($l['priced'] ? money_exact($l['monthly']) : '—') ?></td>
        <td class="text-end text-nowrap"><?= $l['priced'] && $l['billing_cycle'] !== 'one_time' ? money_exact($l['annual']) : '—' ?></td>
        <td class="small text-nowrap"><?php if ($l['expire_date']): ?>
          <span class="<?= $l['renewal'] === 'expired' ? 'text-danger fw-bold' : ($l['renewal'] === 'soon' ? 'text-warning fw-bold' : '') ?>"><?= e(fmt_date($l['expire_date'])) ?></span>
          <div class="text-muted"><?= $l['renewal'] === 'expired' ? 'expired' : ($l['auto_renew'] ? 'auto-renews' : 'manual renewal') ?></div>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

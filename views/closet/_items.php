<?php
/**
 * 2.15.0 A closet's items, grouped by category: name (linked to the item), where it is, on the shelf, checked out,
 * and a low-stock badge. @var array $items Closet::items(); bool $showOffice (the MSP's stock across offices);
 * string $empty the text for an empty closet. Every value is escaped.
 */
use Align\Closet\Closet;

$groups = [];
foreach ($items as $it) {
    $groups[$it['category']][] = $it;
}
$showOffice = $showOffice ?? false;
$cols = $showOffice ? 5 : 4;
?>
<div class="table-responsive">
  <table class="table table-sm table-hover mb-0 closet-items">
    <thead><tr><th>Item</th><?php if ($showOffice): ?><th>Office</th><?php endif; ?><th>Where</th><th class="text-end">On the shelf</th><th class="text-end">Checked out</th></tr></thead>
    <tbody>
    <?php if (!$items): ?><tr><td colspan="<?= $cols ?>" class="text-center text-muted py-4"><?= e($empty) ?></td></tr><?php endif; ?>
    <?php foreach ($groups as $cat => $rows): [$label, $icon] = Closet::CATEGORIES[$cat] ?? Closet::CATEGORIES['other']; ?>
      <tr class="proj-quarter"><th colspan="<?= $cols ?>"><i class="fas fa-fw <?= e($icon) ?> text-secondary me-1"></i><?= e($label) ?></th></tr>
      <?php foreach ($rows as $it): ?>
        <tr class="<?= $it['retired_at'] ? 'text-muted' : '' ?>">
          <td><a href="/closet/items/<?= (int) $it['id'] ?>" class="fw-bold"><?= e($it['name']) ?></a>
            <?php if ($it['retired_at']): ?><span class="badge text-bg-secondary">retired</span><?php endif; ?>
            <?php if ($it['low']): ?><span class="badge text-bg-warning" title="Below the minimum of <?= (int) $it['min_quantity'] ?>">low</span><?php endif; ?>
            <?php $sub = implode(' · ', array_filter([$it['manufacturer'], $it['model'], $it['serial'] ? 'S/N ' . $it['serial'] : null])); ?>
            <?php if ($sub !== ''): ?><div class="small text-muted"><?= e($sub) ?></div><?php endif; ?></td>
          <?php if ($showOffice): ?><td class="small"><?= e($it['office_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td><?php endif; ?>
          <td class="small"><?= e((string) $it['location']) ?: '<span class="text-muted">—</span>' ?></td>
          <td class="text-end"><?= $it['kind'] === 'tracked' ? ((int) $it['quantity'] ? '<span class="badge text-bg-success">on the shelf</span>' : '<span class="text-muted">—</span>') : (int) $it['quantity'] ?></td>
          <td class="text-end"><?= $it['out'] ? ($it['kind'] === 'tracked' ? '<span class="badge text-bg-info">out</span>' : (int) $it['out']) : '<span class="text-muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
/**
 * Budgets of every client in planning. @var array $rows, $years; int $year. Client names are escaped; amounts are
 * formatted numbers with a fixed currency symbol.
 */
use Align\Budget\Budget;

$cats = array_keys(Budget::CATEGORIES);
$tot = array_fill_keys($cats, 0.0);
$grand = 0.0;
$run = 0.0;
foreach ($rows as $r) {
    foreach ($cats as $c) { $tot[$c] += $r['year']['by_cat'][$c]; }
    $grand += $r['year']['total'];
    $run += $r['runRate'];
}
$shown = array_values(array_filter($cats, fn($c) => $tot[$c] > 0));
?>
<?php
$yearBtns = '<div class="btn-group btn-group-sm">';
foreach ($years as $y => $yy) {
    $yearBtns .= '<a class="btn ' . ($y === $year ? 'btn-primary' : 'btn-default') . '" href="?year=' . $y . '">' . e($yy['label']) . '</a>';
}
$yearBtns .= '</div>';
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-coins', 'title' => 'Technology budgets', 'count' => count($rows),
    'desc' => 'Each client\'s plan for the year: recurring services, licensing, projects and hardware reaching end of life. Open a client for the line-by-line budget.',
    'secondary' => [$yearBtns, '<a class="btn btn-sm btn-default" href="/projects"><i class="fas fa-diagram-project me-1"></i>Projects</a>'],
]);
echo \Align\View::fetch('partials/tiles', ['tiles' => [
    ['label' => $years[$year]['label'] . ', all clients', 'value' => money($grand), 'tone' => 'dark'],
    ['label' => 'Monthly recurring (today)', 'value' => money($run), 'tone' => 'dark'],
    ['label' => 'Clients in planning', 'value' => count($rows), 'tone' => 'dark'],
]]);
?>
<div class="card card-dark">
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0 budget-table">
      <thead><tr><th class="budget-client-col">Client</th><?php foreach ($shown as $c): ?><th class="num"><span class="bud-swatch bud-c<?= Budget::CATEGORIES[$c][2] ?>"></span><span title="<?= e(Budget::CATEGORIES[$c][0]) ?>"><?= e(Budget::CATEGORIES[$c][3]) ?></span></th><?php endforeach; ?><th class="num"><?= e($years[$year]['label']) ?> total</th><th class="num">Monthly now</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $c = $r['client']; ?>
        <tr>
          <td><?php if ($lg = client_logo_url($c)): ?><img src="<?= e($lg) ?>" alt="" class="client-logo-sm me-1"><?php endif; ?><a href="/clients/<?= (int) $c['id'] ?>/budget?year=<?= $year ?>" class="fw-bold"><?= e($c['name']) ?></a>
            <?php if (!empty($r['notes']['unpriced'])): ?><span class="badge text-bg-warning" title="Licenses without a price"><?= (int) $r['notes']['unpriced'] ?> unpriced</span><?php endif; ?></td>
          <?php foreach ($shown as $cat): ?><td class="num"><?= $r['year']['by_cat'][$cat] ? money($r['year']['by_cat'][$cat]) : '<span class="text-muted">—</span>' ?></td><?php endforeach; ?>
          <td class="num fw-bold"><?= money($r['year']['total']) ?></td><td class="num"><?= money($r['runRate']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total-row"><th>All clients</th><?php foreach ($shown as $c): ?><th class="num"><?= money($tot[$c]) ?></th><?php endforeach; ?><th class="num"><?= money($grand) ?></th><th class="num"><?= money($run) ?></th></tr></tfoot>
    </table>
  </div>
</div>

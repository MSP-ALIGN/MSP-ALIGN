<?php
use Align\Roadmap\Roadmap;
use Align\Reports\Ui;

/** @var array $r; bool $costs; bool $notes; ?string $num */
$projects = $r['projects'];
$stTone = ['proposed' => 'warn', 'approved' => 'info', 'scheduled' => 'info', 'done' => 'ok', 'declined' => 'muted'];
$prTone = ['critical' => 'bad', 'high' => 'warn', 'medium' => 'info', 'low' => 'muted'];
?>
<section class="rsection">
  <?= Ui::head('Projects & recommendations', $num ?? null, count($projects) . ' item' . (count($projects) == 1 ? '' : 's')) ?>
  <?php if (!$projects): ?><p class="muted">No projects on the roadmap yet.</p><?php else: ?>
  <table class="rtable fixed">
    <colgroup><col style="width:12%"><col><col style="width:14%"><col style="width:10%"><col style="width:11%"><?php if ($costs): ?><col style="width:10%"><col style="width:9%"><?php endif; ?></colgroup>
    <thead><tr><th>When</th><th>Project</th><th>Category</th><th>Priority</th><th>Status</th><?php if ($costs): ?><th class="num">One-time</th><th class="num">Monthly</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($projects as $p): [$cl] = Roadmap::category($p['category']); ?>
      <tr class="<?= $p['status'] === 'declined' ? 'dim' : '' ?>">
        <td class="nowrap"><?= e($p['when']) ?></td>
        <td><span class="name"><?= e($p['title']) ?></span>
          <?php if ($notes && $p['description']): ?><div class="sub"><?= nl2br(e($p['description'])) ?></div><?php endif; ?>
          <?php if (!empty($p['decided_by_name'])): ?><div class="sub"><?= $p['status'] === 'declined' ? 'Declined' : 'Approved' ?> by <?= e($p['decided_by_name']) ?>, <?= e(fmt_date($p['decided_at'])) ?></div><?php endif; ?></td>
        <td><?= e($cl) ?></td>
        <td><?= Ui::pill(Roadmap::PRIORITIES[$p['priority']][0], $prTone[$p['priority']] ?? 'muted') ?></td>
        <td><?= Ui::pill(Roadmap::STATUSES[$p['status']][0], $stTone[$p['status']] ?? 'muted') ?></td>
        <?php if ($costs): ?><td class="num"><?= (float) $p['cost'] ? money($p['cost']) : '—' ?></td><td class="num"><?= (float) $p['recurring_monthly'] ? money($p['recurring_monthly']) : '—' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<?php
use Align\Reports\Ui;

/** @var array $r; bool $costs; ?string $num */
$plan = $r['plan'];
$item = fn(string $dot, string $text, string $cost = '') => '<div class="tl-item"><span class="dot dot-' . $dot . '"></span><span class="tl-t">' . $text . '</span>' . ($cost !== '' ? '<span class="tl-c">' . e($cost) . '</span>' : '') . '</div>';
?>
<section class="rsection">
  <?= Ui::head('Roadmap by quarter', $num ?? null) ?>
  <div class="legend"><span><i class="dot-project"></i>Project</span><span><i class="dot-hardware"></i>Hardware replacement</span><span><i class="dot-os"></i>OS end of support</span><span><i class="dot-warranty"></i>Warranty ends</span><span><i class="dot-compliance"></i>Compliance due</span><span><i class="dot-meeting"></i>Meeting</span></div>
  <?php foreach ($plan['years'] as $y => $yr): ?>
    <div class="tl-year">
      <div class="tl-year-head"><span><?= e($yr['label']) ?> <span class="muted"><?= e($yr['range']) ?></span></span><?php if ($costs): ?><span><?= money($yr['total']) ?></span><?php endif; ?></div>
      <div class="tl-grid">
        <?php foreach (array_slice($plan['quarters'], $y * 4, 4) as $q):
            $items = array_values(array_filter($q['items'], fn($i) => $i['status'] !== 'declined'));
            $qt = $q['hw_cost'] + $q['item_cost'];
            $empty = !$items && !$q['hardware'] && !$q['os'] && !$q['warranty'] && !$q['meetings'] && !$q['compliance']; ?>
          <div class="tl-q <?= $q['past'] ? 'is-past' : '' ?> <?= $q['current'] ? 'is-now' : '' ?>">
            <div class="tl-h"><span><?= e($q['short']) ?> <span class="muted"><?= e($q['months']) ?></span></span><?php if ($costs && $qt): ?><span><?= e(Ui::k($qt)) ?></span><?php endif; ?></div>
            <?php foreach ($items as $it) echo $item('project', '<b>' . e($it['title']) . '</b>' . ($it['status'] === 'proposed' ? ' <span class="muted">(proposed)</span>' : ''), $costs && (float) $it['cost'] ? Ui::k((float) $it['cost']) : ''); ?>
            <?php if ($q['hardware']) echo $item('hardware', 'Replace ' . count($q['hardware']) . ' device' . (count($q['hardware']) > 1 ? 's' : ''), $costs ? Ui::k($q['hw_cost']) : ''); ?>
            <?php foreach ($q['os'] as $g) echo $item('os', e($g['label']) . ' ends · ' . count($g['devices'])); ?>
            <?php if ($q['warranty']) echo $item('warranty', count($q['warranty']) . ' warrant' . (count($q['warranty']) > 1 ? 'ies' : 'y') . ' end'); ?>
            <?php if ($q['compliance']) echo $item('compliance', count($q['compliance']) . ' compliance item' . (count($q['compliance']) > 1 ? 's' : '') . ' due'); ?>
            <?php foreach ($q['meetings'] as $m) echo $item('meeting', e($m['title']) . ' · ' . e(\Align\Fmt::date($m['starts_at'], 'short'))); ?>
            <?php if ($empty): ?><div class="tl-empty"><?= $q['past'] ? '' : 'Nothing planned' ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

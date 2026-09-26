<?php
use Align\Reports\Ui;

/** @var array $r ReportData::roadmap(); bool $costs; ?string $num */
$plan = $r['plan'];
$curYear = $plan['quarters'][$r['currentIndex']]['year'];
$bars = array_map(fn($q) => ['label' => $q['short'], 'value' => $q['hw_cost'] + $q['item_cost'], 'past' => $q['past'], 'current' => $q['current'],
    'parts' => [[$q['hw_cost'], '#8a97aa'], [$q['item_cost'], 'var(--brand)']]], array_values($plan['quarters']));
?>
<section class="rsection avoid-break">
  <?= Ui::head('Three-year plan', $num ?? null, $costs ? money($plan['grand']) . ' over three years' : '') ?>
  <p class="lede">Projects and equipment replacements, placed in the quarter they're planned. Operating system and warranty dates show when action is needed.</p>
  <div class="year-row">
    <?php foreach ($plan['years'] as $y => $yr): ?>
      <div class="year-tile <?= $y === $curYear ? 'is-current' : '' ?>">
        <div class="yt-lbl"><?= e($yr['label']) ?> · <?= e($yr['range']) ?></div>
        <?php if ($costs): ?>
          <div class="yt-val"><?= money($yr['total']) ?></div>
          <div class="yt-sub"><?= money($yr['hw_cost']) ?> hardware · <?= money($yr['item_cost']) ?> projects<?= $yr['recurring'] ? ' · +' . money($yr['recurring']) . '/mo' : '' ?></div>
          <div class="yt-bar"><?php if ($yr['total']): ?><i style="width:<?= round($yr['hw_cost'] / $yr['total'] * 100, 1) ?>%;background:#8a97aa"></i><i style="width:<?= round($yr['item_cost'] / $yr['total'] * 100, 1) ?>%;background:var(--brand)"></i><?php endif; ?></div>
        <?php else: ?>
          <div class="yt-val"><?= (int) $yr['item_count'] ?> project<?= $yr['item_count'] == 1 ? '' : 's' ?></div>
          <div class="yt-sub"><?= (int) $yr['hw_count'] ?> device replacement<?= $yr['hw_count'] == 1 ? '' : 's' ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($costs): ?>
    <?= Ui::quarterChart($bars, array_column($plan['years'], 'label')) ?>
    <div class="legend"><span><i style="background:#8a97aa"></i>Hardware replacements</span><span><i style="background:var(--brand)"></i>Projects</span><span class="muted">Declined projects are left out. Recurring costs are the monthly total of approved projects by year end.</span></div>
  <?php endif; ?>
</section>

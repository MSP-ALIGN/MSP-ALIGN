<?php
use Align\Reports\Ui;

/** @var array $a; bool $costs; ?string $num */
$bars = array_map(fn($b) => ['label' => $b['short'], 'value' => $costs ? $b['cost'] : $b['count'], 'past' => $b['past'], 'current' => $b['current'], 'sub' => $costs && $b['count'] ? $b['count'] . ' dev' : ''], array_values($a['forecast']));
$curYear = \Align\Roadmap\Plan::quarters()[\Align\Roadmap\Plan::currentIndex()]['year'];
?>
<?php if ($planChart ?? true): ?>
<section class="rsection avoid-break">
  <?= Ui::head('Replacement plan', $num ?? null, 'Hardware reaching end of life, by quarter') ?>
  <div class="year-row">
    <?php foreach ($a['years'] as $y => $yr): ?>
      <div class="year-tile <?= $y === $curYear ? 'is-current' : '' ?>">
        <div class="yt-lbl"><?= e($yr['label']) ?> · <?= e($yr['range']) ?></div>
        <div class="yt-val"><?= $costs ? money($yr['cost']) : (int) $yr['count'] . ' devices' ?></div>
        <div class="yt-sub"><?= (int) $yr['count'] ?> device<?= $yr['count'] == 1 ? '' : 's' ?> to replace<?= $y === $curYear && $a['summary']['replace'] ? ', including ' . (int) $a['summary']['replace'] . ' overdue' : '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?= Ui::quarterChart($bars, array_column($a['years'], 'label'), true, $costs ? null : fn($v) => (string) (int) $v) ?>
  <p class="footnote"><?= $costs ? 'Estimated replacement cost per quarter. ' : 'Devices reaching end of life per quarter. ' ?>Devices already past end of life are counted in the current quarter. Lifespans: <?= e(implode(', ', array_map(fn($c, $l) => \Align\Lifecycle\Lifecycle::CLASSES[$c] . ' ' . $l . ' yrs', array_keys($a['policy']['lifespan']), $a['policy']['lifespan']))) ?>.</p>
</section>
<?php endif; ?>
<?php if ($a['issues']): ?>
<section class="rsection avoid-break">
  <h3>Priorities</h3>
  <table class="rtable">
    <thead><tr><th>Finding</th><th>What it means</th><th class="num">Devices</th><?php if ($costs): ?><th class="num">Est. cost</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($a['issues'] as $i): ?>
      <tr><td class="name"><?= Ui::pill(['bad' => 'Now', 'warn' => 'Soon', 'muted' => 'Note'][$i['tone']], $i['tone']) ?> <?= e($i['title']) ?></td><td class="muted"><?= e($i['desc']) ?></td>
        <td class="num"><?= (int) $i['count'] ?></td><?php if ($costs): ?><td class="num"><?= in_array($i['key'], ['replace', 'plan'], true) && $i['cost'] ? money($i['cost']) : '—' ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

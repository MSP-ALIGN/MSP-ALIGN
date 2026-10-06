<?php
use Align\Alignment\Alignment;
use Align\Reports\Ui;

/**
 * 2.3.0 the QBR section "Alignment with our standards": score and band, the last few reviews, and the gaps with the
 * plan for each (the roadmap project made for it). Client-facing: titles and "why it matters" only, no staff notes.
 * @var array $al (ReportData::alignment), $client; string $company; ?string $num
 */
$sc = $al['score'];
$tone = fn(?int $s) => $s === null ? 'muted' : ($s >= 80 ? 'ok' : ($s >= 60 ? 'warn' : 'bad'));
$prio = ['critical' => 'bad', 'high' => 'warn', 'medium' => 'info', 'low' => 'muted'];
$planned = count(array_filter($al['gaps'], fn($g) => $g['project']));
$hist = array_reverse(array_values(array_filter($al['history'], fn($h) => $h['score'] !== null)));
?>
<section class="rsection">
  <?= Ui::head('Alignment with our standards', $num ?? null, 'Reviewed ' . fmt_date($al['review']['finished_at'])) ?>
  <p class="lede">How <?= e($client['name']) ?> measures against the standards <?= $company !== '' && $company !== 'Your company' ? e($company) . ' sets' : 'we set' ?> for every client: how we believe your technology should be set up.</p>
  <div class="kpi-row cols-3">
    <?= Ui::kpi($sc['score'] !== null ? $sc['score'] . '%' : '–', 'Alignment', $sc['band'], $tone($sc['score'])) ?>
    <?= Ui::kpi((string) count($al['gaps']), 'Gaps', $planned . ' already planned', count($al['gaps']) ? 'warn' : 'ok') ?>
    <?= Ui::kpi((string) (int) $al['review']['aligned'], 'Standards met', 'of ' . (int) ($al['review']['aligned'] + $al['review']['misaligned']) . ' that apply' . ($al['na'] ? ' · ' . $al['na'] . ' not applicable' : ''), 'muted') ?>
  </div>
  <?php if (count($hist) > 1): ?>
    <h3>Progress</h3>
    <table class="rtable compact">
      <tbody>
      <?php foreach ($hist as $h): $s = (int) $h['score']; ?>
        <tr><td class="nowrap" style="width:18%"><?= e(fmt_date($h['finished_at'])) ?></td>
          <td><?= Ui::hbar([[$s, 'b-' . $tone($s)], [100 - $s, 'b-muted']], 100) ?></td><td class="num" style="width:10%"><b><?= $s ?>%</b></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if ($al['gaps']): ?>
    <h3>Gaps and the plan</h3>
    <table class="rtable">
      <thead><tr><th style="width:12%">Priority</th><th>Standard</th><th style="width:26%">Plan</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($al['gaps'], 0, 12) as $g): $p = $g['project']; ?>
        <tr><td><?= Ui::pill(Alignment::PRIORITIES[$g['priority']][0] ?? '', $prio[$g['priority']] ?? 'muted') ?></td>
          <td class="name"><?= e($g['title']) ?><?php if ($g['why']): ?><div class="sub"><?= e($g['why']) ?></div><?php endif; ?></td>
          <td><?= $p ? e($p['title']) . '<div class="sub">' . e(\Align\Roadmap\Roadmap::STATUSES[$p['status']][0] ?? $p['status']) . ($p['target_quarter'] ? ' · ' . e(\Align\Roadmap\Plan::quarterFor($p['target_quarter'])['label'] ?? '') : '') . '</div>' : '<span class="muted">To discuss</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($al['gaps']) > 12): ?><p class="muted"><?= count($al['gaps']) - 12 ?> smaller gaps aren't listed here; ask us for the full review.</p><?php endif; ?>
  <?php else: ?>
    <p>Every standard that applies is met. We'll keep checking at each review.</p>
  <?php endif; ?>
</section>

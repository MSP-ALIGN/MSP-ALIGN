<?php
/**
 * 2.5.0 The QBR's health headline, at the top of the executive summary: the score and band, the change since the
 * last business review, each area's score as a bar, and the two weakest areas (with what pulls them down in staff
 * packs; the portal's pack gets Health::forPortal(), without the alignment area or the lines). Areas whose section is
 * switched off were taken out by ReportController::renderQbr().
 * @var array $h Health result; ?array $since Health::sinceReview()
 * Values are numbers, fixed labels or text the MSP typed (framework names): escaped here.
 */
use Align\Health\Health;
use Align\Reports\Ui;

$t = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'bad'];
$weak = array_filter($h['pillars'], fn($p) => $p['score'] !== null && $p['weight'] > 0);
uasort($weak, fn($a, $b) => $a['score'] <=> $b['score']);
$weak = array_slice($weak, 0, 2, true);
?>
<div class="health-head">
  <?= Ui::kpi($h['score'] !== null ? (string) $h['score'] : '–', 'Technology health', $h['band'] . ($since && $since['change'] !== null
      ? ' · ' . ($since['change'] > 0 ? 'up ' . $since['change'] : ($since['change'] < 0 ? 'down ' . abs($since['change']) : 'no change')) . ' since ' . \Align\Fmt::date($since['date'], 'short') : ''), $t[$h['tone']] ?? 'muted') ?>
  <div class="health-bars">
    <?php // each area that has a score, as a bar out of 100 ?>
    <?php foreach ($h['pillars'] as $p): if ($p['score'] === null || $p['weight'] <= 0) continue; $pt = $t[Health::band($p['score'])[1]] ?? 'muted'; ?>
      <div class="health-bar"><span><?= e($p['label']) ?></span><?= Ui::hbar([[$p['score'], 'b-' . $pt], [100 - $p['score'], 'b-muted']], 100) ?><b><?= (int) $p['score'] ?></b></div>
    <?php endforeach; ?>
  </div>
</div>
<?php if ($weak && reset($weak)['score'] < Health::thresholds()[0]): ?>
  <p class="health-weak"><b>Where to focus:</b>
    <?= e(implode('; ', array_map(fn($p) => $p['label'] . ' (' . $p['score'] . ')' . ($p['lines'] ? ': ' . implode(', ', array_map(fn($l) => $l[0], array_slice($p['lines'], 0, 2))) : ''),
        array_filter($weak, fn($p) => $p['score'] < Health::thresholds()[0])))) ?>.</p>
<?php endif; ?>

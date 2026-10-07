<?php
/**
 * 2.5.0 The client health card: the score and band, the trend (last 90 days), the change since the last business
 * review, and each area's score with what pulls it down. Used on the staff client page and the client portal home.
 * @var array $h Health::forClient() (Health::forPortal() in the portal); array $trend Health::history();
 * @var ?array $since Health::sinceReview(); bool $staff (links to each area and to the settings; the portal's
 * card has neither and says the score counts the alignment review it doesn't show)
 * Every value is a number, a fixed label or text the MSP typed (framework names): escaped here.
 */
use Align\Health\Health;

$staff = !empty($staff);
$cardClass = $staff ? 'card-dark' : ''; // the portal's cards are light
$points = array_values(array_filter($trend, fn($t) => $t['score'] !== null));
?>
<div class="card <?= $cardClass ?> health-card" id="overview-health">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-heart-pulse me-2<?= $staff ? '' : ' text-secondary' ?>"></i><?= $staff ? 'Health' : 'Technology health score' ?></h3>
    <?php if ($staff && \Align\Auth::can('admin')): ?><div class="card-tools"><a href="/settings/planning#settings-health" class="btn btn-tool" title="Weights and bands"><i class="fas fa-sliders"></i><span class="visually-hidden">Weights and bands</span></a></div><?php endif; ?>
  </div>
  <div class="card-body">
    <?php // The score, its band, and where it has been ?>
    <div class="d-flex align-items-center flex-wrap gap-3 mb-2">
      <div class="score-ring text-<?= e($h['tone']) ?>"><b><?= $h['score'] !== null ? (int) $h['score'] : '–' ?></b></div>
      <div class="me-auto">
        <span class="badge text-bg-<?= e($h['tone']) ?>"><?= e($h['band']) ?></span>
        <div class="small text-muted mt-1">
          <?php // "Based on" counts every weighted area, alignment too, so the portal (which lists one fewer) leaves it out ?>
          <?php if ($h['score'] === null): ?>No counted area has data yet.<?php elseif ($staff): ?>Based on <?= (int) $h['counted'] ?> of <?= (int) $h['of'] ?> area<?= $h['of'] === 1 ? '' : 's' ?><?php else: ?>Updated daily<?php endif; ?>
          <?php if ($since && $since['change'] !== null): ?> ·
            <span class="<?= $since['change'] > 0 ? 'text-success' : ($since['change'] < 0 ? 'text-danger' : '') ?>"><?= $since['change'] > 0 ? 'up ' . (int) $since['change'] : ($since['change'] < 0 ? 'down ' . abs((int) $since['change']) : 'no change') ?> since the last review (<?= e(fmt_date($since['date'])) ?>, <?= (int) $since['score'] ?>)</span>
          <?php endif; ?>
        </div>
      </div>
      <?php if (count($points) > 1):
          // Inline trend line: x by day across the period; y from the lowest to the highest score shown (at least a
          // 20-point range, as on the alignment page), so a change of a few points is visible
          $first = strtotime($points[0]['day']);
          $span = max(1, strtotime(end($points)['day']) - $first);
          $vals = array_column($points, 'score');
          $lo = max(0, min($vals) - 5);
          $hi = min(100, max(max($vals) + 5, $lo + 20));
          $y = fn(int $s) => round(40 - ($s - $lo) / max(1, $hi - $lo) * 36, 1);
          $xy = array_map(fn($t) => round((strtotime($t['day']) - $first) / $span * 156 + 2, 1) . ',' . $y((int) $t['score']), $points);
          $good = Health::thresholds()[0]; ?>
        <svg class="health-spark text-<?= e($h['tone']) ?>" viewBox="0 0 160 44" role="img" aria-label="<?= e('Health over the last 90 days: from ' . $points[0]['score'] . ' to ' . end($points)['score']) ?>">
          <?php // a faint line where Healthy starts, when it falls inside the range shown ?>
          <?php if ($good >= $lo && $good <= $hi): ?><line x1="0" x2="160" y1="<?= $y($good) ?>" y2="<?= $y($good) ?>" class="health-spark-band"/><?php endif; ?>
          <polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" points="<?= e(implode(' ', $xy)) ?>"/>
        </svg>
      <?php endif; ?>
    </div>

    <?php // Each area: score, bar, and what pulls it down ?>
    <div class="health-areas">
      <?php foreach ($h['pillars'] as $key => $p):
          $tone = $p['score'] === null ? 'secondary' : Health::band($p['score'])[1]; ?>
        <div class="health-area<?= $p['weight'] <= 0 ? ' text-muted' : '' ?>">
          <div class="d-flex align-items-center">
            <i class="fas fa-fw <?= e($p['icon']) ?> text-muted me-2"></i>
            <?php if ($staff && $p['link']): ?><a href="<?= e($p['link']) ?>" class="me-auto"><?= e($p['label']) ?></a><?php else: ?><span class="me-auto"><?= e($p['label']) ?></span><?php endif; ?>
            <?php if ($p['weight'] <= 0): ?><span class="text-muted small ms-2">not counted</span><?php endif; ?>
            <b class="ms-2"><?= $p['score'] !== null ? (int) $p['score'] : '<span class="text-muted fw-normal small">no data</span>' ?></b>
          </div>
          <?php if ($p['score'] !== null): ?><div class="progress progress-xs mt-1"><div class="progress-bar bg-<?= e($tone) ?>" style="width: <?= (int) $p['score'] ?>%"></div></div><?php endif; ?>
          <?php foreach ($p['lines'] as [$text, $lt]): ?><div class="small text-muted"><i class="fas fa-circle fa-2xs text-<?= e(tone_class($lt)) ?> me-1"></i><?= e($text) ?></div><?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$staff && ($h['scores']['alignment'] ?? null) !== null && Health::weights()['alignment'] > 0): ?>
      <p class="small text-muted mb-0 mt-2">The score also counts our review of your setup against our standards, which we go through with you in your business review.</p>
    <?php endif; ?>
  </div>
</div>

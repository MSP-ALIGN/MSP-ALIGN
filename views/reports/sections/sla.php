<?php
use Align\Reports\Ui;
use Align\Service\Sla;

/**
 * Service levels (the PSA ticket SLAs). Client-facing.
 * @var array $s Sla::report(); ?string $num; bool $missed (list missed tickets); ?bool $pageBreak
 */
$st = $s['stats'];
$pr = $s['prior'];
$missed ??= true;
$pill = fn(?float $p) => $p === null ? '<span class="muted">—</span>' : Ui::pill(Sla::pct($p), ['success' => 'ok', 'warning' => 'warn', 'danger' => 'bad', 'secondary' => 'muted'][Sla::tone($p)]);
$tone = fn(?float $p) => ['success' => 'ok', 'warning' => 'warn', 'danger' => 'bad', 'secondary' => 'muted'][Sla::tone($p)];
$delta = function (?float $now, ?float $before): string {
    if ($now === null || $before === null) {
        return '';
    }
    $d = round($now - $before, 1);
    return abs($d) < 0.5 ? 'same as the period before' : ($d > 0 ? '▲ ' : '▼ ') . \Align\Fmt::trim(abs($d), 1) . ' pts vs. the period before';
};
?>
<section class="rsection">
  <?= Ui::head('Service levels', $num ?? null, $s['label'] . ($s['synced'] ? ' · ' . psa_name() . ' · updated ' . rel_time($s['synced']) : '')) ?>
  <p class="lede">How quickly we answered and resolved your support tickets against the response and resolution targets in your service agreement. Targets are measured in business hours; time a ticket spends waiting on you doesn't count.</p>
  <?php if (!$s['hasSla']): ?>
    <p class="muted">No SLA targets apply to your tickets yet, so there's nothing to measure. <?= $st['tickets'] ?> ticket<?= $st['tickets'] == 1 ? '' : 's' ?> in this period.</p>
  <?php else: ?>
  <div class="kpi-row">
    <?= Ui::kpi(Sla::pct($st['resp_pct']), 'Responded on time', $st['resp_met'] + $st['resp_missed'] ? $st['resp_met'] . ' of ' . ($st['resp_met'] + $st['resp_missed']) . ' tickets' . (($d = $delta($st['resp_pct'], $pr['resp_pct'])) ? ' · ' . $d : '') : 'no results yet', $tone($st['resp_pct'])) ?>
    <?= Ui::kpi(Sla::pct($st['res_pct']), 'Resolved on time', $st['res_met'] + $st['res_missed'] ? $st['res_met'] . ' of ' . ($st['res_met'] + $st['res_missed']) . ' tickets' . (($d = $delta($st['res_pct'], $pr['res_pct'])) ? ' · ' . $d : '') : 'no results yet', $tone($st['res_pct'])) ?>
    <?= Ui::kpi((string) $st['tickets'], 'Tickets opened', $pr['tickets'] ? $pr['tickets'] . ' in the period before' : '', 'muted') ?>
    <?= Ui::kpi(Sla::duration($st['avg_response_min']), 'Average first response', 'clock time, not business hours', 'muted') ?>
  </div>

  <?php if ($s['open']['with_sla']): ?>
    <p class="small muted">Open right now: <?= $s['open']['open_total'] ?> ticket<?= $s['open']['open_total'] == 1 ? '' : 's' ?><?= $s['open']['breached'] ? ', ' . $s['open']['breached'] . ' past target' : '' ?><?= $s['open']['warning'] ? ', ' . $s['open']['warning'] . ' close to target' : '' ?>.</p>
  <?php endif; ?>

  <h3>Month by month</h3>
  <?= Ui::slaChart($s['monthly'], $s['target']) ?>
  <div class="legend"><span><i style="background:#2f9e62"></i>At or above goal</span><span><i style="background:#d69a16"></i>Within 10 points</span><span><i style="background:#d64541"></i>Below</span><span class="muted">Share of response and resolution targets met · tickets opened under each month</span></div>

  <?php if ($s['priority']): ?>
    <h3>By priority</h3>
    <table class="rtable fixed compact">
      <?= Ui::cols(['p' => 18, 't' => 12, 'r' => 18, 'rs' => 18, 'ar' => 17, 'av' => 17]) ?>
      <thead><tr><th>Priority</th><th class="num">Tickets</th><th class="num">Responded on time</th><th class="num">Resolved on time</th><th class="num">Avg. first response</th><th class="num">Avg. time to resolve</th></tr></thead>
      <tbody>
      <?php foreach ($s['priority'] as $p): ?>
        <tr><td><?= e($p['priority']) ?></td><td class="num"><?= $p['tickets'] ?></td><td class="num"><?= $pill($p['resp_pct']) ?></td><td class="num"><?= $pill($p['res_pct']) ?></td>
          <td class="num"><?= e(Sla::duration($p['avg_response_min'])) ?></td><td class="num"><?= e(Sla::duration($p['avg_resolution_min'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($missed && $s['missed']): ?>
    <h3>Tickets that missed a target</h3>
    <table class="rtable fixed compact">
      <?= Ui::cols(['n' => 12, 'd' => 13, 'p' => 11, 's' => 46, 'm' => 18]) ?>
      <thead><tr><th>Ticket</th><th>Opened</th><th>Priority</th><th>Subject</th><th>Missed</th></tr></thead>
      <tbody>
      <?php foreach ($s['missed'] as $t): ?>
        <tr><td class="nowrap"><?= e($t['number'] ?: '#' . $t['id']) ?></td><td class="nowrap"><?= e(fmt_date($t['created_at'])) ?></td><td><?= e($t['priority'] ?? '') ?></td>
          <td><?= e(mb_strimwidth((string) $t['subject'], 0, 90, '…')) ?></td>
          <td><?= e(implode(' & ', array_filter([(string) $t['response_met'] === '0' ? 'Response' : null, (string) $t['resolution_met'] === '0' ? 'Resolution' : null]))) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($s['missed']) >= 25 && $st['missed'] > 25): ?><p class="small muted">Showing the 25 most recent.</p><?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
</section>

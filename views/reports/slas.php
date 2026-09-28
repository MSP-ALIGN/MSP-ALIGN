<?php
use Align\Reports\Ui;
use Align\Service\Sla;

/** @var array $rows Sla::allClients(); array $total; array $open; array $openList; array $monthly; int $target; ?bool $supported */
$tone = fn(?float $p) => ['success' => 'ok', 'warning' => 'warn', 'danger' => 'bad', 'secondary' => 'muted'][Sla::tone($p)];
$pill = fn(?float $p) => $p === null ? '<span class="muted">—</span>' : Ui::pill(Sla::pct($p), $tone($p));
$below = array_filter($rows, fn($r) => $r['overall_pct'] !== null && $r['overall_pct'] < $target);
?>
<section class="rsection">
  <?= Ui::head('Across all clients') ?>
  <?php if ($supported === false): ?><p class="muted"><?= e(ucfirst(\Align\Providers\Providers::psaConnector()?->noSlaMessage() ?? 'no SLA fields')) ?>.</p><?php endif; ?>
  <div class="kpi-row">
    <?= Ui::kpi(Sla::pct($total['resp_pct']), 'Responded on time', $total['resp_met'] . ' of ' . ($total['resp_met'] + $total['resp_missed']), $tone($total['resp_pct'])) ?>
    <?= Ui::kpi(Sla::pct($total['res_pct']), 'Resolved on time', $total['res_met'] . ' of ' . ($total['res_met'] + $total['res_missed']), $tone($total['res_pct'])) ?>
    <?= Ui::kpi((string) count($below), 'Clients below goal', 'goal ' . $target . '% of targets met', $below ? 'warn' : 'ok') ?>
    <?= Ui::kpi((string) $open['breached'], 'Open tickets past target', $open['warning'] . ' close to target · ' . $open['open_total'] . ' open', $open['breached'] ? 'bad' : ($open['warning'] ? 'warn' : 'ok')) ?>
  </div>
  <h3>Month by month</h3>
  <?= Ui::slaChart($monthly, $target) ?>

  <h3>By client</h3>
  <table class="rtable fixed compact">
    <?= Ui::cols(['c' => 30, 't' => 9, 'r' => 13, 'rs' => 13, 'm' => 9, 'o' => 14, 'a' => 12]) ?>
    <thead><tr><th>Client</th><th class="num">Tickets</th><th class="num">Response</th><th class="num">Resolution</th><th class="num">Missed</th><th class="num">Open past / close</th><th class="num">Avg. response</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $cid => $r): ?>
      <tr><td><a href="/clients/<?= (int) $cid ?>/service-levels" class="name"><?= e($r['name']) ?></a></td><td class="num"><?= $r['tickets'] ?></td>
        <?php if (!$r['with_sla'] && $r['tickets']): ?><td class="num muted" colspan="2">no SLA assigned</td><?php else: ?><td class="num"><?= $pill($r['resp_pct']) ?></td><td class="num"><?= $pill($r['res_pct']) ?></td><?php endif; ?><td class="num"><?= $r['missed'] ?: '—' ?></td>
        <td class="num"><?= $r['breached_open'] ? Ui::pill((string) $r['breached_open'], 'bad') : '0' ?> / <?= $r['warning_open'] ? Ui::pill((string) $r['warning_open'], 'warn') : '0' ?></td>
        <td class="num"><?= e(Sla::duration($r['avg_response_min'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No tickets in this period.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php $late = array_values(array_filter($openList, fn($t) => $t['state'] !== 'ok')); if ($late): ?>
    <h3>Open tickets past or close to target</h3>
    <table class="rtable fixed compact">
      <?= Ui::cols(['n' => 11, 'c' => 22, 's' => 37, 'p' => 10, 'st' => 20]) ?>
      <thead><tr><th>Ticket</th><th>Client</th><th>Subject</th><th>Priority</th><th>State</th></tr></thead>
      <tbody>
      <?php foreach ($late as $t): $url = Sla::ticketUrl((int) $t['id']); ?>
        <tr><td class="nowrap"><?= $url ? '<a href="' . e($url) . '" target="_blank" rel="noopener">' . e($t['number'] ?: '#' . $t['id']) . '</a>' : e($t['number'] ?: '#' . $t['id']) ?></td><td><?= e($t['client_name']) ?></td>
          <td><?= e(mb_strimwidth((string) $t['subject'], 0, 80, '…')) ?></td><td><?= e($t['priority'] ?? '') ?></td>
          <td><?= $t['state'] === 'breached' ? Ui::pill($t['clock'] . ' past target', 'bad') : Ui::pill($t['clock'] . ' due ' . Sla::relative($t['due']), 'warn') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

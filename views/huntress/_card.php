<?php
/**
 * 2.7.0 The Huntress card on a client's overview: the checks (agents, incidents, antivirus, identities, external
 * ports), then the detail behind them: devices missing an agent or not checking in, open incidents and escalations,
 * risky ports and the latest summary reports (PDF links to Huntress).
 * @var array $client; array $h Huntress\Clients::forClient()
 * Security: host names, subjects, services and the organization's name come from Huntress and are escaped; report
 * links were checked to be https by Huntress\Sync and open in a new tab without a referrer. Viewers see the same
 * card (it's the client's own security picture).
 */
use Align\Huntress\Clients;

$icon = ['pass' => 'fa-circle-check text-success', 'fail' => 'fa-circle-xmark text-danger', 'unknown' => 'fa-circle-question text-secondary'];
$sev = ['critical' => 'danger', 'high' => 'warning', 'low' => 'secondary'];
$cov = $h['coverage'];
$type = ['monthly_summary' => 'Monthly', 'quarterly_summary' => 'Quarterly', 'yearly_summary' => 'Yearly'];
?>
<div class="card card-dark" id="huntress">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-shield-virus me-2"></i>Huntress <span class="text-muted small fw-normal">· <?= e($h['org']['name']) ?></span></h3>
    <div class="card-tools small text-muted mt-1"><?= count($cov['agents']) ?> agents<?= $h['org']['synced_at'] ? ' · synced ' . e(rel_time($h['org']['synced_at'])) : '' ?></div>
  </div>
  <div class="card-body">
    <?php // The checks (they count in the health score's Security area) ?>
    <ul class="list-unstyled small mb-2">
      <?php foreach (Clients::CHECKS as $k => $label): $c = $h['checks'][$k] ?? ['status' => 'unknown', 'detail' => '']; ?>
        <li class="mb-1"><i class="fas fa-fw <?= $icon[$c['status']] ?? $icon['unknown'] ?> me-1"></i><b><?= e($label) ?>:</b> <span class="text-muted"><?= e($c['detail']) ?></span></li>
      <?php endforeach; ?>
    </ul>

    <?php // Devices without an agent, or whose agent went quiet ?>
    <?php if ($cov['missing'] || $cov['offline']): ?>
      <details class="small mb-2"><summary><?= count($cov['missing']) ?> without Huntress, <?= count($cov['offline']) ?> not checking in</summary>
        <ul class="mb-0 mt-1">
          <?php foreach ($cov['missing'] as $d): ?><li><a href="/devices/<?= (int) $d['id'] ?>"><?= e($d['name']) ?></a> <span class="text-muted">(<?= e($d['class']) ?>, no agent)</span></li><?php endforeach; ?>
          <?php foreach ($cov['offline'] as $d): ?><li><a href="/devices/<?= (int) $d['id'] ?>"><?= e($d['name']) ?></a> <span class="text-muted">(last check-in <?= $d['last'] ? e(rel_time($d['last'])) : 'never' ?>)</span></li><?php endforeach; ?>
        </ul></details>
    <?php endif; ?>

    <?php // Open incidents and escalations ?>
    <?php if ($h['open'] || $h['escalations']): ?>
      <div class="small mb-2"><b>Open</b>
        <ul class="mb-0">
          <?php foreach ($h['open'] as $i): ?><li><span class="badge text-bg-<?= $sev[$i['severity']] ?? 'secondary' ?>"><?= e($i['severity']) ?></span> <?= e((string) $i['subject']) ?> <span class="text-muted">· incident <?= $i['sent_at'] ? e(rel_time($i['sent_at'])) : '' ?></span></li><?php endforeach; ?>
          <?php foreach ($h['escalations'] as $x): ?><li><span class="badge text-bg-<?= $x['status'] === 'overdue' ? 'danger' : 'info' ?>"><?= $x['status'] === 'overdue' ? 'overdue' : 'escalation' ?></span> <?= e((string) $x['subject']) ?> <span class="text-muted">· <?= e((string) $x['type']) ?></span></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
    <?php $closed = array_sum($h['closed']); ?>
    <div class="small text-muted mb-2">Incidents closed in the last 12 months: <?= $closed ? implode(', ', array_map(fn($s) => (int) $h['closed'][$s] . ' ' . $s, array_keys(array_filter($h['closed'])))) : 'none' ?>.</div>

    <?php // Risky external ports ?>
    <?php if ($risky = array_filter($h['ports'], fn($p) => (int) $p['risky'] === 1)): ?>
      <div class="small mb-2"><b>Risky services open to the internet</b>
        <ul class="mb-0"><?php foreach ($risky as $p): ?><li><?= e((string) $p['service'] ?: 'Unknown service') ?> on <?= e((string) $p['ip_address']) ?>:<?= (int) $p['port'] ?>/<?= e((string) $p['protocol']) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php // Summary reports (Huntress's PDFs) ?>
    <?php if ($h['reports']): ?>
      <div class="small"><b>Summary reports</b>
        <?php foreach (array_slice($h['reports'], 0, 4) as $r): ?>
          <div><?php if ($r['url']): ?><a href="<?= e($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?php endif; ?><?= e($type[$r['type']] ?? $r['type']) ?> <?= e(fmt_date($r['period_start'])) ?> – <?= e(fmt_date($r['period_end'])) ?><?= $r['url'] ? ' <i class="fas fa-arrow-up-right-from-square fa-xs"></i></a>' : '' ?>
            <span class="text-muted">· <?= (int) $r['incidents_reported'] ?> incidents reported<?= $r['signals_investigated'] !== null ? ', ' . (int) $r['signals_investigated'] . ' signals investigated' : '' ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

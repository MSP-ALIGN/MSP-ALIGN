<?php
/**
 * 2.37.0 The DNSFilter card on a client's overview: the two checks (roaming clients, networks), the request, blocked
 * and threat totals for the last 30 and 90 days, then the detail behind the roaming client check: devices without a
 * roaming client, not synced or not filtering, and roaming clients matched to no RMM device.
 * @var array $client; array $d DNSFilter\Clients::forClient()
 * Security: the organization's name and host names come from DNSFilter (cleaned by the sync) and device names from
 * the RMM: all escaped. Numbers are cast. Viewers see the same card (it's the client's own security picture); the
 * Manage link is for techs.
 */
use Align\DNSFilter\Clients;

$icon = ['pass' => 'fa-circle-check text-success', 'fail' => 'fa-circle-xmark text-danger', 'unknown' => 'fa-circle-question text-secondary'];
$cov = $d['coverage'];
$n = $d['org']['data'];
$num = fn(string $k) => isset($n[$k]) && is_int($n[$k]) ? number_format($n[$k]) : '–';
$bad = count($cov['missing']) + count($cov['offline']) + count($cov['unfiltered']);
?>
<div class="card card-dark" id="dnsfilter">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-filter me-2"></i>DNSFilter <span class="text-muted small fw-normal">· <?= e($d['org']['name']) ?></span></h3>
    <div class="card-tools small text-muted mt-1"><?= count($cov['agents']) ?> roaming clients<?= $d['org']['synced_at'] ? ' · synced ' . e(rel_time($d['org']['synced_at'])) : '' ?><?php if (\Align\Auth::can('tech')): ?> · <a href="/clients/<?= (int) $client['id'] ?>/connectors#security">Manage</a><?php endif; ?></div>
  </div>
  <div class="card-body">
    <?php // The checks (they count in the health score's Security area) ?>
    <ul class="list-unstyled small mb-2">
      <?php foreach (Clients::CHECKS as $k => $label): $c = $d['checks'][$k]; ?>
        <li class="mb-1"><i class="fas fa-fw <?= $icon[$c['status']] ?? $icon['unknown'] ?> me-1"></i><b><?= e($label) ?>:</b> <span class="text-muted"><?= e($c['detail']) ?></span></li>
      <?php endforeach; ?>
    </ul>

    <?php // Traffic totals from DNSFilter's reports (UTC days) ?>
    <table class="table table-sm small mb-2">
      <thead><tr><th></th><th class="text-end">Last 30 days</th><th class="text-end">Last 90 days</th></tr></thead>
      <tbody>
        <tr><td>DNS requests</td><td class="text-end"><?= $num('requests_30d') ?></td><td class="text-end"><?= $num('requests_90d') ?></td></tr>
        <tr><td>Blocked</td><td class="text-end"><?= $num('blocked_30d') ?></td><td class="text-end"><?= $num('blocked_90d') ?></td></tr>
        <tr><td>Threats blocked</td><td class="text-end fw-bold"><?= $num('threats_30d') ?></td><td class="text-end fw-bold"><?= $num('threats_90d') ?></td></tr>
      </tbody>
    </table>
    <div class="small text-muted mb-2"><?= isset($n['networks']) ? (int) $n['networks'] . ' network' . ((int) $n['networks'] === 1 ? '' : 's') . ', ' . (int) ($n['networks_policy'] ?? 0) . ' with a filtering policy.' : '' ?></div>

    <?php // Devices the roaming client check counts against ?>
    <?php if ($bad): ?>
      <details class="small mb-2"><summary><?= count($cov['missing']) ?> without a roaming client, <?= count($cov['offline']) ?> not syncing, <?= count($cov['unfiltered']) ?> not filtering</summary>
        <ul class="mb-0 mt-1">
          <?php foreach ($cov['missing'] as $x): ?><li><a href="/devices/<?= (int) $x['id'] ?>"><?= e($x['name']) ?></a> <span class="text-muted">(no roaming client)</span></li><?php endforeach; ?>
          <?php foreach ($cov['offline'] as $x): ?><li><a href="/devices/<?= (int) $x['id'] ?>"><?= e($x['name']) ?></a> <span class="text-muted">(last sync <?= $x['last'] ? e(rel_time($x['last'])) : 'never' ?>)</span></li><?php endforeach; ?>
          <?php foreach ($cov['unfiltered'] as $x): ?><li><a href="/devices/<?= (int) $x['id'] ?>"><?= e($x['name']) ?></a> <span class="text-muted">(<?= e((string) $x['state']) ?>)</span></li><?php endforeach; ?>
        </ul></details>
    <?php endif; ?>
    <?php // Roaming clients with no matching RMM device (an old computer, a phone, or a name that differs) ?>
    <?php if ($cov['extra']): ?>
      <details class="small"><summary><?= count($cov['extra']) ?> roaming client<?= count($cov['extra']) === 1 ? '' : 's' ?> not matched to an RMM device</summary>
        <ul class="mb-0 mt-1"><?php foreach ($cov['extra'] as $a): ?><li><?= e((string) ($a['hostname'] ?: $a['friendly_name'] ?: 'Unnamed')) ?> <span class="text-muted">(<?= e(implode(', ', array_filter([(string) $a['agent_type'], $a['last_sync'] ? 'synced ' . rel_time($a['last_sync']) : 'never synced']))) ?>)</span></li><?php endforeach; ?></ul></details>
    <?php endif; ?>
  </div>
</div>

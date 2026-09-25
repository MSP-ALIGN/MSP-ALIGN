<?php
$tiles = [
    ['Devices tracked', $summary['total'], '', null],
    ['Replace now', $summary['replace'], 'bad', 'replace'],
    ['Unsupported OS', $summary['os_eos'], 'bad', 'os'],
    ['Plan within ' . \Align\Settings::int('eol_plan_months', 12) . ' mo', $summary['plan'], 'warn', 'replace'],
    ['Warranty expiring', $summary['warranty_soon'], 'warn', 'warranty'],
    ['Out of warranty', $summary['warranty_expired'], 'warn', 'warranty'],
];
?>
<header class="page-head">
  <h1>Dashboard</h1>
  <div class="muted">
    <?php if ($lastSync): ?>
      Last sync <a href="/sync/<?= (int) $lastSync['id'] ?>"><?= e(rel_time($lastSync['started_at'])) ?></a>
      <span class="badge tone-<?= $lastSync['status'] === 'success' ? 'ok' : ($lastSync['status'] === 'running' ? 'muted' : 'warn') ?>"><?= e($lastSync['status']) ?></span>
    <?php else: ?>No sync has run yet.<?php endif; ?>
  </div>
</header>

<?php if (!$configured): ?>
  <div class="card callout">
    <h2>Finish setup</h2>
    <ol>
      <li>Add your NinjaOne and ITFlow API credentials under <a href="/settings">Settings</a> and use the Test buttons.</li>
      <li>Run a <a href="/sync">sync</a>. Clients with the same name in both systems link automatically.</li>
      <li>Link anything left over on <a href="/mapping">Client mapping</a>.</li>
    </ol>
  </div>
<?php endif; ?>

<?php if ($unmapped || $unassigned): ?>
  <div class="flash flash-info">
    <?= $unmapped ? (int) $unmapped . ' ITFlow client(s) have no NinjaOne organization' : '' ?>
    <?= $unmapped && $unassigned ? ' · ' : '' ?>
    <?= $unassigned ? (int) $unassigned . ' NinjaOne device(s) belong to an unlinked organization' : '' ?>
    — <a href="/mapping">review mapping</a>
  </div>
<?php endif; ?>

<section class="tiles">
  <?php foreach ($tiles as [$label, $value, $tone, $filter]): ?>
    <div class="tile <?= $tone && $value ? "tone-$tone" : '' ?>">
      <div class="tile-val"><?= number_format((int) $value) ?></div>
      <div class="tile-lbl"><?= e($label) ?></div>
    </div>
  <?php endforeach; ?>
</section>

<?php require __DIR__ . '/partials/forecast.php'; ?>

<div class="card">
  <div class="card-head"><h2>Clients needing attention</h2><a href="/clients">All clients</a></div>
  <?php if (!$topClients): ?>
    <p class="muted">Nothing flagged. <?= $summary['total'] ? 'Every device is within policy.' : 'Run a sync to load devices.' ?></p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Client</th><th class="num">Devices</th><th class="num">Replace / unsupported</th><th class="num">Need attention</th></tr></thead>
      <tbody>
      <?php foreach ($topClients as $c): ?>
        <tr>
          <td><a href="/clients/<?= (int) $c['id'] ?>?filter=attention"><?= e($c['name']) ?></a></td>
          <td class="num"><?= (int) $c['total'] ?></td>
          <td class="num"><?= $c['replace'] ? '<span class="badge tone-bad">' . (int) $c['replace'] . '</span>' : '0' ?></td>
          <td class="num"><?= (int) $c['attention'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

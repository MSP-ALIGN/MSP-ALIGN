<?php
$filters = ['' => 'All', 'attention' => 'Needs attention', 'replace' => 'Replace / plan', 'os' => 'OS support', 'warranty' => 'Warranty', 'stale' => 'Stale'];
$classes = ['' => 'All types', 'desktop' => 'Desktops', 'laptop' => 'Laptops', 'server' => 'Servers', 'network' => 'Network', 'virtual' => 'Virtual', 'other' => 'Other'];
$link = fn(array $over) => '/clients/' . (int) $client['id'] . '?' . http_build_query(array_filter(array_merge(['filter' => $filter, 'class' => $class], $over)));
?>
<header class="page-head">
  <div>
    <div class="crumbs"><a href="/clients">Clients</a></div>
    <h1><?= e($client['name']) ?></h1>
    <div class="muted small">
      NinjaOne: <?= $client['org_name'] ? e($client['org_name']) : '<a href="/mapping">not linked</a>' ?>
      <?php if ($itflowUrl): ?> · <a href="<?= e(rtrim($itflowUrl, '/') . '/agent/client_overview.php?client_id=' . (int) $client['itflow_client_id']) ?>" target="_blank" rel="noopener">Open in ITFlow ↗</a><?php endif; ?>
    </div>
  </div>
  <a class="btn" href="/clients/<?= (int) $client['id'] ?>/export">Export CSV</a>
</header>

<section class="tiles">
  <div class="tile"><div class="tile-val"><?= (int) $summary['total'] ?></div><div class="tile-lbl">Devices</div></div>
  <div class="tile <?= $summary['replace'] ? 'tone-bad' : '' ?>"><div class="tile-val"><?= (int) $summary['replace'] ?></div><div class="tile-lbl">Replace now</div></div>
  <div class="tile <?= $summary['os_eos'] ? 'tone-bad' : '' ?>"><div class="tile-val"><?= (int) $summary['os_eos'] ?></div><div class="tile-lbl">Unsupported OS</div></div>
  <div class="tile <?= $summary['plan'] ? 'tone-warn' : '' ?>"><div class="tile-val"><?= (int) $summary['plan'] ?></div><div class="tile-lbl">Plan replacement</div></div>
  <div class="tile <?= $summary['warranty_expired'] + $summary['warranty_soon'] ? 'tone-warn' : '' ?>"><div class="tile-val"><?= (int) ($summary['warranty_expired'] + $summary['warranty_soon']) ?></div><div class="tile-lbl">Warranty issues</div></div>
  <div class="tile"><div class="tile-val"><?= money($summary['overdue_cost']) ?></div><div class="tile-lbl">Overdue replacement cost</div></div>
</section>

<?php require __DIR__ . '/../partials/forecast.php'; ?>

<div class="card flush">
  <div class="card-head pad">
    <div class="chips">
      <?php foreach ($filters as $k => $label): ?>
        <a class="chip <?= $filter === $k ? 'on' : '' ?>" href="<?= e($link(['filter' => $k])) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="chips">
      <?php foreach ($classes as $k => $label): ?>
        <a class="chip <?= $class === $k ? 'on' : '' ?>" href="<?= e($link(['class' => $k])) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <table class="table">
    <thead><tr>
      <th>Device</th><th>Type</th><th>Model</th><th>OS</th><th>In service</th><th>Warranty</th><th>End of life</th><th>Status</th><th class="num">Est. cost</th>
    </tr></thead>
    <tbody>
    <?php foreach ($devices as $d): ?>
      <tr>
        <td class="nowrap"><a href="/devices/<?= (int) $d['id'] ?>"><?= e($d['name']) ?></a><div class="muted small"><?= e($d['serial'] ?? '') ?></div></td>
        <td><?= e(ucfirst($d['device_class'])) ?></td>
        <td><?= e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) ?: '<span class="muted">—</span>' ?></td>
        <td><?= e($d['os_name'] ?? '') ?><?php if ($d['os_rule']): ?><div class="muted small">support ends <?= e(fmt_date($d['os_rule']['eos_date'])) ?></div><?php endif; ?></td>
        <td><?= e(fmt_date($d['start_date'])) ?><?php if ($d['start_estimated']): ?> <span class="muted small" title="<?= e($d['start_source']) ?>">est.</span><?php endif; ?>
          <?php if ($d['age_years'] !== null): ?><div class="muted small"><?= e($d['age_years']) ?> yrs</div><?php endif; ?></td>
        <td><?= e(fmt_date($d['warranty_end'])) ?></td>
        <td><?= e(fmt_date($d['eol_date'])) ?></td>
        <td><?php require __DIR__ . '/../partials/status.php'; ?></td>
        <td class="num"><?= $d['is_hardware'] && $d['status'] !== 'excluded' ? money($d['replacement_cost']) : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$devices): ?><tr><td colspan="9" class="muted">No devices match.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

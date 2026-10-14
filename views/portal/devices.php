<?php
/** @var array $devices, $summary; string $filter; bool $showCosts */
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-desktop me-2 text-secondary"></i>Devices</h1>
    <div class="small text-muted">Computers, servers and network equipment, with warranty and planned replacement dates.</div></div>
  <div class="btn-group btn-group-sm mt-2 mt-md-0">
    <?php if (!empty($hasBackup)): ?><a class="btn btn-default" href="/portal/report/backup" target="_blank"><i class="fas fa-database me-1"></i>Backup report</a><?php endif; ?>
    <a class="btn btn-default" href="/portal/report/assets" target="_blank"><i class="fas fa-print me-1"></i>Print asset report</a>
  </div>
</div>

<?= \Align\View::fetch('partials/tiles', ['tiles' => [['label' => 'Devices', 'value' => (int) $summary['total']], ['label' => 'Due for replacement', 'value' => (int) $summary['replace'], 'tone' => 'danger'], ['label' => 'Replace within a year', 'value' => (int) $summary['plan'], 'tone' => 'warning'], ['label' => 'Warranty expired', 'value' => (int) $summary['warranty_expired'], 'tone' => 'secondary']]]) ?>

<div class="card">
  <div class="card-header py-2">
    <div class="btn-group btn-group-sm">
      <a href="/portal/devices" class="btn <?= $filter === '' ? 'btn-primary' : 'btn-default' ?>">All</a>
      <a href="?filter=attention" class="btn <?= $filter === 'attention' ? 'btn-primary' : 'btn-default' ?>">Needs attention</a>
      <a href="?filter=virtual" class="btn <?= $filter === 'virtual' ? 'btn-primary' : 'btn-default' ?>">Virtual</a>
    </div>
  </div>
  <div class="card-body p-0">
    <?php /* 2.18.1: fewer, stacked columns so the whole row fits without scrolling sideways; on phones each device is a card */ ?>
    <table class="table table-sm table-hover mb-0 portal-devices">
      <thead><tr><th>Device</th><th>Make / model</th><th>Operating system</th><th>Warranty and replacement</th><th>Status</th><?php if ($showCosts): ?><th class="text-end">Est. cost</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($devices as $d): ?>
        <tr>
          <td data-label="Device"><div class="fw-semibold text-break"><i class="fas fa-fw <?= e($d['icon']) ?> text-secondary me-1"></i><?= e($d['name']) ?><?= $d['is_virtual'] ? ' <span class="badge text-bg-light border">virtual</span>' : '' ?></div>
            <div class="small text-muted"><?= e($d['type']) ?><?php if ($d['last_user']): ?> · <span title="<?= e($d['last_user']) ?>"><?= e(short_user($d['last_user'])) ?></span><?php endif; ?></div></td>
          <td class="small" data-label="Make / model"><?= e(trim(short_make($d['manufacturer']) . ' ' . ($d['model'] ?? ''))) ?: '<span class="text-muted">—</span>' ?>
            <?php if (!empty($d['serial'])): ?><div class="text-muted text-break">Serial <?= e($d['serial']) ?></div><?php endif; ?></td>
          <td class="small" data-label="Operating system" title="<?= e((string) ($d['os_name'] ?: $d['firmware'])) ?>"><?= e($d['os_name'] ? os_label($d['os_name'], $d['os_build'], $d['os_rule']) : (string) $d['firmware']) ?: '<span class="text-muted">—</span>' ?><?php if ($d['os_rule']): ?><div class="text-muted">Support ends <?= e(fmt_date($d['os_rule']['eos_date'])) ?></div><?php endif; ?></td>
          <td class="small" data-label="Warranty and replacement"><div>Warranty <?= e(fmt_date($d['warranty_end'])) ?: '<span class="text-muted">unknown</span>' ?></div>
            <?php if ($d['is_hardware']): ?><div class="text-muted">Replace by <?= e(fmt_date($d['eol_date'])) ?></div><?php endif; ?><?= !empty($d['replace_planned']) ? '<div class="text-muted">Replace ' . e($d['replace_label']) . '</div>' : '' ?></td>
          <td data-label="Status"><?php require __DIR__ . '/../partials/status.php'; ?></td>
          <?php if ($showCosts): ?><td class="text-end" data-label="Est. cost"><?= $d['is_hardware'] ? money($d['replacement_cost']) : '<span class="text-muted">—</span>' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$devices): ?><tr><td colspan="6" class="empty-state">No devices match.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

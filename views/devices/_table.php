<?php
/**
 * The device table (1.42), shared by a client's Devices & assets page and the all-clients list. Seven columns by
 * default; Type, Backup, Serial, Warranty and Est. cost are in the Columns menu (views/devices/_columns.php).
 * @var array $devices  the rows to draw (already filtered and cut to the page)
 * @var bool $showClient; bool $canBulk (select for bulk replacement); bool $bkOn (backup column); array $backupMap
 * Names, serials, users, models and OS names come from the RMM or PSA and are escaped; classes come from fixed lists.
 */
use Align\Lifecycle\Lifecycle;

$showClient = $showClient ?? false;
$canBulk = $canBulk ?? false;
$bkOn = $bkOn ?? false;
$backupMap = $backupMap ?? [];
$tableId = $tableId ?? 'device-table';
$cols = 7 + ($showClient ? 1 : 0) + ($canBulk ? 1 : 0) + 4 + ($bkOn ? 1 : 0);
?>
<div class="table-responsive">
<table class="table table-sm table-striped table-borderless table-hover mb-0 device-table" id="<?= e($tableId) ?>">
  <thead class="text-dark"><tr>
    <?php if ($canBulk): ?><th style="width:1%"><input type="checkbox" data-bulk-all="<?= e($tableId) ?>" aria-label="Select all"></th><?php endif; ?>
    <th>Name</th><?php if ($showClient): ?><th>Client</th><?php endif; ?><th class="col-opt col-type">Type</th><th>Last user</th>
    <?php if ($bkOn): ?><th class="col-opt col-backup">Backup</th><?php endif; ?>
    <th>Model</th><th class="col-opt col-serial">Serial</th><th>OS</th><th>In service</th><th class="col-opt col-warranty">Warranty</th><th>End of life</th><th>Status</th>
    <th class="col-opt col-cost text-end">Est. cost</th>
  </tr></thead>
  <tbody>
  <?php foreach ($devices as $d): ?>
    <tr>
      <?php if ($canBulk): ?><td><?php if ($d['is_hardware'] && $d['status'] !== 'excluded'): ?><input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>" form="bulk-replace" data-bulk-item data-cost="<?= e((string) (float) $d['replacement_cost']) ?>"<?= !empty($d['project']) ? ' data-in-project' : '' ?> aria-label="Select <?= e($d['name']) ?>"><?php endif; ?></td><?php endif; ?>
      <td class="text-nowrap">
        <i class="fas fa-fw <?= e($d['icon']) ?> text-secondary me-1" title="<?= e($d['type']) ?>"></i><a href="/devices/<?= (int) $d['id'] ?>" class="fw-bold"><?= e($d['name']) ?></a>
        <?php if ($d['source'] === 'manual'): ?><span class="badge text-bg-light border" title="Added in Align">manual</span><?php elseif ($d['source'] === 'psa'): ?><span class="badge text-bg-light border" title="Imported from <?= e(psa_name()) ?> assets"><?= e(psa_name()) ?></span><?php endif; ?>
        <?php if ($d['type'] === Lifecycle::UNASSIGNED): ?><span class="badge text-bg-warning" title="Pick a type on the device or under Unassigned hardware">unassigned</span><?php endif; ?>
        <?php if ($d['is_virtual']): ?><span class="badge text-bg-light border" title="Virtual: OS support only">virtual</span><?php endif; ?>
        <?php if ($d['ip_address']): ?><div class="small text-muted ms-4"><?= e($d['ip_address']) ?></div><?php endif; ?>
      </td>
      <?php if ($showClient): ?><td class="small"><?= $d['client_id'] ? '<a href="/clients/' . (int) $d['client_id'] . '/devices">' . e($d['client_name']) . '</a>' : '<span class="text-muted" title="Its RMM organization isn\'t linked to a client (Client mapping)">no client</span>' ?></td><?php endif; ?>
      <td class="col-opt col-type small"><?= e($d['type']) ?></td>
      <td class="small text-nowrap"><?php if ($d['last_user']): ?><span title="<?= e($d['last_user']) ?>"><i class="fas fa-user fa-xs text-muted me-1"></i><?= e(short_user($d['last_user'])) ?></span><?php if ($d['last_contact']): ?><div class="text-muted"><?= e(rel_time($d['last_contact'])) ?></div><?php endif; ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      <?php if ($bkOn): $bk = $backupMap[(int) $d['id']] ?? null; ?><td class="col-opt col-backup small text-nowrap"><?php if ($bk && !empty($bk['exempt'])): ?><span class="text-muted" title="Marked as not needing a backup"><i class="fas fa-ban me-1"></i>not required</span><?php elseif ($bk): ?><span class="text-<?= tone_class($bk['tone']) ?>" title="Newest restore point <?= e(fmt_datetime($bk['last_point'])) ?>"><i class="fas fa-<?= $bk['tone'] === 'ok' ? 'circle-check' : 'triangle-exclamation' ?> me-1"></i><?= e($bk['last_point'] ? rel_time($bk['last_point']) : 'none') ?></span><?php elseif ($d['device_class'] === 'server' && $d['type'] !== 'Hypervisor host' && $d['status'] !== 'excluded'): ?><span class="text-danger" title="No <?= e(\Align\Providers\Providers::backupNames()) ?> job protects this server"><i class="fas fa-shield-halved me-1"></i>none</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td><?php endif; ?>
      <td class="small" title="<?= e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) ?>"><?= e(trim(short_make($d['manufacturer']) . ' ' . ($d['model'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></td>
      <td class="col-opt col-serial small"><?= e($d['serial'] ?? '') ?></td>
      <td class="small" title="<?= e(trim(($d['os_name'] ?: $d['firmware']) . ($d['os_build'] ? ' build ' . $d['os_build'] : ''))) ?>"><?= e($d['os_name'] ? os_label($d['os_name'], $d['os_build'], $d['os_rule']) : (string) $d['firmware']) ?>
        <?php if ($d['os_rule'] && array_intersect(['os_eos', 'os_soon'], $d['flags'])): ?><div class="text-<?= in_array('os_eos', $d['flags'], true) ? 'danger' : 'warning' ?>">support <?= in_array('os_eos', $d['flags'], true) ? 'ended' : 'ends' ?> <?= e(fmt_date($d['os_rule']['eos_date'])) ?></div><?php endif; ?></td>
      <td class="small text-nowrap"><?= e(fmt_date($d['start_date'], 'month')) ?><?= $d['start_estimated'] ? ' <span class="text-muted" title="' . e($d['start_source']) . '">est.</span>' : '' ?>
        <?php if ($d['age_years'] !== null): ?><div class="text-muted"><?= e($d['age_years']) ?> yrs</div><?php endif; ?></td>
      <td class="col-opt col-warranty small text-nowrap"><?= e(fmt_date($d['warranty_end'])) ?></td>
      <td class="small text-nowrap"><?= e(fmt_date($d['eol_date'], 'month')) ?>
        <?php if (!empty($d['project'])): $pj = $d['project']; ?><div><a class="badge text-bg-primary text-decoration-none" href="/clients/<?= (int) $pj['client_id'] ?>/roadmap#modal-roadmap-<?= (int) $pj['id'] ?>" title="<?= e('In the project "' . $pj['title'] . '" (' . \Align\Roadmap\Roadmap::STATUSES[$pj['status']][0] . ')' . ($pj['psa_ticket_id'] ? ', quote ticket #' . $pj['psa_ticket_id'] : '')) ?>"><i class="fas fa-diagram-project me-1"></i><?= e($pj['quarter_label'] ?? 'Project') ?></a></div>
        <?php elseif ($d['replace_planned']): ?><div><span class="badge text-bg-<?= $d['replace_deferred'] ? 'warning' : 'info' ?>" title="<?= e('Replacement planned for ' . $d['replace_label'] . ($d['replace_note'] ? ': ' . $d['replace_note'] : '')) ?>"><i class="fas fa-calendar-check me-1"></i><?= e($d['replace_label']) ?></span></div><?php endif; ?></td>
      <td><?php require __DIR__ . '/../partials/status.php'; ?></td>
      <td class="col-opt col-cost text-end"><?= $d['is_hardware'] && $d['status'] !== 'excluded' ? money($d['replacement_cost']) : '<span class="text-muted">—</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$devices): ?><tr><td colspan="<?= $cols ?>" class="text-muted p-3">No devices match.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php
use Align\Auth;

$q = $_GET['q'] ?? '';
$canEdit = Auth::can('tech');
$tabs = ['active' => 'In planning', 'removed' => 'Removed from planning', 'archived' => 'Archived in ITFlow', 'all' => 'All'];
?>
<form method="post" action="/clients/bulk" id="bulk-form">
<?= csrf_field() ?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-users mr-2"></i>Clients</h3>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="clients-table" placeholder="Filter…" value="<?= e($q) ?>">
      <?php if ($canEdit): ?>
        <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-client" data-autoopen="add"><i class="fas fa-plus mr-1"></i>New client</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body py-2 border-bottom d-flex flex-wrap align-items-center">
    <ul class="nav nav-pills nav-sm mr-auto">
      <?php foreach ($tabs as $k => $label): ?>
        <li class="nav-item"><a class="nav-link py-1 <?= $view === $k ? 'active' : '' ?>" href="/clients?view=<?= $k ?>"><?= e($label) ?> <span class="badge badge-light border"><?= (int) ($counts[$k] ?? 0) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($canEdit): ?>
      <div class="bulk-bar d-none form-inline" id="bulk-bar">
        <span class="small mr-2"><b id="bulk-count">0</b> selected</span>
        <?php if ($view !== 'removed'): ?>
          <input name="reason" class="form-control form-control-sm mr-2" placeholder="Reason (optional)" list="bulk-reasons">
          <datalist id="bulk-reasons"><option value="Break-fix only"><option value="Not a managed client"><option value="Former client"><option value="Vendor / partner record"><option value="Internal / test"></datalist>
          <button class="btn btn-sm btn-outline-secondary" name="action" value="exclude" data-confirm="Remove the selected clients from planning?"><i class="fas fa-eye-slash mr-1"></i>Remove from planning</button>
        <?php endif; ?>
        <?php if ($view === 'removed' || $view === 'all'): ?>
          <button class="btn btn-sm btn-outline-primary ml-1" name="action" value="restore"><i class="fas fa-rotate-left mr-1"></i>Restore to planning</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-striped table-borderless table-hover mb-0" id="clients-table">
      <thead class="text-dark">
        <tr>
          <?php if ($canEdit): ?><th class="w-check"><input type="checkbox" id="check-all" aria-label="Select all"></th><?php endif; ?>
          <th>Client</th><th>Industry</th><th>vCIO</th><th class="text-right">Devices</th><th class="text-right">Critical</th>
          <th class="text-right">Warnings</th><th class="text-right">Next 12 mo</th><th>Compliance</th><th>Next meeting</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($clients as $c):
          $s = $stats[$c['id']] ?? ['total' => 0, 'bad' => 0, 'warn' => 0, 'cost12' => 0];
          $cad = $cadence[$c['id']] ?? null;
          $sc = $scores[$c['id']] ?? [];
          $avg = $sc ? (int) round(array_sum(array_column($sc, 'score')) / count($sc)) : null;
          ?>
        <tr class="<?= $c['planning_excluded'] ? 'text-muted' : '' ?>">
          <?php if ($canEdit): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" class="row-check" aria-label="Select <?= e($c['name']) ?>"></td><?php endif; ?>
          <td>
            <a href="/clients/<?= (int) $c['id'] ?>" class="font-weight-bold"><?= e($c['name']) ?></a>
            <?php if ($c['source'] === 'manual'): ?><span class="badge badge-light border" title="Added in Align">manual</span><?php endif; ?>
            <?php if ($c['is_archived']): ?><span class="badge badge-dark">archived</span><?php endif; ?>
            <?php if ($c['planning_excluded']): ?><span class="badge badge-secondary" title="<?= e($c['excluded_reason'] ?? '') ?>">removed<?= $c['excluded_reason'] ? ': ' . e($c['excluded_reason']) : '' ?></span><?php endif; ?>
            <div class="small text-muted"><?= $c['org_name'] ? '<i class="fas fa-link mr-1"></i>' . e($c['org_name']) : ($c['source'] === 'itflow' && !$c['planning_excluded'] ? '<span class="text-warning">Not linked to NinjaOne</span>' : '') ?></div>
          </td>
          <td class="small"><?= e($c['industry'] ?? '') ?></td>
          <td class="small"><?= e($c['vcio_name'] ?? '') ?></td>
          <td class="text-right"><?= (int) $s['total'] ?></td>
          <td class="text-right"><?= $s['bad'] ? '<span class="badge badge-danger">' . (int) $s['bad'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
          <td class="text-right"><?= $s['warn'] ? '<span class="badge badge-warning">' . (int) $s['warn'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
          <td class="text-right"><?= $s['cost12'] ? money($s['cost12']) : '<span class="text-muted">—</span>' ?></td>
          <td class="compliance-cell">
            <?php if ($avg !== null): ?>
              <div class="progress progress-xs mb-1"><div class="progress-bar bg-<?= $avg >= 80 ? 'success' : ($avg >= 50 ? 'warning' : 'danger') ?>" style="width: <?= $avg ?>%"></div></div>
              <span class="small"><?= $avg ?>% · <?= count($sc) ?> framework<?= count($sc) > 1 ? 's' : '' ?></span>
            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
          </td>
          <td class="small">
            <?php if ($cad && $cad['next']): ?><?= e(fmt_date($cad['next'])) ?>
            <?php elseif ($cad && $cad['overdue']): ?><span class="badge badge-warning">Due</span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$clients): ?><tr><td colspan="10" class="text-muted p-3"><?= $view === 'active' ? 'No clients yet. Add one, or configure ITFlow in Settings and run a sync.' : 'None.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
</form>
<?php if ($canEdit) echo \Align\View::fetch('partials/client_modal', ['c' => null, 'users' => $users]); ?>

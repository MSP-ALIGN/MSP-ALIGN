<?php
use Align\Auth;

$q = $_GET['q'] ?? '';
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-users mr-2"></i>Clients</h3>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="clients-table" placeholder="Filter…" value="<?= e($q) ?>">
      <a class="btn btn-sm btn-outline-light mr-2" href="/clients<?= $showArchived ? '' : '?archived=1' ?>"><?= $showArchived ? 'Hide archived' : 'Archived' ?></a>
      <?php if (Auth::can('tech')): ?>
        <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-client" data-autoopen="add"><i class="fas fa-plus mr-1"></i>New client</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-striped table-borderless table-hover mb-0" id="clients-table">
      <thead class="text-dark">
        <tr>
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
        <tr>
          <td>
            <a href="/clients/<?= (int) $c['id'] ?>" class="font-weight-bold"><?= e($c['name']) ?></a>
            <?php if ($c['source'] === 'manual'): ?><span class="badge badge-light border" title="Added in Align">manual</span><?php endif; ?>
            <?php if ($c['is_archived']): ?><span class="badge badge-dark">archived</span><?php endif; ?>
            <div class="small text-muted"><?= $c['org_name'] ? '<i class="fas fa-link mr-1"></i>' . e($c['org_name']) : ($c['source'] === 'itflow' ? '<span class="text-warning">Not linked to NinjaOne</span>' : '') ?></div>
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
      <?php if (!$clients): ?><tr><td colspan="9" class="text-muted p-3">No clients yet. Add one, or configure ITFlow in Settings and run a sync.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php if (Auth::can('tech')) echo \Align\View::fetch('partials/client_modal', ['c' => null, 'users' => $users]); ?>

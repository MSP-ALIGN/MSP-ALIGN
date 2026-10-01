<?php
use Align\Auth;

$q = $_GET['q'] ?? '';
$canEdit = Auth::can('tech');
$tabs = ['active' => 'In planning', 'removed' => 'Removed from planning', 'archived' => psa_on() ? 'Archived in ' . psa_name() : 'Archived', 'all' => 'All'];
?>
<?= \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-users', 'title' => 'Clients', 'count' => (int) ($counts[$view] ?? 0),
    'desc' => 'Every client' . (psa_on() ? ' from ' . e(psa_name()) : '') . ' and any added by hand. Clients removed from planning stay here but leave the dashboard, meetings and reports.',
    'primary' => $canEdit ? '<button type="button" class="btn btn-sm btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#modal-client" data-autoopen="add"><i class="fas fa-plus me-1"></i>New client</button>' : '',
    'secondary' => $canEdit ? ['<a class="btn btn-sm btn-default text-nowrap" href="/clients/import"><i class="fas fa-file-import me-1"></i>Import</a>'] : [],
]) ?>
<form method="post" action="/clients/bulk" id="bulk-form">
<?= csrf_field() ?>
<div class="card">
  <div class="card-header list-toolbar d-flex flex-wrap align-items-center">
    <ul class="nav nav-pills view-tabs me-auto">
      <?php foreach ($tabs as $k => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $view === $k ? 'active' : '' ?>" href="/clients?view=<?= $k ?>"><?= e($label) ?> <span class="badge <?= $view === $k ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) ($counts[$k] ?? 0) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
    <input type="search" class="form-control form-control-sm list-search my-1 me-1" data-filter-table="clients-table" data-enter-nosubmit placeholder="Filter clients…" aria-label="Filter clients" value="<?= e($q) ?>">
    <?php if ($canEdit): ?>
      <div class="bulk-bar d-none d-flex flex-wrap align-items-center" id="bulk-bar">
        <span class="small me-2"><b id="bulk-count">0</b> selected</span>
        <?php if ($view !== 'removed'): ?>
          <input name="reason" class="form-control form-control-sm me-2" placeholder="Reason (optional)" list="bulk-reasons" data-enter-nosubmit>
          <datalist id="bulk-reasons"><option value="Break-fix only"><option value="Not a managed client"><option value="Former client"><option value="Vendor / partner record"><option value="Internal / test"></datalist>
          <button class="btn btn-sm btn-outline-secondary" name="action" value="exclude" data-confirm="Remove the selected clients from planning? They're hidden from planning, reports and the dashboard until you restore them." data-confirm-ok="Remove from planning"><i class="fas fa-eye-slash me-1"></i>Remove from planning</button>
        <?php endif; ?>
        <?php if ($view === 'removed' || $view === 'all'): ?>
          <button class="btn btn-sm btn-outline-primary ms-1" name="action" value="restore" data-confirm="Restore the selected clients to planning?" data-confirm-danger="0" data-confirm-ok="Restore"><i class="fas fa-rotate-left me-1"></i>Restore to planning</button>
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
          <th>Client</th><th>Industry</th><th>vCIO</th><th class="text-end">Devices</th><th class="text-end">Critical</th>
          <th class="text-end">Warnings</th><th class="text-end">Next 12 mo</th><th>Compliance</th><th>Next meeting</th>
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
            <?php if ($lg = client_logo_url($c)): ?><img src="<?= e($lg) ?>" alt="" class="client-logo-sm me-1"><?php endif; ?><a href="/clients/<?= (int) $c['id'] ?>" class="fw-bold"><?= e($c['name']) ?></a>
            <?php if ($c['source'] === 'manual'): ?><span class="badge text-bg-light border" title="Added in Align">manual</span><?php endif; ?>
            <?php if ($c['is_archived']): ?><span class="badge text-bg-dark">archived</span><?php endif; ?>
            <?php if ($c['planning_excluded']): ?><span class="badge text-bg-secondary" title="<?= e($c['excluded_reason'] ?? '') ?>">removed<?= $c['excluded_reason'] ? ': ' . e($c['excluded_reason']) : '' ?></span><?php endif; ?>
            <div class="small text-muted"><?= $c['org_name'] ? '<i class="fas fa-link me-1"></i>' . e($c['org_name']) : (!$c['planning_excluded'] && \Align\Providers\Providers::anyRmm() ? '<span class="text-warning">Not linked to ' . e(\Align\Providers\Providers::rmmNames()) . '</span>' : '') ?></div>
          </td>
          <td class="small"><?= e($c['industry'] ?? '') ?></td>
          <td class="small"><?= e($c['vcio_name'] ?? '') ?></td>
          <td class="text-end"><?= (int) $s['total'] ?></td>
          <td class="text-end"><?= $s['bad'] ? '<span class="badge text-bg-danger">' . (int) $s['bad'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
          <td class="text-end"><?= $s['warn'] ? '<span class="badge text-bg-warning">' . (int) $s['warn'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
          <td class="text-end"><?= $s['cost12'] ? money($s['cost12']) : '<span class="text-muted">—</span>' ?></td>
          <td class="compliance-cell">
            <?php if ($avg !== null): ?>
              <div class="progress progress-xs mb-1"><div class="progress-bar bg-<?= $avg >= 80 ? 'success' : ($avg >= 50 ? 'warning' : 'danger') ?>" style="width: <?= $avg ?>%"></div></div>
              <span class="small"><?= $avg ?>% · <?= count($sc) ?> framework<?= count($sc) > 1 ? 's' : '' ?></span>
            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
          </td>
          <td class="small">
            <?php if ($cad && $cad['next']): ?><?= e(fmt_date($cad['next'])) ?>
            <?php elseif ($cad && $cad['overdue']): ?><span class="badge text-bg-warning">Due</span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$clients): ?><tr><td colspan="10" class="text-muted p-3"><?= $view === 'active' ? (psa_on() ? 'No clients yet. Add one, or run a sync to bring them in from ' . psa_name() . '.' : 'No clients yet. Add one, <a href="/clients/import">import a CSV file</a>, or add them from your RMM organizations on <a href="/mapping">Client mapping</a>.') : 'None.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
</form>
<?php if ($canEdit) echo \Align\View::fetch('partials/client_modal', ['c' => null, 'users' => $users]); ?>

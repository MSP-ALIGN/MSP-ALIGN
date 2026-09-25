<?php
use Align\Auth;
use Align\Controllers\ProjectController;
use Align\Roadmap\Roadmap;

$canEdit = Auth::can('tech');
$qs = fn(array $over) => '/projects?' . http_build_query(array_filter(array_merge(
    ['status' => $status === 'open' ? '' : $status, 'client' => $clientId ?: '', 'category' => $category, 'year' => $year === null ? '' : (string) $year], $over
), fn($v) => $v !== '' && $v !== null));
$back = $_SERVER['REQUEST_URI'] ?? '/projects';
$modals = [];

$renderRows = function (array $items) use ($canEdit, &$modals) {
    foreach ($items as $it) {
        [$catLabel, $catIcon, $catTone] = Roadmap::category($it['category']);
        [$stLabel, $stTone] = Roadmap::STATUSES[$it['status']];
        [$prLabel, $prTone] = Roadmap::PRIORITIES[$it['priority']];
        $modals[] = $it;
        ?>
        <tr class="proj-row<?= $it['status'] === 'declined' ? ' text-muted' : '' ?>">
          <td>
            <?php if ($canEdit): ?><a href="#" class="font-weight-bold" data-toggle="modal" data-target="#modal-roadmap-<?= (int) $it['id'] ?>"><?= e($it['title']) ?></a>
            <?php else: ?><span class="font-weight-bold"><?= e($it['title']) ?></span><?php endif; ?>
            <?php if (!empty($it['overdue'])): ?><span class="badge badge-danger ml-1" title="Target quarter has passed">overdue</span><?php endif; ?>
            <?php if ($it['description']): ?><div class="small text-muted proj-desc text-truncate" title="<?= e($it['description']) ?>"><?= e($it['description']) ?></div><?php endif; ?>
          </td>
          <td class="small"><a href="/clients/<?= (int) $it['client_id'] ?>/roadmap"><?= e($it['client_name']) ?></a></td>
          <td><span class="badge badge-<?= e($catTone) ?>"><i class="fas <?= e($catIcon) ?> mr-1"></i><?= e($catLabel) ?></span></td>
          <td><span class="badge badge-<?= e($stTone) ?> <?= $stTone === 'light' ? 'border' : '' ?>"><?= e($stLabel) ?></span></td>
          <td><span class="badge badge-<?= e($prTone) ?>"><?= e($prLabel) ?></span></td>
          <td class="text-right text-nowrap"><?= $it['cost'] !== null ? money((float) $it['cost']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-right text-nowrap small"><?= $it['recurring_monthly'] ? money((float) $it['recurring_monthly']) . '/mo' : '' ?></td>
        </tr>
        <?php
    }
};
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 mr-auto"><i class="fas fa-diagram-project text-secondary mr-2"></i>Projects <span class="badge badge-light border align-middle"><?= (int) $count ?></span></h1>
  <?php if ($canEdit): ?><button class="btn btn-primary btn-sm mt-2 mt-md-0" data-toggle="modal" data-target="#modal-roadmap"><i class="fas fa-plus mr-1"></i>Add project</button><?php endif; ?>
</div>
<p class="text-muted small">Planned work across all clients, placed in the quarter it's targeted for. Budgets roll into each client's 3-year IT plan alongside hardware replacements. Overdue open projects show in the current quarter.</p>

<div class="row">
  <?php foreach ($years as $y => $yr): ?>
    <div class="col-md-4">
      <a class="info-box mb-3 text-dark<?= $year === $y ? ' border border-primary' : '' ?>" href="<?= e($qs(['year' => $year === $y ? '' : (string) $y])) ?>">
        <span class="info-box-icon bg-purple"><b><?= $y + 1 ?></b></span>
        <div class="info-box-content">
          <span class="info-box-text"><?= e($yr['label']) ?> projects <small class="text-muted">(<?= e($yr['range']) ?>)</small></span>
          <span class="info-box-number h5 mb-0"><?= money($yr['cost']) ?></span>
          <span class="small text-muted"><?= (int) $yr['count'] ?> project<?= $yr['count'] == 1 ? '' : 's' ?><?= $year === $y ? ' · showing only this year' : '' ?></span>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<form method="get" action="/projects" class="card card-body py-2 mb-3">
  <div class="form-row align-items-center">
    <div class="col-auto mb-1">
      <div class="btn-group btn-group-sm flex-wrap">
        <?php foreach (ProjectController::VIEWS as $k => $label): ?><a class="btn <?= $status === $k ? 'btn-primary' : 'btn-default' ?>" href="<?= e($qs(['status' => $k === 'open' ? '' : $k])) ?>"><?= e($label) ?></a><?php endforeach; ?>
      </div>
    </div>
    <input type="hidden" name="status" value="<?= e($status === 'open' ? '' : $status) ?>">
    <?php if ($year !== null): ?><input type="hidden" name="year" value="<?= (int) $year ?>"><?php endif; ?>
    <div class="col-md-3 mb-1">
      <select name="client" class="custom-select custom-select-sm" data-autosubmit aria-label="Client">
        <option value="">All clients</option>
        <?php foreach ($clients as $id => $name): ?><option value="<?= (int) $id ?>" <?= $clientId === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 mb-1">
      <select name="category" class="custom-select custom-select-sm" data-autosubmit aria-label="Category">
        <option value="">All categories</option>
        <?php foreach (Roadmap::CATEGORIES as $k => [$label]): ?><option value="<?= e($k) ?>" <?= $category === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <noscript><div class="col-auto"><button class="btn btn-sm btn-default">Apply</button></div></noscript>
  </div>
</form>

<div class="card card-dark">
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0 projects-table">
      <thead><tr><th>Project</th><th>Client</th><th>Category</th><th>Status</th><th>Priority</th><th class="text-right">Budget</th><th class="text-right">Recurring</th></tr></thead>
      <tbody>
      <?php $any = false; foreach ($quarters as $q): if (!$q['items']) continue; $any = true; ?>
        <tr class="proj-quarter"><th colspan="5"><?= e($q['label']) ?> <small class="text-muted font-weight-normal"><?= e($q['months']) ?><?= $q['current'] ? ' · current quarter' : '' ?></small></th>
          <th class="text-right text-nowrap"><?= money($q['cost']) ?></th><th class="text-right small font-weight-normal text-nowrap"><?= $q['recurring'] ? '+' . money($q['recurring']) . '/mo' : '' ?></th></tr>
        <?php $renderRows($q['items']); ?>
      <?php endforeach; ?>
      <?php if ($beyond): $any = true; ?>
        <tr class="proj-quarter"><th colspan="7">Beyond the 3-year plan</th></tr>
        <?php $renderRows($beyond); ?>
      <?php endif; ?>
      <?php if ($unscheduled): $any = true; ?>
        <tr class="proj-quarter"><th colspan="7">Not scheduled yet <small class="text-muted font-weight-normal">pick a target quarter to include it in the plan budget</small></th></tr>
        <?php $renderRows($unscheduled); ?>
      <?php endif; ?>
      <?php if (!$any): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No projects match.<?= $canEdit ? ' Use <b>Add project</b> to plan one.' : '' ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canEdit): ?>
  <?php
  $cid = $clientId ?: 0;
  echo \Align\View::fetch('roadmap/_modal', ['it' => null, 'cid' => $cid, 'pickClients' => $clients, 'back' => $back]);
  foreach ($modals as $it) {
      echo \Align\View::fetch('roadmap/_modal', ['it' => $it, 'cid' => (int) $it['client_id'], 'back' => $back]);
  }
  ?>
<?php endif; ?>

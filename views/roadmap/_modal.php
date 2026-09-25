<?php
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;

$it = $it ?? null;
$id = $it ? 'modal-roadmap-' . (int) $it['id'] : 'modal-roadmap';
$pickClients = $pickClients ?? null;   // global Projects page: [id => name] to choose the client
$back = $back ?? null;                 // where to return after saving
$action = $pickClients && !$it ? '/projects' : '/clients/' . (int) ($it['client_id'] ?? $cid) . '/roadmap' . ($it ? '/' . (int) $it['id'] : '');
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
$planQs = Plan::quarters();
$planEnd = $planQs[count($planQs) - 1]['end'];
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <?php if ($back): ?><input type="hidden" name="back" value="<?= e($back) ?>"><?php endif; ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-road mr-2"></i><?= $it ? 'Edit project' : 'Add project' ?></h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <?php if ($pickClients && !$it): ?>
            <div class="form-group"><label>Client</label>
              <select name="client_id" class="form-control" required>
                <option value="">Choose a client…</option>
                <?php foreach ($pickClients as $pcId => $pcName): ?><option value="<?= (int) $pcId ?>" <?= (int) $pcId === (int) $cid ? 'selected' : '' ?>><?= e($pcName) ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <div class="form-row">
            <div class="form-group col-md-8"><label>Project</label><input name="title" class="form-control" required value="<?= e($it['title'] ?? '') ?>" placeholder="e.g. Replace firewall, Move file server to SharePoint, Add MDR"></div>
            <div class="form-group col-md-4"><label>Category</label>
              <select name="category" class="form-control">
                <?php foreach (Roadmap::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $it['category'] ?? 'project') ?>><?= e($label) ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-4"><label>Target quarter</label>
              <select name="target_quarter" class="form-control quarter-select">
                <option value="">Unscheduled</option>
                <?php $curYear = null; foreach (Plan::quarters() as $q): if ($q['past'] && ($it['target_quarter'] ?? '') !== $q['start']) continue; ?>
                  <?php if ($curYear !== $q['year_label']): if ($curYear !== null) echo '</optgroup>'; $curYear = $q['year_label']; echo '<optgroup label="' . e($q['year_label']) . '">'; endif; ?>
                  <option value="<?= e($q['start']) ?>" <?= $sel($q['start'], $it['target_quarter'] ?? '') ?>><?= e($q['label']) ?> (<?= e($q['months']) ?>)</option>
                <?php endforeach; if ($curYear !== null) echo '</optgroup>'; ?>
                <?php if ($it && $it['target_quarter'] && Plan::indexFor($it['target_quarter'], false) === null && $it['target_quarter'] > $planEnd): ?>
                  <option value="<?= e($it['target_quarter']) ?>" selected><?= e(fmt_date($it['target_quarter'])) ?> (beyond plan)</option>
                <?php endif; ?>
              </select></div>
            <div class="form-group col-md-4"><label>Priority</label>
              <select name="priority" class="form-control"><?php foreach (Roadmap::PRIORITIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $it['priority'] ?? 'medium') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="form-group col-md-4"><label>Status</label>
              <select name="status" class="form-control"><?php foreach (Roadmap::STATUSES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $it['status'] ?? 'proposed') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6"><label>Budget <small class="text-muted">(one-time)</small></label>
              <div class="input-group"><div class="input-group-prepend"><span class="input-group-text">$</span></div><input type="number" min="0" step="1" name="cost" class="form-control" value="<?= e($it['cost'] ?? '') ?>"></div></div>
            <div class="form-group col-md-6"><label>Recurring cost <small class="text-muted">(optional)</small></label>
              <div class="input-group"><div class="input-group-prepend"><span class="input-group-text">$</span></div><input type="number" min="0" step="1" name="recurring_monthly" class="form-control" value="<?= e($it['recurring_monthly'] ?? '') ?>"><div class="input-group-append"><span class="input-group-text">/ month</span></div></div></div>
          </div>
          <div class="form-group mb-0"><label>Description</label><textarea name="description" class="form-control" rows="4" placeholder="Scope, why it matters to the client, dependencies…"><?= e($it['description'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($it): ?><button class="btn btn-outline-danger mr-auto" name="action" value="delete" formnovalidate data-confirm="Delete this project?"><i class="fas fa-trash mr-1"></i>Delete</button><?php endif; ?>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check mr-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

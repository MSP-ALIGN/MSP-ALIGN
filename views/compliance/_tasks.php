<?php
/**
 * 2.40.0 A client's recurring compliance tasks (Compliance\Tasks) on its compliance page: soonest first, overdue and
 * due-soon marked, switched-off ones last; techs and admins mark one done (a note or one of the client's documents
 * is required) or change its interval, next due date, or switch it off and on again.
 * @var array $client, $tasks (Tasks::forClient), $docs (the client's documents, for techs). Every value is escaped;
 * forms post with CSRF to ComplianceSetupController, which checks the role, the client and the task again.
 */
use Align\Auth;

$cid = (int) $client['id'];
$can = Auth::can('tech');
$tone = ['overdue' => 'danger', 'soon' => 'warning', 'ok' => 'success'];
?>
<div class="card card-dark" id="compliance-tasks">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-rotate me-2"></i>Recurring tasks</h3></div>
  <?php if (!$tasks): ?>
    <div class="card-body small text-muted">None yet. Finishing a framework's guided setup adds the recurring work it asks for (risk assessment, access reviews, training, tests).</div>
  <?php endif; ?>
  <ul class="list-group list-group-flush">
    <?php foreach ($tasks as $t): ?>
      <li class="list-group-item py-2 small" data-task="<?= e($t['kind']) ?>">
        <div class="d-flex align-items-start gap-2">
          <div class="me-auto"><b><?= e($t['label']) ?></b>
            <div class="text-muted"><?= e($t['what']) ?></div>
            <div class="text-muted">Every <?= (int) $t['every_months'] ?> months · <?= $t['frameworks'] ? e(implode(', ', $t['frameworks'])) : 'none of the client\'s frameworks needs it now' ?></div>
            <?php if ($t['last']): ?><div class="text-muted">Last done <?= e(fmt_date($t['last']['done_on'])) ?><?= $t['last']['done_by_name'] ? ' by ' . e($t['last']['done_by_name']) : '' ?><?= $t['last']['doc_title'] ? ' · ' . e($t['last']['doc_title']) : '' ?></div><?php endif; ?>
          </div>
          <?php if (!$t['active']): ?><span class="badge text-bg-secondary" data-state="off">Off</span>
          <?php else: ?><span class="badge text-bg-<?= $tone[$t['state']] ?>" data-state="<?= e($t['state']) ?>"><?= $t['state'] === 'overdue' ? 'Overdue' : 'Due' ?> <?= e(fmt_date($t['next_due'])) ?></span><?php endif; ?>
        </div>
        <?php if ($can && $t['active']): ?>
          <details class="mt-1"><summary class="text-primary">Mark done</summary>
            <form method="post" action="/clients/<?= $cid ?>/compliance-tasks/<?= (int) $t['id'] ?>/done" class="mt-1">
              <?= csrf_field() ?>
              <div class="d-flex gap-1 mb-1"><input type="date" class="form-control form-control-sm" name="done_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="Done on">
                <select class="form-select form-select-sm" name="document_id" aria-label="Proof document"><option value="">No document</option>
                  <?php foreach ($docs as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['title']) ?></option><?php endforeach; ?></select></div>
              <textarea class="form-control form-control-sm mb-1" name="note" rows="2" maxlength="5000" placeholder="What was done (needed unless you pick a document)"></textarea>
              <button class="btn btn-xs btn-success">Record</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($can): ?>
          <details class="mt-1"><summary class="text-muted"><?= $t['active'] ? 'Change' : 'Turn back on' ?></summary>
            <form method="post" action="/clients/<?= $cid ?>/compliance-tasks/<?= (int) $t['id'] ?>" class="mt-1 d-flex flex-wrap gap-1 align-items-center">
              <?= csrf_field() ?>
              <label class="small">Every <input type="number" class="form-control form-control-sm d-inline-block" style="width:4.5rem" name="every_months" min="1" max="36" value="<?= (int) $t['every_months'] ?>"> months</label>
              <input type="date" class="form-control form-control-sm w-auto" name="next_due" value="<?= e($t['next_due']) ?>" aria-label="Next due">
              <div class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="active" value="1" id="ta-<?= (int) $t['id'] ?>" <?= $t['active'] ? 'checked' : '' ?>><label class="form-check-label" for="ta-<?= (int) $t['id'] ?>">Active</label></div>
              <button class="btn btn-xs btn-default">Save</button>
            </form>
          </details>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

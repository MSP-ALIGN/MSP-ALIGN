<?php
use Align\Controllers\ClientController;
use Align\Meetings\Meetings;

$c = $c ?? null; // null = new client
$manual = !$c || $c['source'] === 'manual';
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
?>
<div class="modal fade" id="modal-client" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $c ? '/clients/' . (int) $c['id'] : '/clients' ?>">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-user-plus mr-2"></i><?= $c ? 'Edit ' . e($c['name']) : 'New client' ?></h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <?php if (!$manual): ?>
            <div class="alert alert-light border small py-2"><i class="fas fa-circle-info mr-1"></i>This client syncs from ITFlow, so its name is managed there. Everything else here is stored in Align.</div>
          <?php endif; ?>
          <div class="form-row">
            <div class="form-group col-md-8">
              <label>Client name</label>
              <input name="name" class="form-control" value="<?= e($c['name'] ?? '') ?>" <?= $manual ? 'required' : 'readonly' ?>>
            </div>
            <div class="form-group col-md-4">
              <label>Industry</label>
              <select name="industry" class="form-control">
                <option value="">—</option>
                <?php foreach (ClientController::INDUSTRIES as $i): ?><option <?= $sel($i, $c['industry'] ?? '') ?>><?= e($i) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-4"><label>Primary contact</label><input name="contact_name" class="form-control" value="<?= e($c['contact_name'] ?? '') ?>"></div>
            <div class="form-group col-md-4"><label>Email</label><input type="email" name="contact_email" class="form-control" value="<?= e($c['contact_email'] ?? '') ?>"></div>
            <div class="form-group col-md-4"><label>Phone</label><input name="contact_phone" class="form-control" value="<?= e($c['contact_phone'] ?? '') ?>"></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6"><label>Website</label><input name="website" class="form-control" value="<?= e($c['website'] ?? '') ?>"></div>
            <div class="form-group col-md-3">
              <label>Meeting cadence</label>
              <select name="meeting_cadence" class="form-control">
                <?php foreach (Meetings::CADENCES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $c['meeting_cadence'] ?? 'quarterly') ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-3">
              <label>vCIO / account lead</label>
              <select name="vcio_user_id" class="form-control">
                <option value="">—</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $sel($u['id'], $c['vcio_user_id'] ?? '') ?>><?= e($u['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"><?= e($c['address'] ?? '') ?></textarea></div>
          <div class="form-group mb-0"><label>Notes</label><textarea name="notes" class="form-control" rows="3"><?= e($c['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($c && $manual): ?>
            <button class="btn btn-outline-secondary mr-auto" formaction="/clients/<?= (int) $c['id'] ?>/archive" formnovalidate data-confirm="<?= $c['is_archived'] ? 'Restore' : 'Archive' ?> this client?"><?= $c['is_archived'] ? 'Restore' : 'Archive' ?></button>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-check mr-1"></i><?= $c ? 'Save' : 'Create client' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
use Align\Meetings\Meetings;

$m = $m ?? null; // editing when set
$id = $m ? 'modal-meeting-edit' : 'modal-meeting';
$action = $m ? '/meetings/' . (int) $m['id'] : '/meetings';
$date = $m ? date('Y-m-d', strtotime($m['starts_at'])) : date('Y-m-d', strtotime('+7 days'));
$time = $m ? date('H:i', strtotime($m['starts_at'])) : '10:00';
$dur = $m ? (int) round((strtotime($m['ends_at']) - strtotime($m['starts_at'])) / 60) : \Align\Settings::int('meeting_default_minutes', 60);
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
$clientSel = $m['client_id'] ?? $presetClient ?? null;
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= e($action) ?>">
        <?= csrf_field() ?>
        <?php if (!$m): ?><input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>"><?php endif; ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-handshake mr-2"></i><?= $m ? 'Edit meeting' : 'Schedule meeting' ?></h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Client</label>
              <select name="client_id" class="form-control">
                <option value="">— Internal / no client —</option>
                <?php foreach ($modalClients as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel($c['id'], $clientSel) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-6">
              <label>Type</label>
              <select name="type" class="form-control">
                <?php foreach (Meetings::TYPES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $m['type'] ?? 'abr') ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Title <small class="text-muted">(defaults to the type)</small></label>
            <input name="title" class="form-control" value="<?= e($m['title'] ?? '') ?>" placeholder="e.g. 2027 annual technology review">
          </div>
          <div class="form-row">
            <div class="form-group col-md-4"><label>Date</label><input type="date" name="date" class="form-control" required value="<?= e($date) ?>"></div>
            <div class="form-group col-md-4"><label>Start time</label><input type="time" name="time" class="form-control" required value="<?= e($time) ?>" step="900"></div>
            <div class="form-group col-md-4"><label>Length</label>
              <select name="duration" class="form-control">
                <?php foreach ([15, 30, 45, 60, 90, 120, 180, 240] as $n): ?><option value="<?= $n ?>" <?= $sel($n, $dur) ?>><?= $n < 60 ? "$n min" : ($n / 60) . ' hr' . ($n > 60 ? 's' : '') ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php if (!$m): ?>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Repeat</label>
              <select name="repeat" class="form-control" data-toggle-target="#repeat-count">
                <option value="none">Does not repeat</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="semiannual">Every 6 months</option>
                <option value="annual">Yearly</option>
              </select>
            </div>
            <div class="form-group col-md-6 d-none" id="repeat-count">
              <label>Number of meetings</label>
              <input type="number" name="repeat_count" class="form-control" min="2" max="24" value="4">
            </div>
          </div>
          <?php endif; ?>
          <div class="form-row">
            <div class="form-group col-md-6"><label>Location</label><input name="location" class="form-control" value="<?= e($m['location'] ?? '') ?>" placeholder="Client office, phone…"></div>
            <div class="form-group col-md-6"><label>Video link</label><input type="url" name="video_url" class="form-control" value="<?= e($m['video_url'] ?? '') ?>" placeholder="https://teams.microsoft.com/…"></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Owner</label>
              <select name="owner_id" class="form-control">
                <?php foreach ($modalUsers as $usr): ?><option value="<?= (int) $usr['id'] ?>" <?= $sel($usr['id'], $m['owner_id'] ?? \Align\Auth::id()) ?>><?= e($usr['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-6"><label>Attendees</label><input name="attendees" class="form-control" value="<?= e($m['attendees'] ?? '') ?>" placeholder="Names or emails, comma separated" data-attendees>
              <div class="small mt-1 attendee-picks" data-attendee-picks></div></div>
          </div>
          <div class="form-group mb-0">
            <label>Agenda</label>
            <textarea name="agenda" class="form-control" rows="5" placeholder="1. Lifecycle review&#10;2. Compliance gaps&#10;3. Roadmap and budget"><?= e($m['agenda'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-check mr-1"></i><?= $m ? 'Save' : 'Schedule' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

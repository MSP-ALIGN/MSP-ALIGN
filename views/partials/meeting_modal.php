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
      <?php $sentEarlier = !empty($m['invites_sent_at']); ?>
      <form method="post" action="<?= e($action) ?>" data-unsaved data-confirm-rules="<?= e(json_encode([
          ['when' => '[name=send_invites][type=checkbox]:checked', 'title' => $sentEarlier ? 'Email the update to the attendees?' : 'Email invitations to the attendees?', 'ok' => 'Save and send',
           'text' => $sentEarlier ? 'Everyone on the attendee list gets the updated invitation.' : 'Everyone on the attendee list gets a calendar invitation.'],
          ['when' => ['[name=send_invites][type=checkbox]:checked', '[name=repeat] option:checked:not([value="none"])'], 'text' => 'It repeats, so each meeting in the series is sent.'],
      ])) ?>">
        <?= csrf_field() ?>
        <?php if (!$m): ?><input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>"><?php endif; ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-handshake me-2"></i><?= $m ? 'Edit meeting' : 'Schedule meeting' ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="mb-3 col-md-6">
              <label>Client</label>
              <select name="client_id" class="form-select">
                <option value="">— Internal / no client —</option>
                <?php foreach ($modalClients as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel($c['id'], $clientSel) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3 col-md-6">
              <label>Type</label>
              <select name="type" class="form-select">
                <?php foreach (Meetings::TYPES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $m['type'] ?? 'abr') ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label>Title <small class="text-muted">(defaults to the type)</small></label>
            <input name="title" class="form-control" value="<?= e($m['title'] ?? '') ?>" placeholder="e.g. 2027 annual technology review">
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label>Date</label><input type="date" name="date" class="form-control" required value="<?= e($date) ?>"></div>
            <div class="mb-3 col-md-4"><label>Start time</label><input type="time" name="time" class="form-control" required value="<?= e($time) ?>" step="900"></div>
            <div class="mb-3 col-md-4"><label>Length</label>
              <select name="duration" class="form-select">
                <?php foreach ([15, 30, 45, 60, 90, 120, 180, 240] as $n): ?><option value="<?= $n ?>" <?= $sel($n, $dur) ?>><?= $n < 60 ? "$n min" : ($n / 60) . ' hr' . ($n > 60 ? 's' : '') ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php if (!$m): ?>
          <div class="row g-2">
            <div class="mb-3 col-md-6">
              <label>Repeat</label>
              <select name="repeat" class="form-select" data-toggle-target="#repeat-count">
                <option value="none">Does not repeat</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="semiannual">Every 6 months</option>
                <option value="annual">Yearly</option>
              </select>
            </div>
            <div class="mb-3 col-md-6 d-none" id="repeat-count">
              <label>Number of meetings</label>
              <input type="number" name="repeat_count" class="form-control" min="2" max="24" value="4">
            </div>
          </div>
          <?php endif; ?>
          <div class="row g-2">
            <div class="mb-3 col-md-6"><label>Location</label><input name="location" class="form-control" value="<?= e($m['location'] ?? '') ?>" placeholder="Client office, phone…"></div>
            <div class="mb-3 col-md-6"><label>Video link</label><input type="url" name="video_url" class="form-control" value="<?= e($m['video_url'] ?? '') ?>" placeholder="https://teams.microsoft.com/…"></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-6">
              <label>Owner</label>
              <select name="owner_id" class="form-select">
                <?php foreach ($modalUsers as $usr): ?><option value="<?= (int) $usr['id'] ?>" <?= $sel($usr['id'], $m['owner_id'] ?? \Align\Auth::id()) ?>><?= e($usr['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3 col-md-6"><label>Attendees</label><input name="attendees" class="form-control" value="<?= e($m['attendees'] ?? '') ?>" placeholder="Names or emails, comma separated" data-attendees>
              <div class="small mt-1 attendee-picks" data-attendee-picks></div></div>
          </div>
          <div class="mb-3 mb-0">
            <label>Agenda</label>
            <textarea name="agenda" class="form-control" rows="5" placeholder="1. Lifecycle review&#10;2. Compliance gaps&#10;3. Roadmap and budget"><?= e($m['agenda'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <?php if (\Align\Mail\Invites::enabled()): $sentBefore = !empty($m['invites_sent_at']); ?>
            <input type="hidden" name="send_invites" value="0">
            <div class="form-check me-auto"><input type="checkbox" class="form-check-input" id="send-invites-<?= (int) ($m['id'] ?? 0) ?>" name="send_invites" value="1" <?= !$m || $sentBefore ? 'checked' : '' ?>>
              <label class="form-check-label fw-normal small" for="send-invites-<?= (int) ($m['id'] ?? 0) ?>"><?= $sentBefore ? 'Send the update to attendees' : 'Email invitations to attendees' ?><?= \Align\Settings::get('mail_meeting_mode', 'calendar') === 'calendar' && \Align\Mail\Mail::hasCalendar() ? (\Align\Mail\Mail::provider() === 'google' ? ' (Google Calendar)' : ' (Outlook)') : '' ?></label></div>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-check me-1"></i><?= $m ? 'Save' : 'Schedule' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

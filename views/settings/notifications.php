<?php
use Align\Mail\Invites;
use Align\Mail\Mail;
use Align\Mail\Notifications as N;

/** @var array $v, $stats; bool $ready; string $provider */
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$mode = $v['mail_mode'] ?: 'off';
?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'notifications']) ?>
<?php if (!$ready): ?>
  <div class="alert alert-warning py-2"><i class="fas fa-triangle-exclamation mr-1"></i>Email isn't connected yet, so nothing is sent. Set up <a href="/integrations/email">Email</a> (Microsoft 365, Google Workspace or an SMTP server) first; you can choose notifications now.</div>
<?php else: ?>
  <p class="small text-muted">Sending through <?= e(Mail::providerName()) ?> as <?= e((string) Mail::fromAddress()) ?> · <a href="/integrations/email">Mail connection</a> · <a href="/settings/notifications/log">Email log</a><?= $stats['queued'] ? ' <span class="badge badge-warning">' . (int) $stats['queued'] . ' queued</span>' : '' ?></p>
<?php endif; ?>
<form method="post" action="/settings/notifications" id="notifications">
<div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock mr-2"></i>Schedule &amp; meeting invitations</h3></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group col-md-4"><label>Digests are sent at</label><select name="notif_digest_hour" class="custom-select"><?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>" <?= (int) ($v['notif_digest_hour'] ?? 7) === $h ? 'selected' : '' ?>><?= e(\Align\Fmt::hour($h)) ?></option><?php endfor; ?></select></div>
          <div class="form-group col-md-4"><label>Weekly emails on</label><select name="notif_weekly_day" class="custom-select"><?php foreach ($days as $n => $d): ?><option value="<?= $n ?>" <?= (int) ($v['notif_weekly_day'] ?? 1) === $n ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
          <div class="form-group col-md-4"><label>Meeting reminders</label><div class="input-group"><input type="number" name="notif_meeting_reminder_hours" class="form-control" min="1" max="168" value="<?= e($v['notif_meeting_reminder_hours'] ?: '24') ?>"><div class="input-group-append"><span class="input-group-text">hours before</span></div></div></div>
        </div>
        <div class="form-row">
          <div class="form-group col-md-6"><label>Meeting invitations</label><?php if (!Mail::hasCalendar()): ?><p class="small text-muted mb-1">With an SMTP server, invitations are always emails with an .ics invitation attached (mail apps show Accept / Decline).</p><?php endif; ?><select name="mail_meeting_mode" class="custom-select"<?= Mail::hasCalendar() ? '' : ' disabled' ?>><?php foreach (Invites::MODES as $k => $l): ?><option value="<?= $k ?>" <?= ($v['mail_meeting_mode'] ?: 'calendar') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <small class="text-muted">Calendar invitations are real meetings in Outlook or Google Calendar: attendees can accept, and changes and cancellations follow automatically.</small></div>
          <div class="form-group col-md-6<?= $mode === 'app' && Mail::hasCalendar() ? '' : ' d-none' ?>"><label>Organizer</label><select name="mail_meeting_organizer" class="custom-select">
              <option value="owner" <?= ($v['mail_meeting_organizer'] ?: 'owner') === 'owner' ? 'selected' : '' ?>>Meeting owner's own calendar (falls back to the From mailbox)</option>
              <option value="mailbox" <?= $v['mail_meeting_organizer'] === 'mailbox' ? 'selected' : '' ?>>Always the From mailbox</option></select></div>
        </div>
        <input type="hidden" name="mail_teams_links_present" value="1">
        <div class="custom-control custom-switch<?= Mail::hasCalendar() ? '' : ' d-none' ?>"><input type="checkbox" class="custom-control-input" id="mail_teams_links" name="mail_teams_links" value="1" <?= ($v['mail_teams_links'] ?? '1') !== '0' ? 'checked' : '' ?>><label class="custom-control-label font-weight-normal" for="mail_teams_links">Add a <?= $provider === 'google' ? 'Google Meet' : 'Microsoft Teams' ?> link to invitations when the meeting has no video link</label></div>
      </div>
</div>
<div class="card card-dark">
  <?= csrf_field() ?>
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-bell mr-2"></i>Notifications</h3>
    <div class="card-tools"><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save notifications</button></div></div>
  <div class="card-body py-2 small text-muted border-bottom">Switch each email on or off and choose who gets staff notifications by default. Everyone can change their own choices under <b>Account → Email notifications</b>; "vCIO" also sends it to each client's vCIO for their clients. Extra addresses (a shared inbox or your ticketing system's email) get the all-clients version.</div>
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="text-dark"><tr><th style="width:1%">On</th><th>Email</th><th>When</th><th class="text-center">Default for</th><th class="text-center">vCIO</th><th style="min-width:220px">Also send to</th><th></th></tr></thead>
      <tbody>
      <?php $group = null; foreach (N::CATALOG as $key => [$label, $grp, $aud, $timing, $desc, $droles, $dvcio, $don]): if ($grp !== $group): $group = $grp; ?>
        <tr class="bg-light"><td colspan="7" class="small font-weight-bold text-uppercase text-muted py-1"><?= e($grp) ?></td></tr>
      <?php endif; $on = \Align\Settings::get("notif_$key", $don ? '1' : '0') === '1'; ?>
        <tr>
          <td class="align-middle"><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="on-<?= $key ?>" name="on[<?= $key ?>]" value="1" <?= $on ? 'checked' : '' ?>><label class="custom-control-label" for="on-<?= $key ?>"><span class="sr-only"><?= e($label) ?></span></label></div></td>
          <td><b><?= e($label) ?></b><div class="small text-muted"><?= e($desc) ?></div></td>
          <td class="small text-nowrap align-middle"><?= e(N::TIMING[$timing]) ?></td>
          <td class="small text-nowrap align-middle text-center">
            <?php if ($aud === 'staff' && $key !== 'security'): foreach (['admin' => 'Admins', 'tech' => 'Techs', 'viewer' => 'Viewers'] as $r => $rl): ?>
              <div class="custom-control custom-checkbox custom-control-inline mr-2"><input type="checkbox" class="custom-control-input" id="r-<?= $key . $r ?>" name="roles[<?= $key ?>][]" value="<?= $r ?>" <?= in_array($r, N::roles($key), true) ? 'checked' : '' ?>><label class="custom-control-label font-weight-normal" for="r-<?= $key . $r ?>"><?= $rl ?></label></div>
            <?php endforeach; elseif ($key === 'security'): ?>Admins<?php else: ?><span class="text-muted">The client</span><?php endif; ?>
          </td>
          <td class="text-center align-middle"><?php if ($aud === 'staff' && $key !== 'security'): ?><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="v-<?= $key ?>" name="vcio[<?= $key ?>]" value="1" <?= N::toVcio($key) ? 'checked' : '' ?>><label class="custom-control-label" for="v-<?= $key ?>"><span class="sr-only">vCIO</span></label></div><?php endif; ?></td>
          <td class="align-middle"><?php if ($aud === 'staff'): ?><input name="extra[<?= $key ?>]" class="form-control form-control-sm" value="<?= e(\Align\Settings::get("notif_{$key}_extra", '')) ?>" placeholder="alerts@…, tickets@…"><?php endif; ?></td>
          <td class="align-middle text-nowrap"><?php if (in_array($timing, ['daily', 'weekly', 'monthly'], true)): ?>
            <a class="btn btn-xs btn-default" href="/settings/notifications/preview/<?= $key ?>" target="_blank" title="Preview for all clients">Preview</a>
            <button class="btn btn-xs btn-default" formaction="/settings/notifications/digest/<?= $key ?>" <?= $ready ? '' : 'disabled' ?> title="Send it now to everyone who gets it">Send now</button>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <div class="card-footer"><button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save notifications</button></div>
</div>
</form>

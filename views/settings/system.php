<?php
use Align\System\Agent;

/** @var bool $available; ?array $update, $newer, $sys, $active, $upload; array $jobs, $safety; ?string $watch, $lastDownload, $lastDownloadBy; int $reminderDays, $maxUpload */
$stateBadge = fn(string $s) => ['succeeded' => 'success', 'failed' => 'danger', 'running' => 'primary', 'queued' => 'secondary'][$s] ?? 'secondary';
$stateText = fn(string $s) => ['succeeded' => 'Done', 'failed' => 'Failed', 'running' => 'Running', 'queued' => 'Queued'][$s] ?? $s;
$watchId = $watch ?? ($active['id'] ?? null);
$watchJob = $watchId ? Agent::job($watchId) : null;
$overdue = !$lastDownload || strtotime($lastDownload) < time() - max(1, $reminderDays ?: 7) * 86400;
$info = $upload['info'] ?? null;
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h3 mb-0 mr-auto"><i class="fas fa-arrows-rotate text-secondary mr-2"></i>Updates &amp; backups</h1>
</div>

<?php if (!$available): ?>
  <div class="alert alert-warning"><i class="fas fa-triangle-exclamation mr-1"></i>The update and backup service isn't installed on this server yet. Run this once on the server, then refresh:
    <code class="d-block mt-1">sudo mountaineer-align-update</code></div>
<?php endif; ?>

<?php if ($watchJob): ?>
  <div class="card card-outline card-<?= $stateBadge($watchJob['state']) ?>" data-job-watch="<?= e($watchId) ?>" data-job-action="<?= e($watchJob['action'] ?? '') ?>">
    <div class="card-header py-2 d-flex align-items-center">
      <h3 class="card-title mr-auto"><i class="fas fa-fw fa-list-check mr-2"></i><span data-job-label><?= e(Agent::ACTIONS[$watchJob['action'] ?? ''] ?? 'Job') ?></span></h3>
      <span class="badge badge-<?= $stateBadge($watchJob['state']) ?> px-2 py-1" data-job-state><?= e($stateText($watchJob['state'])) ?></span>
    </div>
    <div class="card-body">
      <p class="mb-1"><i class="fas fa-spinner fa-spin mr-1<?= in_array($watchJob['state'], ['succeeded', 'failed'], true) ? ' d-none' : '' ?>" data-job-spinner></i><span data-job-step><?= e($watchJob['step'] ?? '') ?></span></p>
      <p class="mb-2 font-weight-bold" data-job-message><?= e($watchJob['message'] ?? '') ?></p>
      <div data-job-result class="small"></div>
      <details class="mt-2"<?= ($watchJob['state'] ?? '') === 'failed' ? ' open' : '' ?>><summary class="small text-muted">Details</summary>
        <pre class="job-log small bg-dark text-light p-2 mt-2 mb-1 rounded" data-job-log><?= e(Agent::log($watchId, 16384)) ?></pre>
        <a class="small" href="/settings/system/jobs/<?= e($watchId) ?>/log" target="_blank" rel="noopener">Full log</a>
      </details>
      <form method="post" action="/settings/system/download/<?= e($watchId) ?>" data-job-download class="mt-2<?= ($watchJob['action'] ?? '') === 'backup' && $watchJob['state'] === 'succeeded' && is_file((string) Agent::downloadPath($watchId)) ? '' : ' d-none' ?>">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-success"><i class="fas fa-download mr-1"></i>Download backup</button>
        <span class="small text-muted ml-2">If the download didn't start on its own. It works once; the file is then deleted from the server.</span>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="row">
  <div class="col-xl-6">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-arrow-up mr-2"></i>Updates</h3>
        <div class="card-tools"><?= $newer ? '<span class="badge badge-info px-2 py-1">Update available</span>' : ($update && !$update['error'] ? '<span class="badge badge-success px-2 py-1">Up to date</span>' : '') ?></div></div>
      <div class="card-body">
        <dl class="row mb-2">
          <dt class="col-5 font-weight-normal text-muted">This server</dt><dd class="col-7 mb-1"><b><?= e(APP_VERSION) ?></b></dd>
          <dt class="col-5 font-weight-normal text-muted">Latest</dt><dd class="col-7 mb-1"><?= $update && $update['latest'] ? e($update['latest']) . ($newer && ($update['behind'] ?? 0) ? ' <span class="text-muted small">(' . (int) $update['behind'] . ' change' . ((int) $update['behind'] === 1 ? '' : 's') . ')</span>' : '') : '<span class="text-muted">Not checked yet</span>' ?></dd>
          <dt class="col-5 font-weight-normal text-muted">Last checked</dt><dd class="col-7 mb-1"><?= $update ? e(rel_time(date('Y-m-d H:i:s', strtotime($update['checked_at'])))) : '<span class="text-muted">Never</span>' ?></dd>
        </dl>
        <?php if ($update && $update['error']): ?><div class="alert alert-warning py-2 small"><?= e($update['error']) ?></div><?php endif; ?>

        <?php if ($newer): ?>
          <h6 class="mt-3">What's new</h6>
          <ul class="list-unstyled small update-changes mb-3">
            <?php foreach (array_slice($newer['changes'] ?? [], 0, 30) as $c): ?>
              <li class="mb-2"><b><?= e($c['subject']) ?></b> <span class="text-muted"><?= e(date('M j', strtotime($c['date'] ?: 'now'))) ?></span>
                <?php if (trim($c['body'] ?? '') !== ''): ?><div class="text-muted text-pre-line"><?= e(mb_strimwidth($c['body'], 0, 600, '…')) ?></div><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
          <form method="post" action="/settings/system/update" class="border-top pt-3">
            <?= csrf_field() ?>
            <div class="custom-control custom-checkbox mb-2"><input type="checkbox" class="custom-control-input" id="upd-confirm" name="confirm" value="1">
              <label class="custom-control-label font-weight-normal" for="upd-confirm">Align will be unavailable for a minute or two while it updates. A safety copy of the data is made first and deleted once the update succeeds.</label></div>
            <button class="btn btn-primary" <?= $available ? '' : 'disabled' ?>><i class="fas fa-circle-arrow-up mr-1"></i>Update to <?= e($newer['latest']) ?></button>
          </form>
        <?php endif; ?>
        <form method="post" action="/settings/system/check" class="mt-3 d-flex align-items-center flex-wrap">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-default mr-2" <?= $available ? '' : 'disabled' ?>><i class="fas fa-rotate mr-1"></i>Check now</button>
          <span class="small text-muted">Checks GitHub every 6 hours. From the server: <code>sudo mountaineer-align-update</code></span>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-6">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-download mr-2"></i>Backup</h3>
        <div class="card-tools"><span class="badge badge-<?= $overdue ? 'warning' : 'success' ?> px-2 py-1"><?= $lastDownload ? 'Last ' . e(rel_time($lastDownload)) : 'Never downloaded' ?></span></div></div>
      <div class="card-body">
        <p class="small">Backups are <b>not stored on this server</b>. Download builds a backup of the database, uploaded files and the key for saved passwords and API keys, encrypted with this server's backup key. It's deleted from the server as soon as your browser has it.</p>
        <p class="small text-muted mb-3">Last downloaded: <?= $lastDownload ? e(fmt_datetime($lastDownload)) . ($lastDownloadBy ? ' by ' . e($lastDownloadBy) : '') : 'never' ?>. Keep backups somewhere safe, such as your file server or documentation system.</p>
        <form method="post" action="/settings/system/backup" class="mb-3">
          <?= csrf_field() ?>
          <button class="btn btn-success" <?= $available ? '' : 'disabled' ?>><i class="fas fa-download mr-1"></i>Download backup</button>
        </form>
        <form method="post" action="/settings/system/settings" class="form-inline small mb-3">
          <?= csrf_field() ?>
          <label class="mr-2 font-weight-normal" for="rem-days">Remind admins by email after</label>
          <input id="rem-days" type="number" name="backup_reminder_days" class="form-control form-control-sm mr-2" style="width:5em" min="0" max="90" value="<?= (int) $reminderDays ?>">
          <span class="mr-2">days without a download (0 = off)</span>
          <button class="btn btn-xs btn-default">Save</button>
        </form>

        <h6 id="key" class="border-top pt-3">Backup key</h6>
        <?php if ($sys && $sys['public_keys']): ?>
          <p class="small mb-1">Backups are encrypted to this public key. Restoring needs the matching <b>private key</b> (starts with <code>AGE-SECRET-KEY-1</code>), shown once when the server was installed. Without it a backup can't be opened, by anyone.</p>
          <input class="form-control form-control-sm text-monospace mb-2 select-all" readonly value="<?= e(implode(' ', $sys['public_keys'])) ?>">
        <?php elseif ($sys): ?>
          <div class="alert alert-danger py-2 small">This server has no backup key, so backups can't be made. Run <code>sudo mountaineer-align-update</code> on the server to create one.</div>
        <?php endif; ?>
        <?php if (!empty($sys['private_key_on_server'])): ?>
          <div class="alert alert-warning py-2 small"><i class="fas fa-triangle-exclamation mr-1"></i>The private backup key is still on the server (<code>/root/mountaineer-align-backup-key.txt</code>). Store it in your password manager, then remove it: <code>sudo shred -u /root/mountaineer-align-backup-key.txt</code></div>
        <?php endif; ?>
        <form method="post" action="/settings/system/keycheck">
          <?= csrf_field() ?>
          <label class="small mb-1" for="kc-key">Check that a key you've stored matches this server</label>
          <div class="input-group input-group-sm">
            <input type="password" id="kc-key" name="key" class="form-control text-monospace" placeholder="AGE-SECRET-KEY-1…" autocomplete="off" spellcheck="false">
            <div class="input-group-append"><button class="btn btn-default" <?= $available ? '' : 'disabled' ?>>Check key</button></div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card card-dark" id="restore">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left mr-2"></i>Restore</h3></div>
  <div class="card-body">
    <?php if (!$upload): ?>
      <p class="small">Upload a backup downloaded from this page (or from another Align server, to move to new hardware). Nothing changes until you confirm on the next step, and you can test a backup without restoring it.</p>
      <form method="post" action="/settings/system/upload" enctype="multipart/form-data" data-upload data-max="<?= (int) $maxUpload ?>">
        <?= csrf_field() ?>
        <div class="input-group" style="max-width:640px">
          <div class="custom-file">
            <input type="file" class="custom-file-input" id="bk-file" name="backup" accept=".tar,.age,application/x-tar">
            <label class="custom-file-label" for="bk-file">Choose backup file…</label>
          </div>
          <div class="input-group-append"><button class="btn btn-primary" <?= $available ? '' : 'disabled' ?>><i class="fas fa-upload mr-1"></i>Upload</button></div>
        </div>
        <div class="progress mt-2 d-none" style="max-width:640px;height:6px" data-upload-progress><div class="progress-bar" style="width:0"></div></div>
        <div class="small text-danger mt-1" data-upload-error></div>
        <p class="small text-muted mt-2 mb-0">Up to <?= e(fmt_bytes($maxUpload)) ?>. Larger backups, or a server you can't sign in to: copy the file to the server and run <code>sudo mountaineer-align-restore FILE</code>.</p>
      </form>
    <?php else: ?>
      <div class="d-flex flex-wrap align-items-start mb-3">
        <dl class="row mb-0 small mr-auto" style="min-width:320px;max-width:640px">
          <dt class="col-4 font-weight-normal text-muted">File</dt><dd class="col-8 mb-1"><?= e($upload['name']) ?> (<?= e(fmt_bytes($upload['size'])) ?>)</dd>
          <?php if ($info['kind'] === 'bundle'): ?>
            <dt class="col-4 font-weight-normal text-muted">Made</dt><dd class="col-8 mb-1"><?= e(fmt_datetime(date('Y-m-d H:i:s', strtotime($info['created'])))) ?><?= $info['tag'] && $info['tag'] !== 'download' ? ' <span class="text-muted">(' . e(str_replace('-', ' ', $info['tag'])) . ' safety copy)</span>' : '' ?></dd>
            <dt class="col-4 font-weight-normal text-muted">From</dt><dd class="col-8 mb-1"><?= e($info['host']) ?>, version <?= e($info['version']) ?></dd>
            <dt class="col-4 font-weight-normal text-muted">Contains</dt><dd class="col-8 mb-1">Database<?= $info['has_uploads'] ? ', ' . (int) $info['uploads_files'] . ' uploaded file' . ($info['uploads_files'] === 1 ? '' : 's') : '' ?>, encryption key for saved secrets</dd>
          <?php else: ?>
            <dt class="col-4 font-weight-normal text-muted">Type</dt><dd class="col-8 mb-1">Older nightly database backup (before 1.14). Database only. Saved API keys and two-factor secrets only work on the server that made it.</dd>
          <?php endif; ?>
        </dl>
        <form method="post" action="/settings/system/upload/discard"><?= csrf_field() ?><button class="btn btn-sm btn-default">Use a different file</button></form>
      </div>
      <form method="post" action="/settings/system/restore" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-group" style="max-width:640px">
          <label for="rs-key">Backup private key</label>
          <input type="password" id="rs-key" name="key" class="form-control text-monospace" placeholder="AGE-SECRET-KEY-1…" autocomplete="off" spellcheck="false">
          <small class="text-muted">Used once to open the backup and never saved.</small>
        </div>
        <button class="btn btn-default mb-3" formaction="/settings/system/verify" <?= $available ? '' : 'disabled' ?>><i class="fas fa-vial mr-1"></i>Test this backup</button>
        <span class="small text-muted ml-2">Opens and checks every part without changing anything.</span>

        <div class="border-top pt-3">
          <div class="alert alert-danger py-2 small" style="max-width:720px"><i class="fas fa-triangle-exclamation mr-1"></i>Restoring <b>replaces</b> the current data and signs everyone out. Align makes a safety copy first and puts it back automatically if anything goes wrong.</div>
          <div class="mb-2">
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="rs-db" name="restore_db" value="1" checked><label class="custom-control-label font-weight-normal" for="rs-db">Database</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="rs-up" name="restore_uploads" value="1" <?= !empty($info['has_uploads']) ? 'checked' : 'disabled' ?>><label class="custom-control-label font-weight-normal" for="rs-up">Uploaded files (logos, pictures, evidence)</label></div>
          </div>
          <div class="form-row" style="max-width:640px">
            <div class="form-group col-sm-6"><label for="rs-code">Your two-factor code</label><input id="rs-code" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="123456"></div>
            <div class="form-group col-sm-6"><label for="rs-confirm">Type RESTORE</label><input id="rs-confirm" name="confirm" class="form-control" autocomplete="off" placeholder="RESTORE"></div>
          </div>
          <button class="btn btn-danger" <?= $available ? '' : 'disabled' ?>><i class="fas fa-clock-rotate-left mr-1"></i>Restore</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($safety): ?>
  <div class="card">
    <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-life-ring mr-2"></i>Safety copies kept after a failed job</h3></div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead><tr><th>Made</th><th>When</th><th>Size</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($safety as $s): ?>
            <tr><td><?= e($s['kind']) ?></td><td><?= e(fmt_datetime(date('Y-m-d H:i:s', $s['time']))) ?></td><td><?= e(fmt_bytes($s['size'])) ?></td>
              <td class="text-right text-nowrap">
                <form method="post" action="/settings/system/safety/<?= e($s['name']) ?>/download" class="d-inline"><?= csrf_field() ?><button class="btn btn-xs btn-default"><i class="fas fa-download mr-1"></i>Download</button></form>
                <form method="post" action="/settings/system/safety/<?= e($s['name']) ?>/delete" class="d-inline"><?= csrf_field() ?><button class="btn btn-xs btn-outline-danger" data-confirm="Delete this safety copy from the server?">Delete</button></form>
              </td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="small text-muted px-3 py-2 mb-0">Encrypted with the backup key. Restore one like any other backup. Deleted automatically after 14 days.</p>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($sys['legacy']['count'])): ?>
  <div class="alert alert-secondary small">
    <i class="fas fa-box-archive mr-1"></i><?= (int) $sys['legacy']['count'] ?> old nightly backup files (<?= e(fmt_bytes($sys['legacy']['bytes'])) ?>) are still on the server in <code>/var/backups/mountaineer-align</code>. Nightly backups on the server have stopped. Copy them off first if you want to keep them.
    <form method="post" action="/settings/system/legacy/delete" class="form-inline mt-2">
      <?= csrf_field() ?>
      <div class="custom-control custom-checkbox mr-2"><input type="checkbox" class="custom-control-input" id="lg-confirm" name="confirm" value="1"><label class="custom-control-label font-weight-normal" for="lg-confirm">Delete them from the server</label></div>
      <button class="btn btn-xs btn-outline-danger">Delete old backups</button>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-list mr-2"></i>Recent jobs</h3></div>
  <div class="card-body p-0">
    <?php if (!$jobs): ?><p class="text-muted small p-3 mb-0">Nothing yet.</p><?php else: ?>
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>When</th><th>Job</th><th>By</th><th>Result</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($jobs as $j): ?>
          <tr>
            <td class="text-nowrap"><?= e(fmt_datetime(date('Y-m-d H:i:s', strtotime($j['started'] ?? $j['created'])))) ?></td>
            <td><?= e(Agent::ACTIONS[$j['action']] ?? $j['action']) ?></td>
            <td class="small"><?= e(preg_replace('/\s*<[^>]*>$/', '', (string) $j['user'])) ?></td>
            <td><span class="badge badge-<?= $stateBadge($j['state']) ?>"><?= e($stateText($j['state'])) ?></span> <span class="small"><?= e(mb_strimwidth((string) ($j['message'] ?? $j['step'] ?? ''), 0, 140, '…')) ?></span></td>
            <td class="text-right"><a class="small" href="/settings/system/jobs/<?= e($j['id']) ?>/log" target="_blank" rel="noopener">Log</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

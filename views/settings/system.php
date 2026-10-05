<?php
use Align\System\Agent;

/**
 * Settings → Updates & backups (admins).
 * @var bool $available; ?array $update, $newer, $sys, $active, $upload; array $jobs, $safety; ?string $watch, $lastDownload, $lastDownloadBy; int $reminderDays, $maxUpload
 * Security: job, update and system files are written by the agent and treated as data here: every field is escaped,
 * release notes go through md_inline() (escaped first, never links). The uploaded backup's manifest (host, version,
 * dates) can be crafted by whoever made the file and is escaped like the rest. The backup key fields are password
 * inputs that are never filled in.
 */
$stateBadge = fn(string $s) => ['succeeded' => 'success', 'failed' => 'danger', 'running' => 'primary', 'queued' => 'secondary'][$s] ?? 'secondary';
$stateText = fn(string $s) => ['succeeded' => 'Done', 'failed' => 'Failed', 'running' => 'Running', 'queued' => 'Queued'][$s] ?? $s;
$watchId = $watch ?? ($active['id'] ?? null);
$watchJob = $watchId ? Agent::job($watchId) : null;
$overdue = !$lastDownload || strtotime($lastDownload) < time() - max(1, $reminderDays ?: 7) * 86400;
$info = $upload['info'] ?? null;
$docker = Agent::docker();
?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'system']) ?>

<?php if (!$available && $docker): ?>
  <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-1"></i>The backup service isn't running in this container. Restart it (<code>docker compose restart app</code>), then refresh.</div>
<?php elseif (!$available): ?>
  <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-1"></i>The update and backup service isn't installed on this server yet. Run this once on the server, then refresh:
    <code class="d-block mt-1">sudo msp-align-update</code></div>
<?php endif; ?>

<?php $overlayOn = $watchJob && in_array($watchJob['action'] ?? '', ['update', 'restore'], true) && in_array($watchJob['state'], ['running', 'queued'], true); ?>
<div class="job-overlay" id="job-overlay" <?= $overlayOn ? '' : 'hidden' ?> role="dialog" aria-modal="true" aria-labelledby="job-overlay-title" aria-live="polite">
  <div class="job-overlay-card card card-outline card-primary">
    <div class="card-body py-4">
      <div class="text-center">
        <i class="fas fa-gear fa-spin fa-2x text-primary mb-3" data-ov-icon></i>
        <h2 class="h5 mb-1" id="job-overlay-title" data-ov-title><?= ($watchJob['action'] ?? '') === 'restore' ? 'Restoring from the backup' : 'Updating ' . e(APP_NAME) ?></h2>
        <p class="text-muted mb-3" data-ov-sub>Please wait. This usually takes a few minutes.</p>
      </div>
      <div class="progress progress-update mb-1" role="progressbar" aria-label="Progress" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar progress-bar-striped progress-bar-animated" data-ov-bar style="width: 2%"></div>
      </div>
      <div class="d-flex small text-muted mb-3"><span class="me-auto" data-ov-step><?= e(($watchJob['step'] ?? '') ?: 'Starting') ?>…</span><span><span data-ov-pct>2</span>% · <span data-ov-time>0 s</span></span></div>
      <p class="small mb-0 text-center" data-ov-note><i class="fas fa-rotate me-1 text-muted"></i>Keep this tab open. It refreshes on its own when everything is finished; you don't need to do anything.</p>
      <div class="text-center mt-3" data-ov-actions hidden><button type="button" class="btn btn-sm btn-default" data-ov-close>Close and see details</button></div>
    </div>
  </div>
</div>

<?php if ($watchJob): ?>
  <div class="card card-outline card-<?= $stateBadge($watchJob['state']) ?>" data-job-watch="<?= e($watchId) ?>" data-job-action="<?= e($watchJob['action'] ?? '') ?>">
    <div class="card-header py-2 d-flex align-items-center">
      <h3 class="card-title me-auto"><i class="fas fa-fw fa-list-check me-2"></i><span data-job-label><?= e(Agent::ACTIONS[$watchJob['action'] ?? ''] ?? 'Job') ?></span></h3>
      <span class="badge text-bg-<?= $stateBadge($watchJob['state']) ?> px-2 py-1" data-job-state><?= e($stateText($watchJob['state'])) ?></span>
    </div>
    <div class="card-body">
      <p class="mb-1"><i class="fas fa-spinner fa-spin me-1<?= in_array($watchJob['state'], ['succeeded', 'failed'], true) ? ' d-none' : '' ?>" data-job-spinner></i><span data-job-step><?= e($watchJob['step'] ?? '') ?></span></p>
      <p class="mb-2 fw-bold" data-job-message><?= e($watchJob['message'] ?? '') ?></p>
      <div data-job-result class="small"></div>
      <details class="mt-2"<?= ($watchJob['state'] ?? '') === 'failed' ? ' open' : '' ?>><summary class="small text-muted">Details</summary>
        <pre class="job-log small bg-dark text-light p-2 mt-2 mb-1 rounded" data-job-log><?= e(Agent::log($watchId, 16384)) ?></pre>
        <a class="small" href="/settings/system/jobs/<?= e($watchId) ?>/log" target="_blank" rel="noopener">Full log</a>
      </details>
      <form method="post" action="/settings/system/download/<?= e($watchId) ?>" data-job-download class="mt-2<?= ($watchJob['action'] ?? '') === 'backup' && $watchJob['state'] === 'succeeded' && is_file((string) Agent::downloadPath($watchId)) ? '' : ' d-none' ?>">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-success"><i class="fas fa-download me-1"></i>Download backup</button>
        <span class="small text-muted ms-2">If the download didn't start on its own. It works once; the file is then deleted from the server.</span>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="row">
  <div class="col-xl-6">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-arrow-up me-2"></i>Updates</h3>
        <div class="card-tools"><?= $newer ? '<span class="badge text-bg-info px-2 py-1">Update available</span>' : ($update && !$update['error'] ? '<span class="badge text-bg-success px-2 py-1">Up to date</span>' : '') ?></div></div>
      <div class="card-body">
        <dl class="row mb-2">
          <dt class="col-5 fw-normal text-muted">This server</dt><dd class="col-7 mb-1"><b><?= e(APP_VERSION) ?></b></dd>
          <dt class="col-5 fw-normal text-muted">Latest</dt><dd class="col-7 mb-1"><?= $update && $update['latest'] ? e($update['latest']) . ($newer && ($update['behind'] ?? 0) && empty($update['notes']) ? ' <span class="text-muted small">(' . (int) $update['behind'] . ' change' . ((int) $update['behind'] === 1 ? '' : 's') . ')</span>' : '') : '<span class="text-muted">Not checked yet</span>' ?></dd>
          <?php $br = (string) (\Align\Config::get('update_branch') ?: 'main'); // this server's own setting (config.php) ?>
          <?php $signedMode = ($update['mode'] ?? '') === 'signed'; $prints = (array) ($update['signers'] ?? []); ?>
          <dt class="col-5 fw-normal text-muted">Updates from</dt><dd class="col-7 mb-1"><?php if ($docker): ?>Docker images (<code>docker compose pull</code>)<?php elseif ($signedMode): ?><i class="fas fa-shield-halved text-success me-1"></i>Signed releases only
            <?php foreach ($prints as $p): ?><span class="d-block small text-muted text-break" title="Release key fingerprint: compare with the one on mspalign.org">Release key <code class="user-select-all"><?= e((string) $p) ?></code></span><?php endforeach; ?>
            <?php if (($update['head_signed'] ?? true) === false): ?><span class="d-block small text-warning-emphasis">The code here isn't a signed release yet: the next update installs one.</span><?php endif; ?>
            <?php elseif ($br === 'main' || $br === ''): ?>Releases (main)<?php if ($update && ($update['mode'] ?? '') === 'branch'): ?> <span class="small text-muted">not signed</span><?php endif; ?><?php else: ?><span class="badge text-bg-warning">Test channel</span> <?= e($br) ?> <span class="small text-muted">not signed</span><?php endif; ?></dd>
          <dt class="col-5 fw-normal text-muted">Last checked</dt><dd class="col-7 mb-1"><?= $update ? e(rel_time(date('Y-m-d H:i:s', strtotime($update['checked_at'])))) : '<span class="text-muted">Never</span>' ?></dd>
        </dl>
        <?php if ($update && $update['error']): ?><div class="alert alert-warning py-2 small"><?= e($update['error']) ?></div><?php endif; ?>
        <?php if ($update && !empty($update['warning'])): ?><div class="alert alert-danger py-2 small"><i class="fas fa-triangle-exclamation me-1"></i><?= e($update['warning']) ?></div><?php endif; ?>

        <?php if ($newer): ?>
          <h6 class="mt-3">What's new</h6>
          <?php if (!empty($newer['notes'])): // the release notes (README), not every change ?>
          <ul class="list-unstyled small update-changes mb-3">
            <?php foreach ($newer['notes'] as $n): ?>
              <li class="mb-2"><b><?= e($n['title']) ?></b> <span class="text-muted"><?= e($n['version']) ?></span>
                <?php if ($n['text'] !== ''): ?><div class="text-muted"><?= md_inline(mb_strtoupper(mb_substr($n['text'], 0, 1)) . mb_substr($n['text'], 1)) ?></div><?php endif; ?>
                <?php if ($n['items']): ?><ul class="text-muted ps-3 mb-0"><?php foreach ($n['items'] as $it): ?><li<?= $it['level'] > 1 ? ' class="ms-3"' : '' ?>><?= md_inline($it['text']) ?></li><?php endforeach; ?></ul><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?>
          <ul class="list-unstyled small update-changes mb-3">
            <?php foreach (array_slice($newer['changes'] ?? [], 0, 30) as $c): ?>
              <li class="mb-2"><b><?= e($c['subject']) ?></b> <span class="text-muted"><?= e(\Align\Fmt::date($c['date'] ?: 'now', 'short')) ?></span>
                <?php if (trim($c['body'] ?? '') !== ''): ?><div class="text-muted text-pre-line"><?= e(mb_strimwidth($c['body'], 0, 600, '…')) ?></div><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <?php if ($docker): ?>
          <div class="border-top pt-3 small">
            <p class="mb-1">This server runs in <b>Docker</b>, so it updates by pulling the new image. On the Docker host, in the MSP Align folder:</p>
            <code class="d-block select-all mb-1">docker compose pull &amp;&amp; docker compose up -d</code>
            <p class="text-muted mb-0">Download a backup first. The database is updated automatically when the new version starts.</p>
          </div>
          <?php else: ?>
          <form method="post" action="/settings/system/update" class="border-top pt-3">
            <?= csrf_field() ?>
            <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="upd-confirm" name="confirm" value="1">
              <label class="form-check-label fw-normal" for="upd-confirm">Align will be unavailable for a minute or two while it updates. A safety copy of the data is made first and deleted once the update succeeds.</label></div>
            <button class="btn btn-primary" <?= $available ? '' : 'disabled' ?>><i class="fas fa-circle-arrow-up me-1"></i>Update to <?= e($newer['latest']) ?></button>
          </form>
          <?php endif; ?>
        <?php endif; ?>
        <form method="post" action="/settings/system/check" class="mt-3 d-flex align-items-center flex-wrap">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-default me-2" <?= $available ? '' : 'disabled' ?>><i class="fas fa-rotate me-1"></i>Check now</button>
          <span class="small text-muted">Checks GitHub every 6 hours.<?= $docker ? ' Runs in Docker: update with <code>docker compose pull &amp;&amp; docker compose up -d</code>' : ' From the server: <code>sudo msp-align-update</code>' ?></span>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-6">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-download me-2"></i>Backup</h3>
        <div class="card-tools"><span class="badge text-bg-<?= $overdue ? 'warning' : 'success' ?> px-2 py-1"><?= $lastDownload ? 'Last ' . e(rel_time($lastDownload)) : 'Never downloaded' ?></span></div></div>
      <div class="card-body">
        <p class="small">Backups are <b>not stored on this server</b>. Download builds a backup of the database, uploaded files and the key for saved passwords and API keys, encrypted with this server's backup key. It's deleted from the server as soon as your browser has it.</p>
        <p class="small text-muted mb-3">Last downloaded: <?= $lastDownload ? e(fmt_datetime($lastDownload)) . ($lastDownloadBy ? ' by ' . e($lastDownloadBy) : '') : 'never' ?>. Keep backups somewhere safe, such as your file server or documentation system.</p>
        <form method="post" action="/settings/system/backup" class="mb-3">
          <?= csrf_field() ?>
          <button class="btn btn-success" <?= $available ? '' : 'disabled' ?>><i class="fas fa-download me-1"></i>Download backup</button>
        </form>
        <form method="post" action="/settings/system/settings" class="d-flex flex-wrap align-items-center small mb-3">
          <?= csrf_field() ?>
          <label class="me-2 fw-normal" for="rem-days">Remind admins by email after</label>
          <input id="rem-days" type="number" name="backup_reminder_days" class="form-control form-control-sm me-2" style="width:5em" min="0" max="90" value="<?= (int) $reminderDays ?>">
          <span class="me-2">days without a download (0 = off)</span>
          <button class="btn btn-xs btn-default">Save</button>
        </form>

        <h6 id="key" class="border-top pt-3">Backup key</h6>
        <?php if ($sys && $sys['public_keys']): ?>
          <p class="small mb-1">Backups are encrypted to this public key. Restoring needs the matching <b>private key</b> (starts with <code>AGE-SECRET-KEY-1</code>), shown once when the server was installed. Without it a backup can't be opened, by anyone.</p>
          <input class="form-control form-control-sm font-monospace mb-2 select-all" readonly value="<?= e(implode(' ', $sys['public_keys'])) ?>">
        <?php elseif ($sys): ?>
          <div class="alert alert-danger py-2 small">This server has no backup key, so backups can't be made. <?= $docker ? 'Restart the container to create one.' : 'Run <code>sudo msp-align-update</code> on the server to create one.' ?></div>
        <?php endif; ?>
        <?php if (!empty($sys['private_key_on_server'])): ?>
          <div class="alert alert-warning py-2 small"><i class="fas fa-triangle-exclamation me-1"></i>The private backup key is still on the server<?php if ($docker): ?>. Show it with <code>docker compose exec app cat /etc/msp-align/backup-key.txt</code>, store it in your password manager, then remove it: <code>docker compose exec app rm /etc/msp-align/backup-key.txt</code><?php else: ?> (<code>/root/msp-align-backup-key.txt</code>). Store it in your password manager, then remove it: <code>sudo shred -u /root/msp-align-backup-key.txt</code><?php endif; ?></div>
        <?php endif; ?>
        <form method="post" action="/settings/system/keycheck">
          <?= csrf_field() ?>
          <label class="small mb-1" for="kc-key">Check that a key you've stored matches this server</label>
          <div class="input-group input-group-sm">
            <input type="password" id="kc-key" name="key" class="form-control font-monospace" placeholder="AGE-SECRET-KEY-1…" autocomplete="off" spellcheck="false">
            <button class="btn btn-default" <?= $available ? '' : 'disabled' ?>>Check key</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card card-dark" id="restore">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left me-2"></i>Restore</h3></div>
  <div class="card-body">
    <?php if (!$upload): ?>
      <p class="small">Upload a backup downloaded from this page (or from another Align server, to move to new hardware). Nothing changes until you confirm on the next step, and you can test a backup without restoring it.</p>
      <form method="post" action="/settings/system/upload" enctype="multipart/form-data" data-upload data-max="<?= (int) $maxUpload ?>">
        <?= csrf_field() ?>
        <div class="input-group" style="max-width:640px">
          
            <input type="file" class="form-control" id="bk-file" name="backup" accept=".tar,.age,application/x-tar">
            
          
          <button class="btn btn-primary" <?= $available ? '' : 'disabled' ?>><i class="fas fa-upload me-1"></i>Upload</button>
        </div>
        <div class="progress mt-2 d-none" style="max-width:640px;height:6px" data-upload-progress><div class="progress-bar" style="width:0"></div></div>
        <div class="small text-danger mt-1" data-upload-error></div>
        <p class="small text-muted mt-2 mb-0">Up to <?= e(fmt_bytes($maxUpload)) ?>. Larger backups, or a server you can't sign in to: copy the file to the server and run <code>sudo msp-align-restore FILE</code>.</p>
      </form>
    <?php else: ?>
      <div class="d-flex flex-wrap align-items-start mb-3">
        <dl class="row mb-0 small me-auto" style="min-width:320px;max-width:640px">
          <dt class="col-4 fw-normal text-muted">File</dt><dd class="col-8 mb-1"><?= e($upload['name']) ?> (<?= e(fmt_bytes($upload['size'])) ?>)</dd>
          <?php if ($info['kind'] === 'bundle'): ?>
            <dt class="col-4 fw-normal text-muted">Made</dt><dd class="col-8 mb-1"><?= e(fmt_datetime(date('Y-m-d H:i:s', strtotime($info['created'])))) ?><?= $info['tag'] && $info['tag'] !== 'download' ? ' <span class="text-muted">(' . e(str_replace('-', ' ', $info['tag'])) . ' safety copy)</span>' : '' ?></dd>
            <dt class="col-4 fw-normal text-muted">From</dt><dd class="col-8 mb-1"><?= e($info['host']) ?>, version <?= e($info['version']) ?></dd>
            <dt class="col-4 fw-normal text-muted">Contains</dt><dd class="col-8 mb-1">Database<?= $info['has_uploads'] ? ', ' . (int) $info['uploads_files'] . ' uploaded file' . ($info['uploads_files'] === 1 ? '' : 's') : '' ?>, encryption key for saved secrets</dd>
          <?php else: ?>
            <dt class="col-4 fw-normal text-muted">Type</dt><dd class="col-8 mb-1">Older nightly database backup (before 1.14). Database only. Saved API keys and two-factor secrets only work on the server that made it.</dd>
          <?php endif; ?>
        </dl>
        <form method="post" action="/settings/system/upload/discard"><?= csrf_field() ?><button class="btn btn-sm btn-default">Use a different file</button></form>
      </div>
      <form method="post" action="/settings/system/restore" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3" style="max-width:640px">
          <label for="rs-key">Backup private key</label>
          <input type="password" id="rs-key" name="key" class="form-control font-monospace" placeholder="AGE-SECRET-KEY-1…" autocomplete="off" spellcheck="false">
          <small class="text-muted">Used once to open the backup and never saved.</small>
        </div>
        <button class="btn btn-default mb-3" formaction="/settings/system/verify" <?= $available ? '' : 'disabled' ?>><i class="fas fa-vial me-1"></i>Test this backup</button>
        <span class="small text-muted ms-2">Opens and checks every part without changing anything.</span>

        <div class="border-top pt-3">
          <div class="alert alert-danger py-2 small" style="max-width:720px"><i class="fas fa-triangle-exclamation me-1"></i>Restoring <b>replaces</b> the current data and signs everyone out. Align makes a safety copy first and puts it back automatically if anything goes wrong.</div>
          <div class="mb-2">
            <div class="form-check form-check-inline"><input type="checkbox" class="form-check-input" id="rs-db" name="restore_db" value="1" checked><label class="form-check-label fw-normal" for="rs-db">Database</label></div>
            <div class="form-check form-check-inline"><input type="checkbox" class="form-check-input" id="rs-up" name="restore_uploads" value="1" <?= !empty($info['has_uploads']) ? 'checked' : 'disabled' ?>><label class="form-check-label fw-normal" for="rs-up">Uploaded files (logos, pictures, evidence)</label></div>
          </div>
          <div class="row g-2" style="max-width:640px">
            <div class="mb-3 col-sm-6"><label for="rs-code">Your two-factor code</label><input id="rs-code" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="123456"></div>
            <div class="mb-3 col-sm-6"><label for="rs-confirm">Type RESTORE</label><input id="rs-confirm" name="confirm" class="form-control" autocomplete="off" placeholder="RESTORE"></div>
          </div>
          <button class="btn btn-danger" <?= $available ? '' : 'disabled' ?>><i class="fas fa-clock-rotate-left me-1"></i>Restore</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($safety): ?>
  <div class="card">
    <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-life-ring me-2"></i>Safety copies kept after a failed job</h3></div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>Made</th><th>When</th><th>Size</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($safety as $s): ?>
            <tr><td><?= e($s['kind']) ?></td><td><?= e(fmt_datetime(date('Y-m-d H:i:s', $s['time']))) ?></td><td><?= e(fmt_bytes($s['size'])) ?></td>
              <td class="text-end text-nowrap">
                <form method="post" action="/settings/system/safety/<?= e($s['name']) ?>/download" class="d-inline"><?= csrf_field() ?><button class="btn btn-xs btn-default"><i class="fas fa-download me-1"></i>Download</button></form>
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
    <i class="fas fa-box-archive me-1"></i><?= (int) $sys['legacy']['count'] ?> old nightly backup files (<?= e(fmt_bytes($sys['legacy']['bytes'])) ?>) are still on the server in <code>/var/backups/mountaineer-align</code>. Nightly backups on the server have stopped. Copy them off first if you want to keep them.
    <form method="post" action="/settings/system/legacy/delete" class="d-flex flex-wrap align-items-center mt-2">
      <?= csrf_field() ?>
      <div class="form-check me-2"><input type="checkbox" class="form-check-input" id="lg-confirm" name="confirm" value="1"><label class="form-check-label fw-normal" for="lg-confirm">Delete them from the server</label></div>
      <button class="btn btn-xs btn-outline-danger">Delete old backups</button>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-list me-2"></i>Recent jobs</h3></div>
  <div class="card-body p-0">
    <?php if (!$jobs): ?><p class="text-muted small p-3 mb-0">Nothing yet.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr><th>When</th><th>Job</th><th>By</th><th>Result</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($jobs as $j): ?>
          <tr>
            <td class="text-nowrap"><?= e(fmt_datetime(date('Y-m-d H:i:s', strtotime($j['started'] ?? $j['created'])))) ?></td>
            <td><?= e(Agent::ACTIONS[$j['action']] ?? $j['action']) ?></td>
            <td class="small"><?= e(preg_replace('/\s*<[^>]*>$/', '', (string) $j['user'])) ?></td>
            <td><span class="badge text-bg-<?= $stateBadge($j['state']) ?>"><?= e($stateText($j['state'])) ?></span> <span class="small"><?= e(mb_strimwidth((string) ($j['message'] ?? $j['step'] ?? ''), 0, 140, '…')) ?></span></td>
            <td class="text-end"><a class="small" href="/settings/system/jobs/<?= e($j['id']) ?>/log" target="_blank" rel="noopener">Log</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

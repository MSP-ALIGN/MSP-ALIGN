<?php
use Align\Portal\PortalAuth;

/** @var array $client, $users, $contacts, $activity; ?array $link; string $portalUrl */
require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$status = function (array $u): array {
    if (!$u['is_active']) return ['Disabled', 'secondary'];
    if (!$u['password_hash']) return [$u['invite_expires_at'] && $u['invite_expires_at'] >= date('Y-m-d H:i:s') ? 'Invited' : 'Invite expired', $u['invite_expires_at'] && $u['invite_expires_at'] >= date('Y-m-d H:i:s') ? 'info' : 'warning'];
    return ['Active', 'success'];
};
$sectionShort = ['can_roadmap' => 'Roadmap', 'can_budget' => 'Budget', 'can_devices' => 'Devices', 'can_documents' => 'Documents'];
$actionShort = ['can_approve' => 'Approves projects', 'can_submit' => 'Suggests items', 'can_contacts' => 'Sends requests'];
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-door-open me-2 text-secondary"></i>Client portal</h1>
    <div class="small text-muted">Give people at <?= e($client['name']) ?> their own sign-in to see their plan and act on it. They only ever see this client's data. Portal address: <a href="<?= e($portalUrl) ?>" target="_blank"><?= e($portalUrl) ?></a></div></div>
  <button class="btn btn-sm btn-primary mt-2 mt-md-0" data-bs-toggle="modal" data-bs-target="#modal-portal-invite"><i class="fas fa-user-plus me-1"></i>Invite user</button>
</div>

<?php if ($link): $subject = 'Your ' . $client['name'] . ' technology portal';
    $body = "Hi " . explode(' ', $link['name'])[0] . ",\n\n" . ($link['kind'] === 'reset' ? 'Use this link to set a new password for' : 'You now have access to') . " the " . $client['name'] . " client portal, where you can see your technology roadmap, budget and documents.\n\n"
        . ($link['kind'] === 'reset' ? 'Reset your password' : 'Set your password') . " here (the link works once and expires in " . PortalAuth::INVITE_DAYS . " days):\n" . $link['url'] . "\n\nAfter that, sign in at $portalUrl\n"; ?>
  <div class="card card-outline card-success">
    <div class="card-body">
      <div class="d-flex align-items-start mb-2"><i class="fas fa-link text-success me-2 mt-1"></i>
        <div class="me-auto"><b><?= $link['kind'] === 'reset' ? 'Password reset link' : 'Invite link' ?> for <?= e($link['name']) ?></b>
          <div class="small text-muted">Shown only once. It works one time and expires in <?= PortalAuth::INVITE_DAYS ?> days. <?= \Align\Mail\Notifications::enabled('client_portal_invite') && \Align\Mail\Mail::ready() ? 'If it was emailed, there\'s nothing else to do; otherwise send it to ' . e($link['email']) . ' yourself.' : 'Send it to ' . e($link['email']) . ' yourself; Align doesn\'t email it.' ?></div></div></div>
      <div class="input-group input-group-sm mb-2">
        <input class="form-control" id="portal-link" value="<?= e($link['url']) ?>" readonly>
        <button class="btn btn-default" type="button" data-copy="#portal-link"><i class="fas fa-copy me-1"></i>Copy</button>
      </div>
      <a class="btn btn-sm btn-success" href="mailto:<?= e(rawurlencode($link['email'])) ?>?subject=<?= e(rawurlencode($subject)) ?>&amp;body=<?= e(rawurlencode($body)) ?>"><i class="fas fa-envelope me-1"></i>Open in email</a>
    </div>
  </div>
<?php endif; ?>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-users me-2"></i>Portal users</h3><div class="card-tools"><span class="badge text-bg-light"><?= count($users) ?></span></div></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>Name</th><th>Can see</th><th>Can do</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): [$sl, $st] = $status($u); ?>
        <tr class="<?= $u['is_active'] ? '' : 'text-muted' ?>">
          <td><a href="#" class="fw-bold" data-bs-toggle="modal" data-bs-target="#modal-portal-<?= (int) $u['id'] ?>"><?= e($u['name']) ?></a><div class="small text-muted"><?= e($u['email']) ?></div></td>
          <td class="small"><?= e(implode(', ', array_values(array_filter($sectionShort, fn($k) => $u[$k], ARRAY_FILTER_USE_KEY)))) ?: '—' ?></td>
          <td class="small"><?= e(implode(', ', array_values(array_filter($actionShort, fn($k) => $u[$k], ARRAY_FILTER_USE_KEY)))) ?: 'View only' ?></td>
          <td><span class="badge text-bg-<?= $st ?>"><?= $sl ?></span><?= $u['totp_enabled'] ? ' <span class="badge text-bg-light border" title="Two-factor sign-in on"><i class="fas fa-shield-halved"></i> 2FA</span>' : '' ?></td>
          <td class="small text-nowrap"><?= $u['last_login_at'] ? e(rel_time($u['last_login_at'])) : '<span class="text-muted">never</span>' ?></td>
          <td class="text-end text-nowrap"><button class="btn btn-xs btn-default" data-bs-toggle="modal" data-bs-target="#modal-portal-<?= (int) $u['id'] ?>">Manage</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-4">No portal users yet. Invite the owner or office manager to start.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row">
  <div class="col-lg-5">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-shield-halved me-2"></i>Security</h3></div>
      <div class="card-body">
        <p class="mb-2"><i class="fas fa-lock text-success me-1"></i><b>Two-factor sign-in is required</b> for every portal user. They set up an authenticator app right after choosing a password.</p>
        <p class="small text-muted mb-0">Sign-ins lock for 15 minutes after 5 failed attempts. Sessions end after <?= (int) (\Align\Security::idleSeconds() / 60) ?> minutes of inactivity (Settings &rarr; Security) and after <?= (int) (\Align\Security::maxSeconds() / 3600) ?> hours regardless. Changing a password or resetting two-factor signs the user out everywhere.</p>
      </div>
    </div>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-eye me-2"></i>What clients see</h3></div>
      <div class="card-body small">
        <ul class="ps-3 mb-0">
          <li>Only <b><?= e($client['name']) ?></b>'s data, never other clients.</li>
          <li>No internal notes: device notes, license and contact notes, meeting notes, compliance evidence and internal meetings stay private.</li>
          <li>Documents only when <b>Active</b> and shared (policies and plans are shared by default; toggle it on each document).</li>
          <li>Prices only for users with Budget &amp; licensing access.</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left me-2"></i>Recent portal activity</h3></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($activity as $a): ?>
          <li class="list-group-item py-2 d-flex"><span class="text-muted text-nowrap me-3" style="min-width:90px"><?= e(rel_time($a['created_at'])) ?></span>
            <span class="me-auto"><b><?= e($a['portal_name']) ?></b> <?= e(\Align\Controllers\AuditController::portalLabel($a['action'])) ?><?= $a['detail'] && !in_array($a['action'], ['portal.login', 'portal.view', 'portal.logout'], true) ? ' <span class="text-muted">— ' . e(preg_replace('/^' . preg_quote($client['name'], '/') . ':\s*/', '', $a['detail'])) . '</span>' : '' ?></span></li>
        <?php endforeach; ?>
        <?php if (!$activity): ?><li class="list-group-item text-muted">No activity yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-portal-invite" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/clients/<?= $cid ?>/portal">
      <?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Invite a <?= e($client['name']) ?> user</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="mb-3 col-md-6"><label>Name</label><input name="name" class="form-control" required maxlength="190" id="invite-name"></div>
          <div class="mb-3 col-md-6"><label>Email</label><input type="email" name="email" class="form-control" required id="invite-email" list="invite-contacts"></div>
          <?php if ($contacts): ?><datalist id="invite-contacts"><?php foreach ($contacts as $k): ?><option value="<?= e($k['email']) ?>" data-name="<?= e($k['name']) ?>"><?= e($k['name']) ?></option><?php endforeach; ?></datalist><?php endif; ?>
        </div>
        <?php if ($contacts): ?><p class="small text-muted mt-n2">Start typing to pick one of their contacts.</p><?php endif; ?>
        <?= \Align\View::fetch('portal_admin/_perms', ['u' => null, 'pid' => 'inv']) ?>
        <?php if (\Align\Mail\Notifications::enabled('client_portal_invite') && \Align\Mail\Mail::ready()): ?>
          <input type="hidden" name="send_email" value="0">
          <div class="form-check mt-2"><input type="checkbox" class="form-check-input" id="inv-send" name="send_email" value="1" checked><label class="form-check-label fw-normal" for="inv-send">Email the invitation to them now</label></div>
          <p class="small text-muted mb-0 mt-1">They get a branded email with a one-time link and set their own password. The link is also shown to you once.</p>
        <?php else: ?>
        <p class="small text-muted mb-0 mt-2">You'll get a one-time link to send them. They set their own password.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="fas fa-link me-1"></i>Create invite link</button></div>
    </form>
  </div></div>
</div>

<?php foreach ($users as $u): [$sl] = $status($u); ?>
<div class="modal fade" id="modal-portal-<?= (int) $u['id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/portal-users/<?= (int) $u['id'] ?>">
      <?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title"><?= e($u['name']) ?> <small class="text-light"><?= e($u['email']) ?></small></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label>Name</label><input name="name" class="form-control" value="<?= e($u['name']) ?>" maxlength="190"></div>
        <?= \Align\View::fetch('portal_admin/_perms', ['u' => $u, 'pid' => 'pu' . (int) $u['id']]) ?>
        <div class="border-top mt-3 pt-3 d-flex flex-wrap">
          <button class="btn btn-sm btn-default me-2 mb-2" name="action" value="link" formnovalidate><i class="fas fa-link me-1"></i><?= $u['password_hash'] ? 'Password reset link' : 'New invite link' ?></button>
          <?php if ($u['totp_enabled']): ?><button class="btn btn-sm btn-default me-2 mb-2" name="action" value="reset2fa" formnovalidate data-confirm="Reset two-factor sign-in for <?= e($u['name']) ?>?"><i class="fas fa-shield-halved me-1"></i>Reset 2FA</button><?php endif; ?>
          <?php if ($u['is_active']): ?><button class="btn btn-sm btn-outline-secondary me-2 mb-2" name="action" value="disable" formnovalidate data-confirm="Disable <?= e($u['name']) ?>? They are signed out right away."><i class="fas fa-ban me-1"></i>Disable</button>
          <?php else: ?><button class="btn btn-sm btn-outline-success me-2 mb-2" name="action" value="enable" formnovalidate><i class="fas fa-check me-1"></i>Enable</button><?php endif; ?>
          <button class="btn btn-sm btn-outline-danger mb-2" name="action" value="delete" formnovalidate data-confirm="Delete <?= e($u['name']) ?>'s portal account? Disabling keeps their history easier to follow."><i class="fas fa-trash"></i></button>
        </div>
        <div class="small text-muted"><?= e($sl) ?> · invited <?= e(fmt_date($u['created_at'])) ?><?= $u['last_login_at'] ? ' · last signed in ' . e(rel_time($u['last_login_at'])) : '' ?></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Save access</button></div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>

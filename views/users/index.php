<?php
/**
 * Staff accounts (admins). Vars: $users, $roles (UserController::ROLES), ?$newPassword [email, password] (shown once).
 * Security: names and emails are escaped, also inside the confirm texts (data attributes); every button is a POST
 * form with the CSRF token, and the controller re-checks every rule (last admin, yourself).
 */
?><?php if ($newPassword): ?>
  <div class="callout callout-success">
    <h5>Temporary password for <?= e($newPassword['email']) ?></h5>
    <p class="mb-1"><code class="h5 select-all"><?= e($newPassword['password']) ?></code></p>
    <p class="small text-muted mb-0">Shown once. Send it securely. They must change it, and set up two-factor sign-in, the first time they sign in.</p>
  </div>
<?php endif; ?>

<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-shield me-2"></i>Users</h3>
    <div class="card-tools"><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-user"><i class="fas fa-plus me-1"></i>New user</button></div>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-striped table-borderless table-hover mb-0">
      <thead class="text-dark"><tr><th>Name</th><th>Email</th><th>Role</th><th>2FA</th><th>Last sign-in</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr class="<?= $u['is_active'] ? '' : 'text-muted' ?>">
          <td class="align-middle"><?= user_avatar($u, 'user-initials', 'me-2') ?><?= e($u['name']) ?><?= $u['is_active'] ? '' : ' <span class="badge text-bg-secondary">disabled</span>' ?></td>
          <td class="align-middle"><?= e($u['email']) ?></td>
          <td class="align-middle">
            <form method="post" action="/users/<?= (int) $u['id'] ?>" class="d-inline"><?= csrf_field() ?>
              <input type="hidden" name="action" value="role">
              <select name="role" class="form-select form-select-sm w-auto" data-autosubmit data-confirm-ok="Change role"
                data-confirm-change="<?= e('Change ' . $u['name'] . '\'s role from {from} to {to}? ' . ((int) $u['id'] === (int) \Align\Auth::id() ? 'That\'s you: you lose anything the new role can\'t do as soon as it\'s saved.' : 'It takes effect right away.')) ?>">
                <?php foreach ($roles as $r => $label): ?><option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="align-middle"><?= $u['totp_enabled'] ? '<span class="badge text-bg-success">on</span>' : '<span class="badge text-bg-light border">off</span>' ?></td>
          <td class="align-middle small"><?= e(rel_time($u['last_login_at'])) ?></td>
          <td class="text-end text-nowrap">
            <?php foreach (['reset' => 'Reset password', 'toggle' => $u['is_active'] ? 'Disable' : 'Enable'] + ($u['totp_enabled'] ? ['reset_2fa' => 'Remove 2FA'] : []) as $a => $label): ?>
              <form method="post" action="/users/<?= (int) $u['id'] ?>" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $a ?>"><button class="btn btn-xs btn-default" data-confirm="<?= e($label) ?> for <?= e($u['email']) ?>?<?= $a === 'reset_2fa' ? ' This also gives them a one-time password, which you pass on.' : '' ?>"><?= e($label) ?></button>
              </form>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modal-user" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="/users">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark"><h5 class="modal-title">New user</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label>Name</label><input name="name" class="form-control" required></div>
          <div class="mb-3"><label>Email</label><input type="email" name="email" class="form-control" required></div>
          <div class="mb-3 mb-0"><label>Role</label>
            <select name="role" class="form-select"><?php foreach ($roles as $r => $label): ?><option value="<?= $r ?>" <?= $r === 'tech' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          <p class="small text-muted mt-2 mb-0">A temporary password is generated and shown once.</p>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create user</button></div>
      </form>
    </div>
  </div>
</div>

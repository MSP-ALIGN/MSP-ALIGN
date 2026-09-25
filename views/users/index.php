<header class="page-head"><h1>Users</h1></header>

<?php if ($newPassword): ?>
  <div class="card callout">
    <h2>Temporary password for <?= e($newPassword['email']) ?></h2>
    <p><code class="big"><?= e($newPassword['password']) ?></code></p>
    <p class="muted small">Shown once. Send it securely and have them change it under Account.</p>
  </div>
<?php endif; ?>

<div class="card flush">
  <table class="table">
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>2FA</th><th>Last sign-in</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr class="<?= $u['is_active'] ? '' : 'dim' ?>">
        <td><?= e($u['name']) ?><?= $u['is_active'] ? '' : ' <span class="badge tone-muted">disabled</span>' ?></td>
        <td><?= e($u['email']) ?></td>
        <td>
          <form method="post" action="/users/<?= (int) $u['id'] ?>" class="inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="role">
            <select name="role" data-autosubmit>
              <?php foreach ($roles as $r => $label): ?><option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?>
            </select>
            <noscript><button class="btn small">Set</button></noscript>
          </form>
        </td>
        <td><?= $u['totp_enabled'] ? '<span class="badge tone-ok">on</span>' : '<span class="muted">off</span>' ?></td>
        <td><?= e(rel_time($u['last_login_at'])) ?></td>
        <td class="actions">
          <?php foreach (['reset' => 'Reset password', 'toggle' => $u['is_active'] ? 'Disable' : 'Enable'] + ($u['totp_enabled'] ? ['reset_2fa' => 'Remove 2FA'] : []) as $a => $label): ?>
            <form method="post" action="/users/<?= (int) $u['id'] ?>" class="inline"><?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $a ?>"><button class="btn small" data-confirm="<?= e($label) ?> for <?= e($u['email']) ?>?"><?= e($label) ?></button>
            </form>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>Add user</h2>
  <form method="post" action="/users" class="form-grid">
    <?= csrf_field() ?>
    <label>Name<input name="name" required></label>
    <label>Email<input type="email" name="email" required></label>
    <label>Role
      <select name="role"><?php foreach ($roles as $r => $label): ?><option value="<?= $r ?>" <?= $r === 'tech' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
    </label>
    <div><button class="btn primary">Create user</button></div>
  </form>
  <p class="muted small">A temporary password is generated and shown once.</p>
</div>

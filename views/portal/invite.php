<?php /** @var ?array $invitee */ ?>
<?php if (!$invitee): ?>
  <p class="login-box-msg"><b>This link has expired or was already used.</b></p>
  <p class="small text-muted text-center">Sign-in links work once and expire after <?= \Align\Portal\PortalAuth::INVITE_DAYS ?> days. Ask your IT provider to send you a new one.</p>
  <a href="/portal/login" class="btn btn-light w-100">Go to sign in</a>
<?php else: ?>
  <p class="login-box-msg">Welcome, <b><?= e($invitee['name']) ?></b><br><span class="small text-muted">Set a password for the <?= e($invitee['client_name']) ?> client portal.</span></p>
  <form method="post" action="/portal/invite/<?= e($token) ?>">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="small mb-1">Email</label><input class="form-control" value="<?= e($invitee['email']) ?>" readonly autocomplete="username"></div>
    <div class="mb-3"><label class="small mb-1">New password <span class="text-muted">(at least 12 characters)</span></label><input type="password" name="password" class="form-control" minlength="12" autocomplete="new-password" required autofocus></div>
    <div class="mb-3"><label class="small mb-1">Confirm password</label><input type="password" name="confirm" class="form-control" minlength="12" autocomplete="new-password" required></div>
    <button class="btn btn-primary w-100">Set password and sign in</button>
  </form>
<?php endif; ?>

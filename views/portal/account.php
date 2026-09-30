<?php /** @var array $pu; ?string $setupSecret, $setupUri */ ?>
<h1 class="h4 mb-3"><i class="fas fa-user-gear me-2 text-secondary"></i>Account &amp; security</h1>
<div class="row">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1">Your details</h3></div>
      <div class="card-body small">
        <div class="mb-1"><span class="text-muted">Name</span> <b><?= e($pu['name']) ?></b></div>
        <div class="mb-1"><span class="text-muted">Email</span> <?= e($pu['email']) ?></div>
        <div class="mb-1"><span class="text-muted">Organization</span> <?= e($pu['client_name']) ?></div>
        <div class="mt-2"><span class="text-muted">You can see:</span>
          <?php foreach (\Align\Portal\PortalAuth::SECTIONS as $k => $label): if ($pu[$k]): ?><span class="badge text-bg-light border me-1"><?= e($label) ?></span><?php endif; endforeach; ?></div>
        <?php $acts = array_filter(\Align\Portal\PortalAuth::ACTIONS, fn($k) => (bool) $pu[$k], ARRAY_FILTER_USE_KEY); if ($acts): ?>
          <div class="mt-1"><span class="text-muted">You can:</span> <?= e(implode('; ', array_map('lcfirst', $acts))) ?></div><?php endif; ?>
        <div class="mt-2 text-muted">To change your name, email or access, ask your IT provider.</div>
      </div>
    </div>
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1">Change password</h3></div>
      <form method="post" action="/portal/account/password" class="card-body">
        <?= csrf_field() ?>
        <input type="text" name="username" value="<?= e($pu['email']) ?>" autocomplete="username" hidden>
        <div class="mb-3"><label>Current password</label><input type="password" name="current" class="form-control" autocomplete="current-password" required></div>
        <div class="row g-2">
          <div class="mb-3 col-md-6"><label>New password</label><input type="password" name="new" class="form-control" minlength="12" autocomplete="new-password" required></div>
          <div class="mb-3 col-md-6"><label>Confirm</label><input type="password" name="confirm" class="form-control" minlength="12" autocomplete="new-password" required></div>
        </div>
        <button class="btn btn-primary btn-sm">Change password</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card <?= !$pu['totp_enabled'] ? 'card-outline card-warning' : '' ?>">
      <div class="card-header py-2"><h3 class="card-title mt-1">Two-factor sign-in</h3>
        <div class="card-tools"><span class="badge text-bg-<?= $pu['totp_enabled'] ? 'success' : 'secondary' ?>"><?= $pu['totp_enabled'] ? 'On' : 'Off' ?></span></div></div>
      <div class="card-body">
        <?php if ($setupSecret): ?>
          <ol class="small ps-3">
            <li>Open an authenticator app (Microsoft Authenticator, Google Authenticator, 1Password…).</li>
            <li>Add an account and enter this key: <code class="d-block my-1 user-select-all"><?= e(trim(chunk_split($setupSecret, 4, ' '))) ?></code>
              <span class="text-muted">or open this link on your phone: <a href="<?= e($setupUri) ?>">add to authenticator</a></span></li>
            <li>Enter the 6-digit code it shows.</li>
          </ol>
          <form method="post" action="/portal/account/2fa" class="d-flex flex-wrap align-items-center"><?= csrf_field() ?><input type="hidden" name="action" value="confirm">
            <input name="code" class="form-control form-control-sm me-2 mb-2" inputmode="numeric" autocomplete="one-time-code" placeholder="<?= $pu['totp_enabled'] ? 'New phone\'s code' : '123456' ?>" aria-label="Code from the new authenticator" required autofocus>
            <?php if ($pu['totp_enabled']): ?><input name="current_code" class="form-control form-control-sm me-2 mb-2" inputmode="numeric" autocomplete="off" placeholder="Current phone's code" aria-label="Code from your current authenticator" required><?php endif; ?>
            <button class="btn btn-sm btn-primary mb-2 me-2"><?= $pu['totp_enabled'] ? 'Use this authenticator' : 'Turn on' ?></button></form>
          <form method="post" action="/portal/account/2fa"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-link px-0">Cancel</button></form>
        <?php elseif ($pu['totp_enabled']): ?>
          <p class="small">Each sign-in asks for a code from your authenticator app<?= \Align\Remember::days() ? ', except on a browser you asked it to remember' : '' ?>. Two-factor sign-in is required, so it can't be turned off.</p>
          <form method="post" action="/portal/account/2fa"><?= csrf_field() ?><input type="hidden" name="action" value="begin"><button class="btn btn-sm btn-default">Replace authenticator (new phone)</button></form>
          <?= \Align\View::fetch('partials/remembered', ['kind' => 'portal', 'uid' => (int) $pu['id'], 'action' => '/portal/account/remembered']) ?>
        <?php else: ?>
          <p class="small"><b>Two-factor sign-in is required.</b> It protects your organization's information even if your password is stolen. Set it up to continue.</p>
          <form method="post" action="/portal/account/2fa"><?= csrf_field() ?><input type="hidden" name="action" value="begin"><button class="btn btn-sm btn-primary">Set up two-factor sign-in</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

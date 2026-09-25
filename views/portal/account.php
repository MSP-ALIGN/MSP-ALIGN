<?php /** @var array $pu; ?string $setupSecret, $setupUri */ ?>
<h1 class="h4 mb-3"><i class="fas fa-user-gear mr-2 text-secondary"></i>Account &amp; security</h1>
<div class="row">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1">Your details</h3></div>
      <div class="card-body small">
        <div class="mb-1"><span class="text-muted">Name</span> <b><?= e($pu['name']) ?></b></div>
        <div class="mb-1"><span class="text-muted">Email</span> <?= e($pu['email']) ?></div>
        <div class="mb-1"><span class="text-muted">Organization</span> <?= e($pu['client_name']) ?></div>
        <div class="mt-2"><span class="text-muted">You can see:</span>
          <?php foreach (\Align\Portal\PortalAuth::SECTIONS as $k => $label): if ($pu[$k]): ?><span class="badge badge-light border mr-1"><?= e($label) ?></span><?php endif; endforeach; ?></div>
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
        <div class="form-group"><label>Current password</label><input type="password" name="current" class="form-control" autocomplete="current-password" required></div>
        <div class="form-row">
          <div class="form-group col-md-6"><label>New password</label><input type="password" name="new" class="form-control" minlength="12" autocomplete="new-password" required></div>
          <div class="form-group col-md-6"><label>Confirm</label><input type="password" name="confirm" class="form-control" minlength="12" autocomplete="new-password" required></div>
        </div>
        <button class="btn btn-primary btn-sm">Change password</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card <?= $pu['portal_require_2fa'] && !$pu['totp_enabled'] ? 'card-outline card-warning' : '' ?>">
      <div class="card-header py-2"><h3 class="card-title mt-1">Two-factor sign-in</h3>
        <div class="card-tools"><span class="badge badge-<?= $pu['totp_enabled'] ? 'success' : 'secondary' ?>"><?= $pu['totp_enabled'] ? 'On' : 'Off' ?></span></div></div>
      <div class="card-body">
        <?php if ($pu['totp_enabled']): ?>
          <p class="small">Each sign-in asks for a code from your authenticator app.</p>
          <?php if ($pu['portal_require_2fa']): ?><p class="small text-muted mb-0">Your organization requires two-factor sign-in, so it can't be turned off.</p>
          <?php else: ?>
            <form method="post" action="/portal/account/2fa" class="form-inline"><?= csrf_field() ?><input type="hidden" name="action" value="disable">
              <input type="password" name="password" class="form-control form-control-sm mr-2 mb-2" placeholder="Your password" autocomplete="current-password" required>
              <button class="btn btn-sm btn-outline-danger mb-2">Turn off</button></form>
          <?php endif; ?>
        <?php elseif ($setupSecret): ?>
          <ol class="small pl-3">
            <li>Open an authenticator app (Microsoft Authenticator, Google Authenticator, 1Password…).</li>
            <li>Add an account and enter this key: <code class="d-block my-1 user-select-all"><?= e(trim(chunk_split($setupSecret, 4, ' '))) ?></code>
              <span class="text-muted">or open this link on your phone: <a href="<?= e($setupUri) ?>">add to authenticator</a></span></li>
            <li>Enter the 6-digit code it shows.</li>
          </ol>
          <form method="post" action="/portal/account/2fa" class="form-inline"><?= csrf_field() ?><input type="hidden" name="action" value="confirm">
            <input name="code" class="form-control form-control-sm mr-2 mb-2" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required autofocus>
            <button class="btn btn-sm btn-primary mb-2 mr-2">Turn on</button></form>
          <form method="post" action="/portal/account/2fa"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-link px-0">Cancel</button></form>
        <?php else: ?>
          <p class="small"><?= $pu['portal_require_2fa'] ? '<b>Your organization requires two-factor sign-in.</b> Set it up to continue using the portal.' : 'Add a second step to sign-in with a code from an authenticator app on your phone.' ?></p>
          <form method="post" action="/portal/account/2fa"><?= csrf_field() ?><input type="hidden" name="action" value="begin"><button class="btn btn-sm btn-primary">Set up two-factor sign-in</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

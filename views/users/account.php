<header class="page-head"><h1>Your account</h1><span class="muted"><?= e($u['email']) ?> · <?= e($u['role']) ?></span></header>

<div class="grid2">
  <div class="card">
    <h2>Change password</h2>
    <form method="post" action="/account/password" class="stack">
      <?= csrf_field() ?>
      <label>Current password<input type="password" name="current" autocomplete="current-password" required></label>
      <label>New password <span class="hint">at least 12 characters</span><input type="password" name="new" autocomplete="new-password" minlength="12" required></label>
      <label>Confirm new password<input type="password" name="confirm" autocomplete="new-password" minlength="12" required></label>
      <div><button class="btn primary">Change password</button></div>
    </form>
  </div>

  <div class="card">
    <h2>Two-factor sign-in</h2>
    <?php if ($u['totp_enabled']): ?>
      <p><span class="badge tone-ok">On</span> You'll be asked for a code from your authenticator app at each sign-in.</p>
      <form method="post" action="/account/2fa" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="disable">
        <label>Confirm your password to turn it off<input type="password" name="password" required></label>
        <div><button class="btn">Turn off two-factor</button></div>
      </form>
    <?php elseif ($setupSecret): ?>
      <ol class="small">
        <li>In your authenticator app (Microsoft Authenticator, Google Authenticator, 1Password, etc.) add an account and choose <b>enter a setup key</b>.</li>
        <li>Account name: <b><?= e($u['email']) ?></b>, key type: time-based.</li>
        <li>Key: <code class="big"><?= e(implode(' ', str_split($setupSecret, 4))) ?></code></li>
      </ol>
      <p class="small">On this device you can also <a href="<?= e($setupUri) ?>">open the setup link</a>.</p>
      <form method="post" action="/account/2fa" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
        <label>Enter the 6-digit code it shows<input name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
        <div class="row"><button class="btn primary">Turn on</button>
          <button class="btn" name="action" value="cancel" formnovalidate>Cancel</button></div>
      </form>
    <?php else: ?>
      <p class="muted">Off. Recommended for every account, since Align holds API keys for your RMM and PSA.</p>
      <form method="post" action="/account/2fa"><?= csrf_field() ?><input type="hidden" name="action" value="begin"><button class="btn primary">Set up two-factor</button></form>
    <?php endif; ?>
  </div>
</div>

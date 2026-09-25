<h1 class="h3 mb-3">Your account <small class="text-muted h6"><?= e($u['email']) ?> · <?= e($u['role']) ?></small></h1>
<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-id-badge mr-2"></i>Profile picture</h3></div>
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center">
      <div class="mr-4 mb-2" data-logo-preview><?= user_avatar($u, 'avatar-lg') ?></div>
      <div class="flex-grow-1 mb-2">
        <form method="post" action="/account/avatar" enctype="multipart/form-data" class="form-inline">
          <?= csrf_field() ?>
          <div class="custom-file mr-2 mb-1 avatar-file">
            <input type="file" class="custom-file-input" id="avatar" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif" required data-logo-input>
            <label class="custom-file-label" for="avatar">Choose a photo…</label>
          </div>
          <button class="btn btn-primary mb-1"><i class="fas fa-upload mr-1"></i>Upload</button>
        </form>
        <?php if (avatar_url($u)): ?>
          <form method="post" action="/account/avatar" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><button class="btn btn-link btn-sm text-danger px-0">Remove picture</button></form>
        <?php endif; ?>
        <p class="small text-muted mb-0">PNG, JPG, WebP or GIF up to 5 MB. It's cropped to a square and shown in the top bar, the user list, next to your name as vCIO, and when you have a document open.</p>
      </div>
    </div>
  </div>
</div>
<div class="row">
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-key mr-2"></i>Change password</h3></div>
      <div class="card-body">
        <form method="post" action="/account/password">
          <?= csrf_field() ?>
          <div class="form-group"><label>Current password</label><input type="password" name="current" class="form-control" autocomplete="current-password" required></div>
          <div class="form-group"><label>New password <small class="text-muted">(12+ characters)</small></label><input type="password" name="new" class="form-control" autocomplete="new-password" minlength="12" required></div>
          <div class="form-group"><label>Confirm new password</label><input type="password" name="confirm" class="form-control" autocomplete="new-password" minlength="12" required></div>
          <button class="btn btn-primary">Change password</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-shield-halved mr-2"></i>Two-factor sign-in</h3></div>
      <div class="card-body">
        <?php if ($u['totp_enabled']): ?>
          <p><span class="badge badge-success">On</span> You'll be asked for a code at each sign-in.</p>
          <form method="post" action="/account/2fa">
            <?= csrf_field() ?><input type="hidden" name="action" value="disable">
            <div class="form-group"><label>Confirm your password to turn it off</label><input type="password" name="password" class="form-control" required></div>
            <button class="btn btn-outline-danger">Turn off two-factor</button>
          </form>
        <?php elseif ($setupSecret): ?>
          <ol class="small pl-3">
            <li>In your authenticator app (Microsoft Authenticator, Google Authenticator, 1Password…) add an account and choose <b>enter a setup key</b>.</li>
            <li>Account: <b><?= e($u['email']) ?></b>, time-based.</li>
            <li>Key: <code class="select-all"><?= e(implode(' ', str_split($setupSecret, 4))) ?></code></li>
          </ol>
          <p class="small">On a phone you can <a href="<?= e($setupUri) ?>">open the setup link</a> instead.</p>
          <form method="post" action="/account/2fa">
            <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
            <div class="form-group"><label>Enter the 6-digit code it shows</label><input name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" required></div>
            <button class="btn btn-primary">Turn on</button>
            <button class="btn btn-light" name="action" value="cancel" formnovalidate>Cancel</button>
          </form>
        <?php else: ?>
          <p class="text-muted">Off. Recommended for every account, since Align holds API keys for your RMM and PSA.</p>
          <form method="post" action="/account/2fa"><?= csrf_field() ?><input type="hidden" name="action" value="begin"><button class="btn btn-primary">Set up two-factor</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-rss mr-2"></i>Calendar feed</h3></div>
      <div class="card-body small">
        <?php if ($feedUrl): ?>
          <p>Subscribe to this in Outlook (<b>Add calendar → Subscribe from web</b>) to see Align meetings alongside your own.</p>
          <input class="form-control form-control-sm mb-2 select-all" readonly value="<?= e($feedUrl) ?>">
          <form method="post" action="/calendar/feed" class="d-inline"><?= csrf_field() ?><button class="btn btn-xs btn-outline-secondary" data-confirm="Make a new link? The old one stops working.">New link</button></form>
          <form method="post" action="/calendar/feed" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><button class="btn btn-xs btn-outline-danger">Turn off</button></form>
        <?php else: ?>
          <p class="text-muted">Create a private link to show Align meetings in Outlook, Google or Apple Calendar.</p>
          <form method="post" action="/calendar/feed"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Create feed link</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

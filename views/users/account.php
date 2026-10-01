<h1 class="h3 mb-3">Your account <small class="text-muted h6"><?= e($u['email']) ?> · <?= e($u['role']) ?></small></h1>
<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-id-badge me-2"></i>Profile picture</h3></div>
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center">
      <div class="me-4 mb-2" data-logo-preview><?= user_avatar($u, 'avatar-lg') ?></div>
      <div class="flex-grow-1 mb-2">
        <form method="post" action="/account/avatar" enctype="multipart/form-data" class="d-flex flex-wrap align-items-center">
          <?= csrf_field() ?>
          <div class=" me-2 mb-1 avatar-file">
            <input type="file" class="form-control" id="avatar" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif" required data-logo-input>
            
          </div>
          <button class="btn btn-primary mb-1"><i class="fas fa-upload me-1"></i>Upload</button>
        </form>
        <?php if (avatar_url($u)): ?>
          <form method="post" action="/account/avatar" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><button class="btn btn-link btn-sm text-danger px-0">Remove picture</button></form>
        <?php endif; ?>
        <p class="small text-muted mb-0">PNG, JPG, WebP or GIF up to 5 MB. It's cropped to a square and shown in the top bar, the user list, next to your name as vCIO, and when you have a document open.</p>
      </div>
    </div>
  </div>
</div>
<?php $themePref = in_array($u['theme'] ?? 'auto', ['light', 'dark'], true) ? $u['theme'] : 'auto'; ?>
<form method="post" action="/account/appearance" class="card card-dark" id="appearance">
  <?= csrf_field() ?>
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-half-stroke me-2"></i>Light or dark</h3></div>
  <div class="card-body">
    <div class="theme-picker d-flex flex-wrap gap-3">
      <?php foreach (['light' => ['Light', 'fa-sun'], 'dark' => ['Dark', 'fa-moon'], 'auto' => ['Match my computer', 'fa-desktop']] as $k => [$label, $icon]): ?>
        <button class="theme-option<?= $themePref === $k ? ' is-active' : '' ?>" name="theme" value="<?= $k ?>"<?= $themePref === $k ? ' aria-pressed="true"' : '' ?>>
          <span class="theme-swatch theme-swatch-<?= $k ?>" aria-hidden="true"><span></span><span></span></span>
          <span><i class="fas fa-fw <?= $icon ?> me-1"></i><?= $label ?><?= $themePref === $k ? ' <i class="fas fa-check text-success ms-1"></i>' : '' ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <p class="small text-muted mb-0 mt-2">Just for you, on every computer you sign in from. Printed reports and PDFs always use the light colors.</p>
  </div>
</form>
<div class="row">
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-key me-2"></i>Change password</h3></div>
      <div class="card-body">
        <form method="post" action="/account/password">
          <?= csrf_field() ?>
          <div class="mb-3"><label>Current password</label><input type="password" name="current" class="form-control" autocomplete="current-password" required></div>
          <?php if ($u['must_change_password']): ?><div class="alert alert-warning small py-2">Your password was set by an administrator. Choose your own to continue.</div><?php endif; ?>
          <div class="mb-3"><label>New password <small class="text-muted">(12+ characters, not a common password)</small></label><input type="password" name="new" class="form-control" autocomplete="new-password" minlength="12" required></div>
          <div class="mb-3"><label>Confirm new password</label><input type="password" name="confirm" class="form-control" autocomplete="new-password" minlength="12" required></div>
          <button class="btn btn-primary">Change password</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-shield-halved me-2"></i>Two-factor sign-in</h3></div>
      <div class="card-body">
        <?php if ($u['totp_enabled']): ?>
          <p><span class="badge text-bg-success">On</span> You'll be asked for a code at each sign-in<?= \Align\Remember::days() ? ', except on a browser you asked it to remember' : '' ?>. It's required for all staff accounts.</p>
          <form method="post" action="/account/2fa" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="begin"><button class="btn btn-sm btn-default">Replace authenticator (new phone)</button></form>
          <form method="post" action="/account/2fa" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="signout_all"><button class="btn btn-sm btn-default" data-confirm="Sign out of every other browser and device? Remembered browsers are forgotten too.">Sign out everywhere else</button></form>
          <?= \Align\View::fetch('partials/remembered', ['kind' => 'staff', 'uid' => (int) $u['id'], 'action' => '/account/remembered']) ?>
        <?php endif; ?>
        <?php if ($setupSecret): ?>
          <ol class="small ps-3">
            <li>In your authenticator app (Microsoft Authenticator, Google Authenticator, 1Password…) add an account and choose <b>enter a setup key</b>.</li>
            <li>Account: <b><?= e($u['email']) ?></b>, time-based.</li>
            <li>Key: <code class="select-all"><?= e(implode(' ', str_split($setupSecret, 4))) ?></code></li>
          </ol>
          <p class="small">On a phone you can <a href="<?= e($setupUri) ?>">open the setup link</a> instead.</p>
          <form method="post" action="/account/2fa">
            <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
            <div class="mb-3"><label>Enter the 6-digit code it shows</label><input name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" required></div>
            <?php if ($u['totp_enabled']): ?>
              <div class="mb-3"><label>And a code from your <b>current</b> authenticator</label><input name="current_code" class="form-control" inputmode="numeric" autocomplete="off" required></div>
            <?php endif; ?>
            <button class="btn btn-primary"><?= $u['totp_enabled'] ? 'Replace' : 'Turn on' ?></button>
            <button class="btn btn-light" name="action" value="cancel" formnovalidate>Cancel</button>
          </form>
        <?php elseif (!$u['totp_enabled']): ?>
          <p class="text-danger"><b>Required.</b> Every staff account needs two-factor sign-in before it can open client data.</p>
          <form method="post" action="/account/2fa"><?= csrf_field() ?><input type="hidden" name="action" value="begin"><button class="btn btn-primary">Set up two-factor</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-rss me-2"></i>Calendar feed</h3></div>
      <div class="card-body small">
        <?php if ($feedUrl): ?>
          <p>Subscribe to this in Outlook (<b>Add calendar → Subscribe from web</b>) to see Align meetings alongside your own. <b>Copy it now:</b> it's shown only once.</p>
          <input class="form-control form-control-sm mb-2 select-all" readonly value="<?= e($feedUrl) ?>">
          <p class="text-muted">Only titles, times and client names are included, never agendas or attendees.</p>
          <form method="post" action="/calendar/feed" class="d-inline"><?= csrf_field() ?><button class="btn btn-xs btn-outline-secondary" data-confirm="Make a new link? The old one stops working.">New link</button></form>
          <form method="post" action="/calendar/feed" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><button class="btn btn-xs btn-outline-danger" data-confirm="Turn off your calendar feed? Calendars subscribed to it stop updating." data-confirm-ok="Turn off">Turn off</button></form>
        <?php elseif ($feedOn): ?>
          <p><span class="badge text-bg-success">On</span> Your feed link is active. For security it isn't shown again; make a new link if you need it (the old one stops working).</p>
          <form method="post" action="/calendar/feed" class="d-inline"><?= csrf_field() ?><button class="btn btn-xs btn-outline-secondary" data-confirm="Make a new link? The old one stops working.">New link</button></form>
          <form method="post" action="/calendar/feed" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><button class="btn btn-xs btn-outline-danger" data-confirm="Turn off your calendar feed? Calendars subscribed to it stop updating." data-confirm-ok="Turn off">Turn off</button></form>
        <?php else: ?>
          <p class="text-muted">Create a private link to show Align meetings in Outlook, Google or Apple Calendar.</p>
          <form method="post" action="/calendar/feed"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Create feed link</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<form method="post" action="/account/notifications" class="card card-dark" id="notifications">
  <?= csrf_field() ?>
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-bell me-2"></i>Email notifications</h3></div>
  <?php if (!$mailOn): ?>
    <div class="card-body small text-muted">Email isn't set up yet<?= \Align\Auth::can('admin') ? ' (<a href="/integrations/email">Integrations → Email</a>)' : '' ?>. Your choices here apply once it is.</div>
  <?php endif; ?>
  <div class="card-body">
    <div class="mb-3">
      <label class="d-block">Which clients</label>
      <div class="form-check form-check-inline"><input type="radio" class="form-check-input" id="sc-mine" name="scope" value="mine" <?= $notifScope === 'mine' ? 'checked' : '' ?>><label class="form-check-label fw-normal" for="sc-mine">Only clients I'm vCIO for (<?= (int) $vcioCount ?>)</label></div>
      <div class="form-check form-check-inline"><input type="radio" class="form-check-input" id="sc-all" name="scope" value="all" <?= $notifScope === 'all' ? 'checked' : '' ?>><label class="form-check-label fw-normal" for="sc-all">All clients</label></div>
    </div>
    <div class="row">
      <?php foreach ($notifPrefs as $k => $p): [$label, $group, , $timing, $desc] = \Align\Mail\Notifications::CATALOG[$k]; $globalOn = \Align\Settings::get("notif_$k", \Align\Mail\Notifications::CATALOG[$k][7] ? '1' : '0') === '1'; ?>
        <div class="col-lg-6 mb-2">
          <div class="form-check form-switch">
            <input type="checkbox" class="form-check-input" id="np-<?= $k ?>" name="notif[<?= $k ?>]" value="1" <?= $p['on'] ? 'checked' : '' ?> <?= $globalOn ? '' : 'disabled' ?>>
            <label class="form-check-label" for="np-<?= $k ?>"><?= e($label) ?> <span class="badge text-bg-light border fw-normal"><?= e(\Align\Mail\Notifications::TIMING[$timing]) ?></span><?= $globalOn ? '' : ' <span class="badge text-bg-secondary fw-normal">off for everyone</span>' ?></label>
            <div class="small text-muted"><?= e($desc) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="small text-muted mb-0">Emails go to <?= e($u['email']) ?>. When a notification is sent to each client's vCIO, you'll get it for your clients unless you switch it off here.</p>
  </div>
  <div class="card-footer"><button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button></div>
</form>

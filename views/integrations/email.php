<?php
use Align\Mail\Invites;
use Align\Mail\Mail;
use Align\Mail\Notifications as N;

/** @var array $v, $secrets, $stats; bool $ready, $baseUrlSet; string $redirectUri, $provider; ?array $certInfo, $sa */
$mode = $v['mail_mode'] ?: 'off';
if ($provider === 'smtp' && $mode === 'delegated') {
    $mode = 'app'; // SMTP is just on or off
}
$smtpSec = \Align\Mail\Smtp::security();
$secret = function (string $name, string $label, bool $textarea = false, string $placeholder = '') use ($secrets) {
    $has = $secrets[$name] ?? false;
    $ph = $has ? '•••••••• saved — leave blank to keep' : ($placeholder ?: 'Not set');
    $h = '<div class="mb-3"><label>' . e($label) . '</label>'
        . ($textarea ? '<textarea name="' . e($name) . '" class="form-control font-monospace small" rows="3" autocomplete="off" spellcheck="false" placeholder="' . e($ph) . '"></textarea>'
            : '<input type="password" name="' . e($name) . '" class="form-control" autocomplete="new-password" placeholder="' . e($ph) . '">');
    if ($has) {
        $h .= '<div class="form-check mt-1"><input type="checkbox" class="form-check-input" id="clear_' . e($name) . '" name="clear_' . e($name) . '" value="1">'
            . '<label class="form-check-label small fw-normal" for="clear_' . e($name) . '">Remove saved value</label></div>';
    }
    return $h . '</div>';
};
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$pname = Mail::PROVIDERS[$provider];
$status = match (true) {
    $mode === 'off' => ['secondary', 'Off', 'Email is switched off. Nothing is sent or queued.'],
    !$ready && $provider === 'smtp' => ['warning', 'Not finished', trim((string) $v['smtp_user']) !== '' && !($secrets['smtp_pass'] ?? false) ? 'Enter the password (or API key) for ' . $v['smtp_user'] . ', then save.' : 'Fill in the SMTP server and the From address, then save.'],
    !$ready => ['warning', 'Not finished', $mode === 'delegated' ? 'Save the app details, then click Connect with ' . ($provider === 'google' ? 'Google' : 'Microsoft') . '.'
        : ($provider === 'google' ? 'Paste the service account key and the From mailbox, then save.' : 'Fill in the tenant, client ID, credential and From mailbox, then save.')],
    $stats['failed7'] && $stats['last_error'] => ['danger', 'Problem', 'Recent sends failed: ' . $stats['last_error']],
    $provider === 'smtp' => ['success', 'Ready', "$pname " . \Align\Mail\Smtp::host() . ':' . \Align\Mail\Smtp::port() . ' · sending as ' . $v['mail_from'] . '.'],
    default => ['success', 'Ready', "$pname · " . ($mode === 'delegated' ? 'connected as ' . Mail::connectedAs() : 'sending as ' . $v['mail_from']) . '.'],
};
?>
<div class="small mb-1"><a href="/integrations">Integrations</a> /</div>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h3 mb-0 me-auto"><i class="fas fa-envelope text-secondary me-2"></i>Email</h1>
  <a class="btn btn-sm btn-default me-2" href="/settings/notifications"><i class="fas fa-bell me-1"></i>Notifications</a>
  <a class="btn btn-sm btn-default" href="/settings/notifications/log"><i class="fas fa-list me-1"></i>Email log<?= $stats['queued'] ? ' <span class="badge text-bg-warning">' . (int) $stats['queued'] . ' queued</span>' : '' ?></a>
</div>
<?php if (!$baseUrlSet): ?>
  <div class="alert alert-warning py-2 small"><i class="fas fa-triangle-exclamation me-1"></i><code>base_url</code> is not set in <code>/etc/msp-align/config.php</code>. Links in emails and the sign-in redirect are built from it, so set it to the address people use (for example https://align.example.com).</div>
<?php endif; ?>

<div class="row">
  <div class="col-xl-7">
    <form method="post" action="/integrations/email" class="card card-dark" data-unsaved data-confirm-rules="<?= e(json_encode([['lowered' => 'mail_log_days', 'title' => 'Keep email content for fewer days?', 'ok' => 'Save', 'danger' => true,
      'text' => 'The content of logged emails older than that is deleted at the next clean-up, and can\'t be brought back.']])) ?>">
      <?= csrf_field() ?>
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-plug me-2"></i>Mail connection</h3>
        <div class="card-tools"><span class="badge text-bg-<?= $status[0] ?> px-2 py-1"><?= e($status[1]) ?></span></div></div>
      <div class="card-body">
        <p class="small text-<?= $status[0] === 'danger' ? 'danger' : 'muted' ?>"><?= e($status[2]) ?></p>
        <div class="mb-3">
          <label class="d-block">Email service</label>
          <div class="btn-group w-100" data-radio-buttons role="radiogroup">
            <label class="btn btn-outline-primary <?= $provider === 'microsoft' ? 'active' : '' ?>"><input type="radio" name="mail_provider" value="microsoft" <?= $provider === 'microsoft' ? 'checked' : '' ?>><i class="fab fa-microsoft me-1"></i>Microsoft 365</label>
            <label class="btn btn-outline-primary <?= $provider === 'google' ? 'active' : '' ?>"><input type="radio" name="mail_provider" value="google" <?= $provider === 'google' ? 'checked' : '' ?>><i class="fab fa-google me-1"></i>Google Workspace</label>
            <label class="btn btn-outline-primary <?= $provider === 'smtp' ? 'active' : '' ?>"><input type="radio" name="mail_provider" value="smtp" <?= $provider === 'smtp' ? 'checked' : '' ?>><i class="fas fa-server me-1"></i>SMTP server</label>
          </div>
          <small class="text-muted" data-show-when="mail_provider=smtp">Your own mail server or a relay (SMTP2GO, Mailgun, SendGrid, Amazon SES, or the Microsoft 365 / Google relay). Meeting invitations go out as emails with an .ics invitation, since there's no calendar to put them in.</small>
        </div>
        <div class="mb-3">
          <label class="d-block"><span data-show-when="mail_provider=microsoft,google">How Align signs in</span><span data-show-when="mail_provider=smtp">Sending</span></label>
          <div class="form-check "><input type="radio" class="form-check-input" id="mode-off" name="mail_mode" value="off" <?= $mode === 'off' ? 'checked' : '' ?>><label class="form-check-label fw-normal" for="mode-off">Off</label></div>
          <div class="form-check "><input type="radio" class="form-check-input" id="mode-app" name="mail_mode" value="app" <?= $mode === 'app' ? 'checked' : '' ?>>
            <label class="form-check-label fw-normal" for="mode-app"><span data-show-when="mail_provider=microsoft">App-only (recommended for servers)</span><span data-show-when="mail_provider=google">Service account with domain-wide delegation (recommended for servers)</span><span data-show-when="mail_provider=smtp">On: send through the SMTP server below</span></label></div>
          <div class="form-check " data-show-when="mail_provider=microsoft,google"><input type="radio" class="form-check-input" id="mode-delegated" name="mail_mode" value="delegated" <?= $mode === 'delegated' ? 'checked' : '' ?>>
            <label class="form-check-label fw-normal" for="mode-delegated"><span data-show-when="mail_provider=microsoft">Sign in as a mailbox (Connect with Microsoft)</span><span data-show-when="mail_provider=google">Sign in as a mailbox (Connect with Google)</span></label></div>
        </div>

        <div data-show-when="mail_mode=app,delegated">
          <details class="mb-3 small border rounded p-2 bg-light" data-show-when="mail_provider=microsoft">
            <summary class="fw-bold">Setup steps in Microsoft Entra ID</summary>
            <ol class="ps-3 mt-2 mb-1">
              <li>Entra admin center → <b>App registrations</b> → <b>New registration</b>. Name it "MSP-ALIGN", single tenant.</li>
              <li>Copy the <b>Application (client) ID</b> and <b>Directory (tenant) ID</b> into the fields below.</li>
              <li><b>Certificates &amp; secrets</b>: create a client secret (copy its <b>Value</b>), or upload a certificate (.cer) and paste the certificate and its private key below.</li>
              <li data-show-when="mail_mode=app"><b>API permissions</b> → Microsoft Graph → <b>Application permissions</b>: <code>Mail.Send</code>, and <code>Calendars.ReadWrite</code> for Outlook meeting invitations. Click <b>Grant admin consent</b>.
                <br>To limit the app to the sending mailbox (recommended), use Exchange <b>RBAC for Applications</b> instead of tenant-wide consent:
                <pre class="bg-white border p-2 mt-1 mb-1 small">Connect-ExchangeOnline
New-ServicePrincipal -AppId &lt;client ID&gt; -ObjectId &lt;enterprise app object ID&gt; -DisplayName "MSP-ALIGN"
New-ManagementScope -Name "Align mailboxes" -RecipientRestrictionFilter "CustomAttribute10 -eq 'align'"
Set-Mailbox <?= e($v['mail_from'] ?: 'alerts@yourdomain.com') ?> -CustomAttribute10 align
New-ManagementRoleAssignment -App &lt;client ID&gt; -Role "Application Mail.Send" -CustomResourceScope "Align mailboxes"
New-ManagementRoleAssignment -App &lt;client ID&gt; -Role "Application Calendars.ReadWrite" -CustomResourceScope "Align mailboxes"
Test-ServicePrincipalAuthorization -Identity &lt;client ID&gt; -Resource <?= e($v['mail_from'] ?: 'alerts@yourdomain.com') ?></pre>
                Set the attribute on each staff mailbox too if meetings should be organized from the owner's own calendar. Don't also grant the Graph permissions tenant-wide, or the scope has no effect.</li>
              <li data-show-when="mail_mode=delegated"><b>Authentication</b> → Add a platform → <b>Web</b> → redirect URI <code><?= e($redirectUri) ?></code>.
                <b>API permissions</b> → Microsoft Graph → <b>Delegated</b>: <code>Mail.Send</code>, <code>Mail.Send.Shared</code>, <code>Calendars.ReadWrite</code>, <code>Calendars.ReadWrite.Shared</code>, <code>User.Read</code>, <code>offline_access</code>. Grant admin consent, save here, then click <b>Connect with Microsoft</b> and sign in as the mailbox that should send (or an account with Send As on it).</li>
            </ol>
          </details>
          <details class="mb-3 small border rounded p-2 bg-light" data-show-when="mail_provider=google">
            <summary class="fw-bold">Setup steps in Google Cloud and the Admin console</summary>
            <ol class="ps-3 mt-2 mb-1">
              <li>In <b>Google Cloud console</b> create (or pick) a project, then under <b>APIs &amp; Services → Library</b> enable the <b>Gmail API</b> and the <b>Google Calendar API</b>.</li>
              <li data-show-when="mail_mode=app"><b>IAM &amp; Admin → Service accounts → Create service account</b> (no roles needed). Open it → <b>Keys → Add key → JSON</b>, and paste the downloaded file below. (If key creation is blocked, an organization policy <code>iam.disableServiceAccountKeyCreation</code> is on.)</li>
              <li data-show-when="mail_mode=app">In the <b>Google Admin console → Security → Access and data control → API controls → Manage domain-wide delegation → Add new</b>, enter the service account's client ID<?= $sa ? ' <code>' . e($sa['client_id'] ?? '') . '</code>' : '' ?> and these scopes:
                <pre class="bg-white border p-2 mt-1 mb-1 small"><?= e(implode(',', \Align\Mail\Google::SCOPES)) ?></pre>
                The service account then acts as the From mailbox, and as each meeting owner for invitations if you choose that below. The From address must be a real user (not a group or alias).</li>
              <li data-show-when="mail_mode=delegated"><b>APIs &amp; Services → OAuth consent screen</b>: user type <b>Internal</b> (so tokens don't expire after 7 days), add the scopes <code>gmail.send</code> and <code>calendar.events</code>.</li>
              <li data-show-when="mail_mode=delegated"><b>Credentials → Create credentials → OAuth client ID → Web application</b>, authorized redirect URI <code><?= e($redirectUri) ?></code>. Paste the client ID and secret below, save, then click <b>Connect with Google</b> and sign in as the sending mailbox.</li>
            </ol>
          </details>
          <div data-show-when="mail_provider=smtp">
            <div class="row g-2">
              <div class="mb-3 col-md-8"><label>SMTP server</label><input name="smtp_host" class="form-control" value="<?= e($v['smtp_host']) ?>" placeholder="smtp.example.com" autocomplete="off" spellcheck="false"></div>
              <div class="mb-3 col-md-4"><label>Port</label><input type="number" name="smtp_port" class="form-control" min="1" max="65535" value="<?= e($v['smtp_port']) ?>" placeholder="<?= (int) \Align\Mail\Smtp::port() ?>"></div>
            </div>
            <div class="mb-3"><label>Security</label><select name="smtp_security" class="form-select">
              <?php foreach (\Align\Mail\Smtp::SECURITY as $k => $l): ?><option value="<?= e($k) ?>" <?= $smtpSec === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
              <small class="text-muted">A user name and password are only sent over an encrypted connection (or to this server itself).</small></div>
            <div class="row g-2">
              <div class="mb-3 col-md-6"><label>User name <small class="text-muted">(empty for a relay that trusts this server)</small></label><input name="smtp_user" class="form-control" value="<?= e($v['smtp_user']) ?>" autocomplete="off" spellcheck="false"></div>
              <div class="col-md-6"><?= $secret('smtp_pass', 'Password or API key') ?></div>
            </div>
            <input type="hidden" name="smtp_verify_present" value="1">
            <div class="form-check form-switch mb-3"><input type="checkbox" class="form-check-input" id="smtp_verify" name="smtp_verify" value="1" <?= ($v['smtp_verify'] ?? '1') !== '0' ? 'checked' : '' ?>><label class="form-check-label fw-normal" for="smtp_verify">Check the server's certificate <small class="text-muted">(switch off only for an internal relay with its own certificate: without the check, someone who can intercept the connection could read the password)</small></label></div>
          </div>
          <div data-show-when="mail_provider=google;mail_mode=app">
            <?php if ($sa): ?><p class="small text-muted mb-2"><i class="fas fa-key me-1"></i>Service account <b><?= e($sa['client_email']) ?></b> · client ID <code><?= e($sa['client_id'] ?? '') ?></code> · project <?= e($sa['project_id'] ?? '') ?></p><?php endif; ?>
            <?= $secret('g_sa_json', 'Service account key (JSON)', true, '{ "type": "service_account", "project_id": … }') ?>
          </div>
          <div data-show-when="mail_provider=google;mail_mode=delegated">
            <div class="row g-2">
              <div class="mb-3 col-md-6"><label>OAuth client ID</label><input name="g_client_id" class="form-control font-monospace small" value="<?= e($v['g_client_id']) ?>" placeholder="1234567890-abc.apps.googleusercontent.com" autocomplete="off"></div>
              <div class="col-md-6"><?= $secret('g_client_secret', 'OAuth client secret') ?></div>
            </div>
            <div class="border rounded p-2 mb-3">
              <?php if ($secrets['g_refresh_token']): ?>
                <div class="d-flex align-items-center"><i class="fas fa-circle-check text-success me-2"></i>
                  <div class="me-auto small">Connected as <b><?= e($v['g_connected_as']) ?></b><?= $v['g_connected_name'] ? ' (' . e($v['g_connected_name']) . ')' : '' ?> since <?= e(fmt_date($v['g_connected_at'])) ?>.<?= $v['g_calendar_granted'] === '0' ? ' <span class="text-warning">Calendar access not granted: invitations go out as .ics emails.</span>' : '' ?></div>
                  <a class="btn btn-sm btn-default me-1" href="/integrations/email/connect">Reconnect</a>
                  <button class="btn btn-sm btn-outline-danger" form="email-disconnect" data-confirm="Disconnect the mailbox? Align stops sending email (invitations, notifications and client portal emails) until it's connected again.">Disconnect</button></div>
              <?php else: ?>
                <div class="d-flex align-items-center"><span class="small text-muted me-auto">Save the client ID and secret first, then sign in as the sending mailbox.</span>
                  <a class="btn btn-sm btn-primary" href="/integrations/email/connect"><i class="fab fa-google me-1"></i>Connect with Google</a></div>
              <?php endif; ?>
              <div class="small text-muted mt-1">Redirect URI to register: <code><?= e($redirectUri) ?></code></div>
            </div>
          </div>
          <div data-show-when="mail_provider=microsoft">
          <div class="row g-2">
            <div class="mb-3 col-md-6"><label>Directory (tenant) ID</label><input name="m365_tenant" class="form-control" value="<?= e($v['m365_tenant']) ?>" placeholder="contoso.onmicrosoft.com or GUID" autocomplete="off"></div>
            <div class="mb-3 col-md-6"><label>Application (client) ID</label><input name="m365_client_id" class="form-control font-monospace" value="<?= e($v['m365_client_id']) ?>" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off"></div>
          </div>
          <div class="mb-3 mb-2">
            <label class="d-block">Credential</label>
            <div class="form-check form-check-inline"><input type="radio" class="form-check-input" id="auth-secret" name="m365_auth" value="secret" <?= ($v['m365_auth'] ?: 'secret') === 'secret' ? 'checked' : '' ?>><label class="form-check-label fw-normal" for="auth-secret">Client secret</label></div>
            <div class="form-check form-check-inline"><input type="radio" class="form-check-input" id="auth-cert" name="m365_auth" value="certificate" <?= $v['m365_auth'] === 'certificate' ? 'checked' : '' ?>><label class="form-check-label fw-normal" for="auth-cert">Certificate (more secure)</label></div>
          </div>
          <div data-show-when="m365_auth=secret"><?= $secret('m365_client_secret', 'Client secret value') ?></div>
          <div data-show-when="m365_auth=certificate">
            <?php if ($certInfo): ?><p class="small text-muted mb-2"><i class="fas fa-certificate me-1"></i>Certificate <?= e($certInfo['subject']) ?> · thumbprint <code><?= e($certInfo['thumbprint']) ?></code> · expires <b class="<?= $certInfo['expires'] < date('Y-m-d', strtotime('+30 days')) ? 'text-danger' : '' ?>"><?= e(fmt_date($certInfo['expires'])) ?></b></p><?php endif; ?>
            <div class="row g-2">
              <div class="col-md-6"><?= $secret('m365_cert_pem', 'Certificate (PEM)', true, '-----BEGIN CERTIFICATE-----') ?></div>
              <div class="col-md-6"><?= $secret('m365_key_pem', 'Private key (PEM, unencrypted)', true, '-----BEGIN PRIVATE KEY-----') ?></div>
            </div>
            <p class="small text-muted">Create one with <code>openssl req -x509 -newkey rsa:2048 -nodes -days 730 -subj "/CN=MSP-ALIGN" -keyout align.key -out align.crt</code>, upload <code>align.crt</code> to the app registration, and paste both files here. In delegated mode a client secret is still needed for the sign-in button.</p>
          </div>

          </div>
          <div data-show-when="mail_provider=microsoft;mail_mode=delegated" class="border rounded p-2 mb-3">
            <?php if ($secrets['m365_refresh_token']): ?>
              <div class="d-flex align-items-center"><i class="fas fa-circle-check text-success me-2"></i>
                <div class="me-auto small">Connected as <b><?= e($v['m365_connected_as']) ?></b><?= $v['m365_connected_name'] ? ' (' . e($v['m365_connected_name']) . ')' : '' ?> since <?= e(fmt_date($v['m365_connected_at'])) ?>.</div>
                <a class="btn btn-sm btn-default me-1" href="/integrations/email/connect">Reconnect</a>
                <button class="btn btn-sm btn-outline-danger" form="email-disconnect" data-confirm="Disconnect the mailbox? Align stops sending email (invitations, notifications and client portal emails) until it's connected again.">Disconnect</button></div>
            <?php else: ?>
              <div class="d-flex align-items-center"><span class="small text-muted me-auto">Save the details above first, then sign in as the sending mailbox.</span>
                <a class="btn btn-sm btn-primary" href="/integrations/email/connect"><i class="fab fa-microsoft me-1"></i>Connect with Microsoft</a></div>
            <?php endif; ?>
            <div class="small text-muted mt-1">Redirect URI to register: <code><?= e($redirectUri) ?></code></div>
          </div>

          <div class="row g-2">
            <div class="mb-3 col-md-6"><label><span data-show-when="mail_provider=microsoft,google;mail_mode=app">From mailbox</span><span data-show-when="mail_provider=smtp">From address</span><span data-show-when="mail_provider=microsoft;mail_mode=delegated">Send as <small class="text-muted">(optional shared mailbox)</small></span><span data-show-when="mail_provider=google;mail_mode=delegated">Send as <small class="text-muted">(optional Gmail "Send mail as" alias)</small></span></label>
              <input type="email" name="mail_from" class="form-control" value="<?= e($v['mail_from']) ?>" placeholder="alerts@yourdomain.com"></div>
            <div class="mb-3 col-md-6"><label>Display name <small class="text-muted">(optional)</small></label><input name="mail_from_name" class="form-control" value="<?= e($v['mail_from_name']) ?>" placeholder="<?= e(\Align\Settings::get('company_name') ?: 'Your company') ?>"></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-6"><label>Reply-to <small class="text-muted">(optional)</small></label><input type="email" name="mail_reply_to" class="form-control" value="<?= e($v['mail_reply_to']) ?>" placeholder="support@yourdomain.com"></div>
            <div class="mb-3 col-md-6"><label>Keep email content for</label>
              <div class="input-group"><input type="number" name="mail_log_days" class="form-control" min="1" max="365" value="<?= e($v['mail_log_days'] ?: '30') ?>"><span class="input-group-text">days</span></div>
              <small class="text-muted">After this the log keeps who, what and when, but not the message. Invite and password emails are wiped as soon as they're sent.</small></div>
          </div>
          <input type="hidden" name="mail_save_sent_present" value="1">
          <div class="form-check form-switch mb-2" data-show-when="mail_provider=microsoft"><input type="checkbox" class="form-check-input" id="mail_save_sent" name="mail_save_sent" value="1" <?= ($v['mail_save_sent'] ?? '1') !== '0' ? 'checked' : '' ?>><label class="form-check-label fw-normal" for="mail_save_sent">Save a copy in the mailbox's Sent Items</label></div>
        </div>
      </div>

      <div class="card-footer"><button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button></div>
    </form>
    <form method="post" action="/integrations/email/disconnect" id="email-disconnect" class="d-none"><?= csrf_field() ?></form>
  </div>

  <div class="col-xl-5">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-paper-plane me-2"></i>Send a test</h3></div>
      <form method="post" action="/integrations/email/test" class="card-body">
        <?= csrf_field() ?>
        <div class="input-group"><input type="email" name="to" class="form-control" value="<?= e(\Align\Auth::user()['email'] ?? '') ?>" required <?= $ready ? '' : 'disabled' ?>>
          <button class="btn btn-primary" <?= $ready ? '' : 'disabled' ?>>Send test</button></div>
        <small class="text-muted">Sent straight away (not queued) so you see the <?= $provider === 'smtp' ? 'server' : 'provider' ?>'s answer here.</small>
      </form>
    </div>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-inbox me-2"></i>Queue</h3><div class="card-tools"><a class="btn btn-tool" href="/settings/notifications/log">Log</a></div></div>
      <div class="card-body py-2">
        <div class="d-flex text-center">
          <div class="flex-fill"><div class="h5 mb-0 fw-bold"><?= (int) $stats['sent24'] ?></div><div class="small text-muted">sent (24 h)</div></div>
          <div class="flex-fill"><div class="h5 mb-0 fw-bold <?= $stats['queued'] ? 'text-warning' : '' ?>"><?= (int) $stats['queued'] ?></div><div class="small text-muted">queued</div></div>
          <div class="flex-fill"><div class="h5 mb-0 fw-bold <?= $stats['failed7'] ? 'text-danger' : '' ?>"><?= (int) $stats['failed7'] ?></div><div class="small text-muted">failed (7 days)</div></div>
        </div>
        <p class="small text-muted mb-0 mt-2">Email is sent every minute by the <code>msp-align-mail</code> timer and retried automatically if the <?= $provider === 'smtp' ? 'server' : 'provider' ?> is unavailable.<?= $stats['last_sent'] ? ' Last sent ' . e(rel_time($stats['last_sent'])) . '.' : '' ?></p>
      </div>
    </div>
  </div>
</div>


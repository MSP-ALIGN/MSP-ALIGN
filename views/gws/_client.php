<?php
/**
 * 2.6.3 The Google Workspace card on a client's Connectors page: connect (domain and a super admin's email, after the
 * client's super admin allows the service account under Domain-wide delegation), the connection with its last sync,
 * Sync now, Disconnect, and the client's own service account (fallback, admins).
 * @var array $client; ?array $gws client_gws row; ?array $info Workspace::info() (the MSP's service account, or null);
 * @var array $scopes Workspace::SCOPES
 * Security: every value is escaped (the organization's name and errors come from Google). Forms post with CSRF; the
 * role checks are repeated by GoogleController. A saved key is never shown back, only its client id.
 */
use Align\Auth;
use Align\Google\Clients;

$cid = (int) $client['id'];
$tech = Auth::can('tech');
$connected = Clients::connected($gws);
$own = $gws && $gws['mode'] === 'own';
$form = fn(string $action, string $label, string $cls, string $extra = '') => '<form method="post" action="/clients/' . $cid . '/gws/' . $action . '" class="d-inline">' . csrf_field()
    . '<button class="btn btn-sm ' . $cls . '"' . $extra . '>' . $label . '</button></form>';
// What the client's super admin pastes under Domain-wide delegation: the client id and the scopes, comma separated
$delegation = function (?string $clientId) use ($scopes, $cid): string {
    return '<div class="row g-2 mt-1"><div class="col-md-4"><label class="small">Client ID</label><div class="input-group input-group-sm"><input class="form-control font-monospace" id="gws-cid-' . $cid . '" value="' . e((string) $clientId) . '" readonly>'
        . '<button class="btn btn-default" type="button" data-copy="#gws-cid-' . $cid . '"><i class="fas fa-copy"></i></button></div></div>'
        . '<div class="col-md-8"><label class="small">OAuth scopes</label><div class="input-group input-group-sm"><input class="form-control font-monospace" id="gws-scopes-' . $cid . '" value="' . e(implode(',', $scopes)) . '" readonly>'
        . '<button class="btn btn-default" type="button" data-copy="#gws-scopes-' . $cid . '"><i class="fas fa-copy"></i></button></div></div></div>';
};
?>
<div class="card card-outline card-<?= $connected ? ($gws['last_error'] ? 'danger' : 'success') : 'secondary' ?>" id="gws">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fab fa-google me-2"></i>Google Workspace</h3>
    <div class="card-tools">
      <?php if ($connected && $tech): ?>
        <?= $form('sync', '<i class="fas fa-rotate me-1"></i>Sync now', 'btn-default') ?>
        <?= $form('disconnect', 'Disconnect', 'btn-link text-danger', ' data-confirm="Disconnect ' . e($client['name']) . '\'s Google Workspace? Its Google Workspace licenses are retired (restored if you connect again)."') ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body py-2 small">
    <?php if ($connected): ?>
      <?php // Connected: the organization, who Align signs in as, and the last sync ?>
      <div><b><?= e($gws['org_name'] ?: $gws['domain']) ?></b> · <?= e($gws['domain']) ?>
        <span class="text-muted">· as <?= e($gws['admin_email']) ?> · <?= $own ? 'the client\'s own service account' : 'your service account' ?> · last synced <?= $gws['last_sync_at'] ? e(rel_time($gws['last_sync_at'])) : 'not yet' ?></span></div>
      <?php if ($gws['last_error']): ?><div class="text-danger mt-1"><i class="fas fa-triangle-exclamation me-1"></i><?= e($gws['last_error']) ?></div><?php endif; ?>
      <div class="text-muted mt-1">Editions go to <a href="/clients/<?= $cid ?>/licenses">Licensing</a> (users per edition, updated every hour); prices come from the <a href="/integrations/google-workspace#prices">price list</a> unless a license has its own. Security checks (2-Step Verification, super admins, sharing) run once a day and show on the <a href="/clients/<?= $cid ?>#security">overview</a>.</div>
    <?php elseif (!$info && !Auth::can('admin')): ?>
      <p class="mb-0 text-muted">Read this client's Google Workspace editions into Licensing and check its security settings. An admin sets it up once under Integrations.</p>
    <?php elseif (!$info): ?>
      <p class="mb-0 text-muted">Read this client's Google Workspace editions into Licensing and check its security settings. First <a href="/integrations/google-workspace">set up Google Workspace (clients)</a> (once for all clients), or use a service account in the client's own Google Cloud below.</p>
    <?php else: ?>
      <?php // Not connected: what the client's super admin does first, then the form ?>
      <p class="mb-1">Connect to read <?= e($client['name']) ?>'s Google Workspace editions into Licensing (hourly) and check its security settings (daily), read-only.</p>
      <ol class="ps-3 mb-1">
        <li>A super admin of the client opens the <b>Google Admin console</b> → Security → Access and data control → API controls → <b>Manage Domain-wide delegation</b> → Add new, and pastes:<?= $delegation($info['client_id']) ?></li>
        <li class="mt-2">Enter the client's primary domain and that super admin's email (Align reads as this account; it must stay a super admin for the sharing and app checks).</li>
      </ol>
      <?php if ($tech): ?>
        <form method="post" action="/clients/<?= $cid ?>/gws/connect" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <div class="col-md-4"><label>Primary domain</label><input name="domain" class="form-control form-control-sm" placeholder="example.com" value="<?= e($gws['domain'] ?? '') ?>" required></div>
          <div class="col-md-5"><label>Super admin's email</label><input name="admin_email" type="email" class="form-control form-control-sm" placeholder="admin@example.com" value="<?= e($gws['admin_email'] ?? '') ?>" required></div>
          <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="fab fa-google me-1"></i>Connect</button></div>
        </form>
        <p class="text-muted mt-1 mb-0">Google can take a few minutes to apply a new delegation: if Connect says it isn't allowed yet, try again shortly.</p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (Auth::can('admin')): // the fallback: a service account in the client's own Google Cloud project ?>
      <details class="mt-2"<?= $own ? ' open' : '' ?>><summary class="text-muted">Use a service account in the client's own Google Cloud instead</summary>
        <form method="post" action="/clients/<?= $cid ?>/gws/connect" class="mt-2" data-unsaved>
          <?= csrf_field() ?><input type="hidden" name="own" value="1">
          <p class="text-muted mb-2">For a client that won't allow an outside service account: in <b>their</b> Google Cloud, create a project and a service account, turn on the Admin SDK, Enterprise License Manager and Cloud Identity APIs, and download a JSON key. Their super admin adds <b>that</b> service account's client ID (not yours) with the same scopes under Domain-wide delegation.<?php if ($own && $gws['key_client_id']): ?> Saved key: client ID <code><?= e($gws['key_client_id']) ?></code>.<?php endif; ?></p>
          <?php if (!$info) echo $delegation($own ? $gws['key_client_id'] : '(the client ID of their service account)'); ?>
          <div class="row g-2 mt-1">
            <div class="col-md-4"><label>Primary domain</label><input name="domain" class="form-control form-control-sm" value="<?= e($gws['domain'] ?? '') ?>" required></div>
            <div class="col-md-8"><label>Super admin's email</label><input name="admin_email" type="email" class="form-control form-control-sm" value="<?= e($gws['admin_email'] ?? '') ?>" required></div>
            <div class="col-12"><label>Service account key (JSON)</label><textarea name="key" rows="3" class="form-control form-control-sm font-monospace" autocomplete="off" spellcheck="false" placeholder="<?= $own ? 'Saved; paste a new key to replace it' : '{&quot;type&quot;: &quot;service_account&quot;, …}' ?>"></textarea></div>
          </div>
          <button class="btn btn-sm btn-default mt-2"><i class="fas fa-plug me-1"></i>Connect with this key</button>
        </form>
      </details>
    <?php endif; ?>
  </div>
</div>

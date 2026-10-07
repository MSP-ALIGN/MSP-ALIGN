<?php
/**
 * 2.6.0 The Microsoft 365 card on a client's Licensing page: connect (or send the approval link), the connected
 * tenant with its last sync, Sync now, Disconnect, confirming a tenant approved through a link, the client's own app
 * (fallback, admins), and the one-time offer to retire licenses that Microsoft 365 now counts.
 * @var array $client; ?array $m365 client_m365 row; bool $appReady; ?string $link an approval link made on the last
 * request (shown once); array $dupes Tenants::dupes() (only before the check is done)
 * Security: every value is escaped (tenant names and errors come from Microsoft). Forms post with CSRF; the role
 * checks are repeated by M365Controller. The own app's secret is never shown back. A tenant waiting for confirmation
 * shows its id and domain so staff can tell it's the right one.
 */
use Align\Auth;
use Align\M365\Tenants;

$cid = (int) $client['id'];
$tech = Auth::can('tech');
$connected = Tenants::connected($m365);
$pending = Tenants::awaiting($m365); // a tenant approved through a link (or not readable yet) waiting for staff
$form = fn(string $action, string $label, string $cls, string $extra = '') => '<form method="post" action="/clients/' . $cid . '/m365/' . $action . '" class="d-inline">' . csrf_field()
    . '<button class="btn btn-sm ' . $cls . '"' . $extra . '>' . $label . '</button></form>';
?>
<div class="card card-outline card-<?= $connected ? ($m365['last_error'] ? 'danger' : 'success') : 'secondary' ?>" id="m365">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fab fa-microsoft me-2"></i>Microsoft 365</h3>
    <div class="card-tools">
      <?php if ($connected && $tech): ?>
        <?= $form('sync', '<i class="fas fa-rotate me-1"></i>Sync now', 'btn-default') ?>
        <?= $form('disconnect', 'Disconnect', 'btn-link text-danger', ' data-confirm="Disconnect ' . e($client['name']) . '\'s Microsoft 365? Its Microsoft 365 licenses are retired (restored if you connect again)."') ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body py-2 small">
    <?php if ($pending): // waiting for staff: shown with its id and domain, above any working connection (which it doesn't touch) ?>
      <div class="alert alert-warning py-2 mb-2"><b><?= e($m365['pending_tenant_name']) ?></b><?= $m365['pending_tenant_domain'] ? ' · ' . e($m365['pending_tenant_domain']) : '' ?>
        <div class="font-monospace small">Tenant ID <?= e($m365['pending_tenant_id']) ?></div>
        <div><?= e((string) $m365['pending_note']) ?> Is that <?= e($client['name']) ?>'s tenant?<?= $connected ? ' Confirming replaces the connection below.' : '' ?></div></div>
      <?php if ($tech): ?><div class="mb-2"><?= $form('confirm', '<i class="fas fa-check me-1"></i>Yes, connect it', 'btn-primary') ?> <?= $form('reject', 'No, forget it', 'btn-default') ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if ($connected): ?>
      <?php // Connected: the tenant and the last sync ?>
      <div><b><?= e($m365['tenant_name']) ?></b><?= $m365['tenant_domain'] ? ' · ' . e($m365['tenant_domain']) : '' ?>
        <span class="text-muted">· <?= $m365['mode'] === 'own' ? 'the client\'s own app' : 'your app' ?> · last synced <?= $m365['last_sync_at'] ? e(rel_time($m365['last_sync_at'])) : 'not yet' ?></span></div>
      <?php if ($m365['last_error']): ?><div class="text-danger mt-1"><i class="fas fa-triangle-exclamation me-1"></i><?= e($m365['last_error']) ?></div><?php endif; ?>
      <?php if ($m365['mode'] === 'own' && $m365['secret_expires'] && $m365['secret_expires'] <= date('Y-m-d', strtotime('+' . Tenants::WARN_DAYS . ' days'))): ?>
        <div class="text-warning mt-1"><i class="fas fa-key me-1"></i>The app's client secret <?= $m365['secret_expires'] < date('Y-m-d') ? 'has expired' : 'expires ' . e(fmt_date($m365['secret_expires'])) ?>: save a new one below.</div>
      <?php endif; ?>
      <div class="text-muted mt-1">Seats bought and assigned update every hour. Prices come from the <a href="/integrations/microsoft-365#prices">price list</a> unless a license has its own.</div>
    <?php elseif ($pending): ?>
      <?php // nothing more to show until it's confirmed or forgotten ?>
    <?php elseif (!$appReady): ?>
      <p class="mb-0 text-muted">Read this client's Microsoft 365 subscriptions into Licensing. <?= Auth::can('admin') ? 'First <a href="/integrations/microsoft-365">set up Microsoft 365 (clients)</a> (once for all clients).' : 'An admin sets it up once under Integrations.' ?></p>
    <?php else: ?>
      <p class="mb-2">Connect to read <?= e($client['name']) ?>'s Microsoft 365 subscriptions into Licensing, hourly and read-only. An admin of their tenant approves your app once.</p>
      <?php if ($tech): ?>
        <div class="d-flex flex-wrap gap-2">
          <?= $form('connect', '<i class="fab fa-microsoft me-1"></i>Connect Microsoft 365', 'btn-primary', ' title="Opens Microsoft: sign in as the client\'s admin and accept"') ?>
          <?= $form('link', '<i class="fas fa-link me-1"></i>Link for the client\'s admin', 'btn-default') ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($link): // an approval link, shown once ?>
      <div class="mt-2"><label class="small">Send this to <?= e($client['name']) ?>'s Microsoft 365 admin. It works once, for <?= (int) Tenants::LINK_DAYS ?> days (a newer link or Connect replaces it); you confirm the tenant here afterwards.</label>
        <div class="input-group input-group-sm"><input class="form-control" id="m365-link" value="<?= e($link) ?>" readonly><button class="btn btn-default" type="button" data-copy="#m365-link"><i class="fas fa-copy me-1"></i>Copy</button></div></div>
    <?php endif; ?>

    <?php if (Auth::can('admin') && !$pending): // the fallback (works without the MSP app): an app in the client's own tenant ?>
      <details class="mt-2"<?= $m365 && $m365['mode'] === 'own' ? ' open' : '' ?>><summary class="text-muted">Use an app in the client's own tenant instead</summary>
        <form method="post" action="/clients/<?= $cid ?>/m365/own" class="mt-2" data-unsaved>
          <?= csrf_field() ?>
          <p class="text-muted mb-2">For a client that doesn't allow outside apps: register an app in <b>their</b> Entra ID with Microsoft Graph application permissions Organization.Read.All and User.Read.All (admin consent granted) and a client secret.</p>
          <div class="row g-2">
            <div class="col-md-6"><label>Directory (tenant) ID</label><input name="tenant_id" class="form-control form-control-sm" value="<?= e($m365 && $m365['mode'] === 'own' ? $m365['tenant_id'] : '') ?>" required></div>
            <div class="col-md-6"><label>Application (client) ID</label><input name="app_id" class="form-control form-control-sm" value="<?= e($m365 && $m365['mode'] === 'own' ? $m365['app_id'] : '') ?>" required></div>
            <div class="col-md-8"><label>Client secret (value)</label><input name="secret" type="password" autocomplete="off" class="form-control form-control-sm" placeholder="<?= $m365 && $m365['mode'] === 'own' ? 'Saved; paste a new one to replace it' : '' ?>"></div>
            <div class="col-md-4"><label>Secret expires</label><input name="secret_expires" type="date" class="form-control form-control-sm" value="<?= e($m365 && $m365['mode'] === 'own' ? (string) $m365['secret_expires'] : '') ?>" required></div>
          </div>
          <button class="btn btn-sm btn-default mt-2"><i class="fas fa-plug me-1"></i>Connect with this app</button>
        </form>
      </details>
    <?php endif; ?>
  </div>
</div>

<?php if ($dupes && $tech): // the one-time duplicate check after connecting ?>
<form method="post" action="/clients/<?= $cid ?>/m365/dupes" class="card card-outline card-warning">
  <?= csrf_field() ?>
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-clone me-2 text-warning"></i>Counted twice?</h3></div>
  <div class="card-body py-2 small">
    <p class="mb-2">These licenses look like Microsoft subscriptions that Microsoft 365 now counts. Retire the ones that are the same, so their cost isn't counted twice:</p>
    <?php foreach ($dupes as $d): ?>
      <div class="form-check"><input type="checkbox" class="form-check-input" name="retire[]" value="<?= (int) $d['id'] ?>" id="dupe-<?= (int) $d['id'] ?>" checked>
        <label class="form-check-label fw-normal" for="dupe-<?= (int) $d['id'] ?>"><?= e($d['name']) ?> <span class="text-muted">(<?= $d['source'] === 'psa' ? e(psa_name()) : 'added in Align' ?><?= $d['seats'] !== null ? ', ' . (int) $d['seats'] . ' seats' : '' ?>)</span></label></div>
    <?php endforeach; ?>
    <div class="mt-2"><button class="btn btn-sm btn-warning">Retire the ticked ones</button>
      <button class="btn btn-sm btn-link" name="keep" value="1" formnovalidate>Keep them all</button></div>
    <?php if (array_filter($dupes, fn($x) => $x['source'] === 'psa')): ?><p class="text-muted mt-2 mb-0">Retired here only: archive them in <?= e(psa_name()) ?> too.</p><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php
/**
 * 2.6.0 Integrations → Microsoft 365 (clients), admins only: setting up the MSP's app (Align creates it after a
 * one-time sign-in, or an existing app entered by hand), its certificate, the price list and the connected clients.
 * @var array $app App::info(); ?int $daysLeft; ?array $setup the sign-in in progress (user_code, verification_uri);
 * @var array $prices Tenants::prices(); array $clients client_m365 rows with client_name and licenses; string $redirectUri
 * Security: every value is escaped (tenant, app and subscription names come from Microsoft). The device code itself
 * stays in the session; only the short user code and Microsoft's sign-in page are shown. Every form posts with CSRF.
 */
use Align\Licensing\Licenses;

$ready = $app['ready'];
?>
<div class="small mb-1"><a href="/integrations">Integrations</a> /</div>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h3 mb-0 me-3"><i class="fab fa-microsoft text-secondary me-2"></i>Microsoft 365 (clients)</h1>
  <span class="badge text-bg-<?= $ready ? 'success' : 'secondary' ?> px-2 py-1 me-auto"><?= $ready ? 'Ready' : 'Not set up' ?></span>
  <a class="small" href="/help#guide-m365">How it works</a>
</div>
<?php if (!\Align\Config::get('base_url')): // the app's redirect URI comes from it; without it, from this page's address ?>
  <div class="alert alert-warning py-2 small"><i class="fas fa-triangle-exclamation me-1"></i>Set <code>base_url</code> in the server's config before setting this up: the app is registered with this server's address (<?= e($redirectUri) ?>), and if the address changes later every client's approval stops working.</div>
<?php endif; ?>
<p class="text-muted small mb-3">Reads each client's Microsoft 365 subscriptions (seats bought and assigned) into their Licensing, hourly, once the client's admin approves your app. <b>Read only</b>: Align can't change anything in a client's tenant.</p>

<?php // ---- The MSP's app: set up, or its status ---- ?>
<div class="row">
  <div class="col-xl-7">
    <div class="card card-dark" id="app">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-id-badge me-2"></i>Your app</h3></div>
      <div class="card-body">
        <?php if ($ready): ?>
          <dl class="row small mb-2">
            <dt class="col-sm-4">App</dt><dd class="col-sm-8"><?= e($app['app_name'] ?: 'Your app') ?> <span class="text-muted">(<?= $app['mode'] === 'auto' ? 'created by Align' : 'your own' ?>)</span></dd>
            <dt class="col-sm-4">Application (client) ID</dt><dd class="col-sm-8"><code><?= e($app['app_id']) ?></code></dd>
            <dt class="col-sm-4">Your tenant</dt><dd class="col-sm-8"><?= e($app['tenant_name'] ?: $app['tenant']) ?></dd>
            <dt class="col-sm-4"><?= $app['mode'] === 'auto' ? 'Certificate' : 'Client secret' ?></dt>
            <dd class="col-sm-8"><?php if ($app['expires']): ?>expires <?= e(fmt_date($app['expires'])) ?><?php if ($daysLeft !== null && $daysLeft <= 30): ?> <span class="badge text-bg-<?= $daysLeft <= 7 ? 'danger' : 'warning' ?>"><?= $daysLeft < 0 ? 'expired' : "$daysLeft days" ?></span><?php endif; ?><?php endif; ?>
              <?php if ($app['mode'] === 'auto'): ?><div class="text-muted">Replaced automatically <?= (int) \Align\M365\App::ROTATE_DAYS ?> days before it expires<?= $app['rotated_at'] ? '; last replaced ' . e(fmt_date($app['rotated_at'])) : '' ?>.</div><?php endif; ?></dd>
          </dl>
          <?php if ($app['rotate_error']): ?><div class="alert alert-danger py-2 small mb-2"><i class="fas fa-triangle-exclamation me-1"></i>The certificate couldn't be replaced: <?= e($app['rotate_error']) ?> It's tried again daily; the current one works until it expires.</div><?php endif; ?>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($app['mode'] === 'auto'): ?>
              <form method="post" action="/integrations/microsoft-365/rotate"><?= csrf_field() ?><button class="btn btn-sm btn-default" data-confirm="Replace the certificate now? Align makes a new one, adds it to the app and removes the old one." data-confirm-danger="0" data-confirm-ok="Replace"><i class="fas fa-rotate me-1"></i>Replace certificate now</button></form>
            <?php endif; ?>
            <form method="post" action="/integrations/microsoft-365/forget"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" data-confirm="Remove the app from Align? Connected clients stop syncing until an app is set up again (their licenses stay). The app stays in your Microsoft tenant until you delete it there."><i class="fas fa-trash me-1"></i>Remove from Align</button></form>
          </div>
        <?php elseif ($setup): ?>
          <?php // The sign-in in progress: the short code and Microsoft's page ?>
          <ol class="ps-3">
            <li class="mb-2">Open <a href="<?= e($setup['verification_uri']) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https://#', '', $setup['verification_uri'])) ?></a> and enter this code:
              <div class="display-6 fw-bold my-2 font-monospace" id="m365-user-code"><?= e($setup['user_code']) ?></div></li>
            <li class="mb-2">Sign in with an <b>admin account of your own (MSP) tenant</b> (Global Administrator, or Application Administrator plus Privileged Role Administrator) and accept. Microsoft names the sign-in "Microsoft Graph Command Line Tools": that's Microsoft's own tool, used only for this.</li>
            <li>Come back here and press Continue.</li>
          </ol>
          <div class="d-flex gap-2">
            <form method="post" action="/integrations/microsoft-365/setup/finish"><?= csrf_field() ?><button class="btn btn-primary"><i class="fas fa-arrow-right me-1"></i>Continue</button></form>
            <form method="post" action="/integrations/microsoft-365/setup"><?= csrf_field() ?><button class="btn btn-default">New code</button></form>
          </div>
          <p class="small text-muted mt-2 mb-0">The code works for <?= max(1, (int) round(($setup['expires'] - time()) / 60)) ?> more minutes.</p>
        <?php else: ?>
          <p>Align creates one app in <b>your</b> Microsoft tenant: each client's admin approves it once, and Align reads their tenant with it. You sign in once; nothing to copy or paste.</p>
          <ul class="small text-muted">
            <li>The app can only <b>read</b> client tenants: subscriptions and users (for license waste later).</li>
            <li>It signs in with a certificate whose private key stays (encrypted) in Align, replaced automatically every year.</li>
            <li>Your sign-in is used once, to create the app, and not kept.</li>
          </ul>
          <form method="post" action="/integrations/microsoft-365/setup"><?= csrf_field() ?><button class="btn btn-primary"><i class="fab fa-microsoft me-1"></i>Set up with Microsoft</button></form>
          <p class="small text-muted mt-3 mb-0">Can't sign in that way (for example a Conditional Access policy)? <a href="#manual" data-bs-toggle="collapse">Use an app you made yourself</a>.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php // ---- Manual: an app the MSP made themselves ---- ?>
    <div class="collapse<?= $app['mode'] === 'manual' ? ' show' : '' ?>" id="manual">
      <form method="post" action="/integrations/microsoft-365/manual" class="card card-dark" data-unsaved>
        <?= csrf_field() ?>
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-wrench me-2"></i>Use an app you made yourself</h3></div>
        <div class="card-body small">
          <p>In <b>your</b> Entra ID: App registrations → New registration, <b>Accounts in any organizational directory</b>, Web redirect URI <code><?= e($redirectUri) ?></code>. Under API permissions add Microsoft Graph <b>application</b> permissions <b>Organization.Read.All</b> and <b>User.Read.All</b>. Create a client secret.</p>
          <div class="row g-2">
            <div class="col-md-6"><label>Application (client) ID</label><input name="app_id" class="form-control" value="<?= e($app['mode'] === 'manual' ? $app['app_id'] : '') ?>" required></div>
            <div class="col-md-6"><label>Directory (tenant) ID</label><input name="tenant_id" class="form-control" value="<?= e($app['mode'] === 'manual' ? $app['tenant'] : '') ?>" required></div>
            <div class="col-md-8"><label>Client secret (value)</label><input name="secret" type="password" class="form-control" autocomplete="off" placeholder="<?= $app['mode'] === 'manual' ? 'Saved; paste a new one to replace it' : '' ?>"></div>
            <div class="col-md-4"><label>Secret expires</label><input name="secret_expires" type="date" class="form-control" value="<?= e($app['mode'] === 'manual' ? (string) $app['expires'] : '') ?>" required></div>
          </div>
          <p class="text-muted mt-2 mb-2">A secret isn't replaced automatically: you're reminded 30 days before it expires.</p>
          <button class="btn btn-sm btn-primary"><i class="fas fa-check me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>

  <?php // ---- Connected clients ---- ?>
  <div class="col-xl-5">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-building me-2"></i>Clients</h3><div class="card-tools"><span class="badge text-bg-light"><?= count($clients) ?></span></div></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($clients as $c): ?>
          <li class="list-group-item d-flex align-items-start">
            <div class="me-auto"><a href="/clients/<?= (int) $c['client_id'] ?>/licenses#m365" class="fw-bold"><?= e($c['client_name']) ?></a>
              <div class="text-muted"><?= e($c['tenant_name'] ?: ($c['tenant_id'] ?: ($c['pending_tenant_name'] ?: $c['pending_tenant_id']))) ?><?= $c['tenant_domain'] ? ' · ' . e($c['tenant_domain']) : '' ?><?= $c['mode'] === 'own' ? ' · own app' : '' ?></div>
              <?php if ($c['last_error']): ?><div class="text-danger"><?= e($c['last_error']) ?></div><?php endif; ?></div>
            <div class="text-end text-nowrap ms-2">
              <?php if ($c['pending_tenant_id'] && $c['status'] !== 'connected'): ?><span class="badge text-bg-warning">to confirm</span>
              <?php else: ?><span class="badge text-bg-<?= $c['last_error'] ? 'danger' : 'success' ?>"><?= (int) $c['licenses'] ?> licenses</span>
                <div class="text-muted"><?= $c['last_sync_at'] ? e(rel_time($c['last_sync_at'])) : 'not synced' ?></div><?php endif; ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$clients): ?><li class="list-group-item text-muted">No clients connected yet. <?= $ready ? 'Open a client\'s Licensing page and press Connect Microsoft 365.' : 'Set up the app first.' ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php // ---- The price list ---- ?>
<form method="post" action="/integrations/microsoft-365/prices" class="card card-dark" id="prices" data-unsaved>
  <?= csrf_field() ?>
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-tags me-2"></i>Price list</h3></div>
  <div class="card-body pb-0"><p class="small text-muted">Your price to clients for each Microsoft subscription, used by every client's license unless you give a license its own price. Subscriptions appear here as clients are synced; free and trial ones are left out of Licensing (untick "Leave out" to count one).</p></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>Subscription</th><th style="width:22%">Name in Align</th><th style="width:15%">Price per seat</th><th style="width:13%">Billed</th><th class="text-end">Clients · seats</th><th class="text-center">Leave out</th></tr></thead>
      <tbody>
      <?php foreach ($prices as $p): $k = e($p['sku_part']); ?>
        <tr class="<?= $p['skip'] ? 'text-muted' : '' ?>">
          <td><code class="small"><?= $k ?></code></td>
          <td><input name="prices[<?= $k ?>][name]" class="form-control form-control-sm" value="<?= e($p['name']) ?>" maxlength="190"></td>
          <td><div class="input-group input-group-sm"><span class="input-group-text"><?= e(\Align\Fmt::symbol()) ?></span><input name="prices[<?= $k ?>][unit_price]" type="number" min="0" step="0.01" class="form-control" value="<?= e($p['unit_price']) ?>"></div></td>
          <td><select name="prices[<?= $k ?>][billing_cycle]" class="form-select form-select-sm"><?php foreach (['monthly', 'quarterly', 'annual'] as $cy): ?><option value="<?= $cy ?>" <?= $p['billing_cycle'] === $cy ? 'selected' : '' ?>><?= e(Licenses::CYCLES[$cy][0]) ?></option><?php endforeach; ?></select></td>
          <td class="text-end small"><?= (int) $p['in_use'] ?> · <?= (int) $p['seats'] ?></td>
          <td class="text-center"><input type="checkbox" class="form-check-input" name="prices[<?= $k ?>][skip]" value="1" <?= $p['skip'] ? 'checked' : '' ?> aria-label="Leave out <?= e($p['name']) ?>"></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$prices): ?><tr><td colspan="6" class="text-muted text-center py-3">Nothing yet: subscriptions are added as clients are connected and synced.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($prices): ?><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save price list</button></div><?php endif; ?>
</form>

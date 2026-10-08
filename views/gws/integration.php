<?php
/**
 * 2.6.3 Integrations → Google Workspace (clients), admins only: the MSP's service account (save its JSON key, or
 * remove it), what each client's super admin allows (client ID and scopes to copy), the connected clients and the
 * price list.
 * @var ?array $info Workspace::info(); array $scopes; array $prices Clients::prices(); array $clients client_gws rows
 *      with client_name and licenses
 * Security: every value is escaped (organization and edition names come from Google). The key is never shown back,
 * only its service account's email and client id. Every form posts with CSRF.
 */
use Align\Licensing\Licenses;

$ready = $info !== null;
?>
<div class="small mb-1"><a href="/integrations">Integrations</a> /</div>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h3 mb-0 me-3"><i class="fab fa-google text-secondary me-2"></i>Google Workspace (clients)</h1>
  <span class="badge text-bg-<?= $ready ? 'success' : 'secondary' ?> px-2 py-1 me-auto"><?= $ready ? 'Ready' : 'Not set up' ?></span>
  <a class="small" href="/help#guide-gws">How it works</a>
</div>
<p class="text-muted small mb-3">Reads each client's Google Workspace editions (users per edition) into their Licensing, hourly, and its security settings daily, once the client's super admin allows your service account. <b>Read only</b>: Align only ever reads (Google offers the licensing scope only as read-write; Align never sends a change).</p>

<div class="row">
  <div class="col-xl-7">
    <?php // ---- The MSP's service account ---- ?>
    <div class="card card-dark" id="key">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-id-badge me-2"></i>Your service account</h3></div>
      <div class="card-body small">
        <?php if ($ready): ?>
          <dl class="row mb-2">
            <dt class="col-sm-4">Service account</dt><dd class="col-sm-8"><?= e($info['client_email']) ?></dd>
            <dt class="col-sm-4">Client ID</dt><dd class="col-sm-8"><div class="input-group input-group-sm"><input class="form-control font-monospace" id="gws-client-id" value="<?= e($info['client_id']) ?>" readonly><button class="btn btn-default" type="button" data-copy="#gws-client-id"><i class="fas fa-copy"></i></button></div></dd>
            <dt class="col-sm-4">OAuth scopes</dt><dd class="col-sm-8"><div class="input-group input-group-sm"><input class="form-control font-monospace" id="gws-scopes" value="<?= e(implode(',', $scopes)) ?>" readonly><button class="btn btn-default" type="button" data-copy="#gws-scopes"><i class="fas fa-copy"></i></button></div></dd>
            <?php if ($info['saved_at']): ?><dt class="col-sm-4">Saved</dt><dd class="col-sm-8"><?= e(fmt_date($info['saved_at'])) ?></dd><?php endif; ?>
          </dl>
          <p class="text-muted">Each client's super admin adds this client ID with these scopes in their Google Admin console → Security → Access and data control → API controls → Manage Domain-wide delegation. Then connect the client from its Connectors page.</p>
          <form method="post" action="/integrations/google-workspace/forget"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" data-confirm="Remove the key from Align? Clients connected with it stop syncing until a key is saved again (their licenses stay)."><i class="fas fa-trash me-1"></i>Remove from Align</button></form>
        <?php else: ?>
          <ol class="ps-3">
            <li>In <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud</a>, create (or pick) a project of yours and turn on <b>Admin SDK API</b>, <b>Enterprise License Manager API</b> and <b>Cloud Identity API</b> (APIs &amp; Services → Library).</li>
            <li>IAM &amp; Admin → Service accounts → <b>Create service account</b> (no roles needed), then Keys → Add key → <b>JSON</b>.</li>
            <li>Paste or choose the downloaded file here. It's stored encrypted and never shown again.</li>
          </ol>
          <p class="text-muted">An organization policy may block key creation (<code>iam.disableServiceAccountKeyCreation</code>): allow it for this project.</p>
        <?php endif; ?>
        <form method="post" action="/integrations/google-workspace/key" enctype="multipart/form-data" class="<?= $ready ? 'mt-3 border-top pt-2' : '' ?>" data-unsaved>
          <?= csrf_field() ?>
          <?php if ($ready): ?><label class="fw-bold">Replace the key</label><p class="text-muted mb-1">For a new key of the same service account (clients keep working); a different service account means every client allows it again.</p><?php endif; ?>
          <textarea name="key" rows="3" class="form-control form-control-sm font-monospace mb-2" autocomplete="off" spellcheck="false" placeholder='{"type": "service_account", …}'></textarea>
          <div class="d-flex flex-wrap gap-2 align-items-center"><input type="file" name="key_file" accept=".json,application/json" class="form-control form-control-sm w-auto"><button class="btn btn-sm btn-primary"><i class="fas fa-check me-1"></i>Save key</button></div>
        </form>
      </div>
    </div>
  </div>

  <?php // ---- Connected clients ---- ?>
  <div class="col-xl-5">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-building me-2"></i>Clients</h3><div class="card-tools"><span class="badge text-bg-light"><?= count($clients) ?></span></div></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($clients as $c): ?>
          <li class="list-group-item d-flex align-items-start">
            <div class="me-auto"><a href="/clients/<?= (int) $c['client_id'] ?>/connectors#gws" class="fw-bold"><?= e($c['client_name']) ?></a>
              <div class="text-muted"><?= e($c['org_name'] ?: $c['domain']) ?> · <?= e($c['domain']) ?><?= $c['mode'] === 'own' ? ' · own service account' : '' ?></div>
              <?php if ($c['last_error']): ?><div class="text-danger"><?= e($c['last_error']) ?></div><?php endif; ?></div>
            <div class="text-end text-nowrap ms-2"><span class="badge text-bg-<?= $c['last_error'] ? 'danger' : 'success' ?>"><?= (int) $c['licenses'] ?> licenses</span>
              <div class="text-muted"><?= $c['last_sync_at'] ? e(rel_time($c['last_sync_at'])) : 'not synced' ?></div></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$clients): ?><li class="list-group-item text-muted">No clients connected yet. <?= $ready ? 'Open a client\'s Connectors page to connect it.' : 'Save your service account key first.' ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php // ---- The price list ---- ?>
<form method="post" action="/integrations/google-workspace/prices" class="card card-dark" id="prices" data-unsaved>
  <?= csrf_field() ?>
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-tags me-2"></i>Price list</h3></div>
  <div class="card-body pb-0"><p class="small text-muted">Your price to clients per user for each Google Workspace edition, used by every client's license unless you give a license its own price. Editions you don't sell can be left out of Licensing.</p></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>SKU</th><th style="width:30%">Name in Align</th><th style="width:15%">Price per user</th><th style="width:13%">Billed</th><th class="text-end">Clients · users</th><th class="text-center">Leave out</th></tr></thead>
      <tbody>
      <?php foreach ($prices as $p): $k = e($p['sku_id']); ?>
        <tr class="<?= $p['skip'] ? 'text-muted' : '' ?>">
          <td><code class="small"><?= $k ?></code></td>
          <td><input name="prices[<?= $k ?>][name]" class="form-control form-control-sm" value="<?= e($p['name']) ?>" maxlength="190"></td>
          <td><div class="input-group input-group-sm"><span class="input-group-text"><?= e(\Align\Fmt::symbol()) ?></span><input name="prices[<?= $k ?>][unit_price]" type="number" min="0" step="0.01" class="form-control" value="<?= e($p['unit_price']) ?>"></div></td>
          <td><select name="prices[<?= $k ?>][billing_cycle]" class="form-select form-select-sm"><?php foreach (['monthly', 'quarterly', 'annual'] as $cy): ?><option value="<?= $cy ?>" <?= $p['billing_cycle'] === $cy ? 'selected' : '' ?>><?= e(Licenses::CYCLES[$cy][0]) ?></option><?php endforeach; ?></select></td>
          <td class="text-end small"><?= (int) $p['in_use'] ?> · <?= (int) $p['seats'] ?></td>
          <td class="text-center"><input type="checkbox" class="form-check-input" name="prices[<?= $k ?>][skip]" value="1" <?= $p['skip'] ? 'checked' : '' ?> aria-label="Leave out <?= e($p['name']) ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><button class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save price list</button></div>
</form>

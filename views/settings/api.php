<?php
use Align\Api\Keys;

/**
 * Settings -> API.
 * @var bool $enabled; array $keys, $clients, $requests, $stats; ?array $new ['id','name','token']; string $baseUrl
 * Security: a new key's token is shown here once (the controller drops it from the session); afterwards only its
 * prefix. Request log fields (path, IP, request id) come from API callers and are escaped.
 */
$tab = 'api';
require __DIR__ . '/_tabs.php';
$stateBadge = ['active' => ['Active', 'success'], 'expiring' => ['Expires soon', 'warning'], 'expired' => ['Expired', 'secondary'], 'revoked' => ['Revoked', 'dark'], 'owner_inactive' => ['Stopped: creator disabled', 'danger']];
$scopeSummary = function (array $k): string {
    $s = $k['scope_list'];
    $all = Keys::allScopes();
    if (count($s) === count($all)) {
        return 'Full access';
    }
    $writes = array_map(fn($x) => substr($x, 0, -6), array_filter($s, fn($x) => str_ends_with($x, ':write')));
    $reads = array_map(fn($x) => substr($x, 0, -5), array_filter($s, fn($x) => str_ends_with($x, ':read')));
    $readAll = count($reads) === count(Keys::AREAS);
    return ($readAll ? 'Read everything' : 'Read: ' . implode(', ', array_map(fn($a) => Keys::AREAS[$a][0], $reads)))
        . ($writes ? ' · Write: ' . implode(', ', array_map(fn($a) => Keys::AREAS[$a][0], $writes)) : '');
};
?>
<?php if ($new): ?>
  <div class="card card-outline card-success" id="api-new-token">
    <div class="card-body">
      <h2 class="h5"><i class="fas fa-key text-success me-2"></i>Key created: <?= e($new['name']) ?></h2>
      <p class="mb-2">Copy it now and store it in your password manager or the tool that will use it. <b>It won't be shown again</b>; if it's lost, revoke it and create a new one.</p>
      <div class="input-group" style="max-width:720px">
        <input type="text" class="form-control font-monospace" id="api-token" value="<?= e($new['token']) ?>" readonly aria-label="New API key">
        <button class="btn btn-success" type="button" data-copy="#api-token"><i class="fas fa-copy me-1"></i>Copy</button>
      </div>
      <p class="small text-muted mt-2 mb-0">Test it: <code>curl -H "Authorization: Bearer <?= e(substr($new['token'], 0, 13)) ?>…" <?= e($baseUrl) ?></code></p>
    </div>
  </div>
<?php endif; ?>

<div class="row">
  <div class="col-xl-8">
    <div class="card card-dark">
      <div class="card-header py-2 d-flex align-items-center"><h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-code me-2"></i>REST API</h3>
        <form method="post" action="/settings/api/toggle" class="d-inline"><?= csrf_field() ?><input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
          <button class="btn btn-sm <?= $enabled ? 'btn-outline-light' : 'btn-success' ?>"<?= $enabled ? ' data-confirm="' . e('Turn off the API? ' . (count($keys) ? 'All ' . count($keys) . ' key' . (count($keys) === 1 ? '' : 's') . ' stop working until you turn it back on: anything using them (n8n, scripts, agents) will fail.' : 'Nothing can use it until you turn it back on.')) . '" data-confirm-ok="Turn off"' : '' ?>><i class="fas fa-power-off me-1"></i><?= $enabled ? 'Turn off' : 'Turn on' ?></button></form></div>
      <div class="card-body">
        <p class="mb-2"><?= $enabled ? '<span class="badge text-bg-success me-1">On</span>' : '<span class="badge text-bg-secondary me-1">Off</span>' ?>
          Lets automation tools (n8n, Zapier, Power Automate), AI agents and your own scripts read and change planning data with an API key. Every change is recorded in the audit log with the key's name.</p>
        <dl class="row small mb-2">
          <dt class="col-sm-3">Base URL</dt><dd class="col-sm-9"><code><?= e($baseUrl) ?></code></dd>
          <dt class="col-sm-3">Authentication</dt><dd class="col-sm-9"><code>Authorization: Bearer msa_…</code></dd>
          <dt class="col-sm-3">Format</dt><dd class="col-sm-9">JSON; OpenAPI 3.1 description for importing into tools and agents</dd>
        </dl>
        <a class="btn btn-sm btn-default me-1" href="/settings/api/docs"><i class="fas fa-book me-1"></i>API reference</a>
        <a class="btn btn-sm btn-default" href="/settings/api/openapi.json"><i class="fas fa-download me-1"></i>OpenAPI (JSON)</a>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card"><div class="card-body">
      <h3 class="h6 text-muted text-uppercase small fw-bold">Last 24 hours</h3>
      <div class="d-flex text-center">
        <div class="flex-fill"><div class="h4 mb-0"><?= (int) $stats['n'] ?></div><div class="small text-muted">requests</div></div>
        <div class="flex-fill"><div class="h4 mb-0<?= (int) $stats['errors'] ? ' text-warning' : '' ?>"><?= (int) $stats['errors'] ?></div><div class="small text-muted">errors</div></div>
        <div class="flex-fill"><div class="h4 mb-0<?= (int) $stats['limited'] ? ' text-danger' : '' ?>"><?= (int) $stats['limited'] ?></div><div class="small text-muted">rate limited</div></div>
        <div class="flex-fill"><div class="h4 mb-0"><?= $stats['avg_ms'] !== null ? (int) $stats['avg_ms'] : '—' ?></div><div class="small text-muted">avg ms</div></div>
      </div>
    </div></div>
  </div>
</div>

<div class="card card-dark">
  <div class="card-header py-2 d-flex align-items-center"><h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-key me-2"></i>API keys (<?= count($keys) ?>)</h3>
    <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#api-create" aria-expanded="<?= query('new') === '1' ? 'true' : 'false' ?>"><i class="fas fa-plus me-1"></i>New API key</button></div>
  <div class="collapse<?= query('new') === '1' || (!$keys && $enabled) ? ' show' : '' ?>" id="api-create">
    <form method="post" action="/settings/api/keys" class="card-body border-bottom bg-light" data-api-form>
      <?= csrf_field() ?>
      <h4 class="h6 mb-3">New API key</h4>
      <?php $formKey = null; require __DIR__ . '/_api_key_form.php'; ?>
      <button class="btn btn-primary"><i class="fas fa-key me-1"></i>Create key</button>
      <span class="small text-muted ms-2">Give each tool its own key with only what it needs, so you can revoke one without breaking the others.</span>
    </form>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-striped table-borderless table-hover mb-0">
      <thead class="text-dark"><tr><th>Name</th><th>Key</th><th>Permissions</th><th>Clients</th><th>Expires</th><th>Last used</th><th class="text-end">24h</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($keys as $k): $st = Keys::state($k); [$sl, $sc] = $stateBadge[$st]; ?>
        <tr class="<?= in_array($st, ['revoked', 'expired', 'owner_inactive'], true) ? 'text-muted' : '' ?>">
          <td class="align-middle"><a href="/settings/api/keys/<?= (int) $k['id'] ?>" class="fw-bold"><?= e($k['name']) ?></a> <span class="badge text-bg-<?= $sc ?>"><?= $sl ?></span>
            <?php if ($k['notes']): ?><div class="small text-muted"><?= e($k['notes']) ?></div><?php endif; ?></td>
          <td class="align-middle"><code class="small">msa_<?= e($k['prefix']) ?>_…</code></td>
          <td class="small align-middle" style="max-width:320px"><?= e($scopeSummary($k)) ?></td>
          <td class="small align-middle"><?= $k['client_list'] === null ? 'All' : count($k['client_list']) . ' client' . (count($k['client_list']) === 1 ? '' : 's') ?></td>
          <td class="small align-middle text-nowrap"><?= $k['expires_at'] ? e(fmt_date($k['expires_at'])) : 'Never' ?></td>
          <td class="small align-middle text-nowrap"><?= $k['last_used_at'] ? e(rel_time($k['last_used_at'])) . '<div class="text-muted">' . e($k['last_ip']) . '</div>' : '<span class="text-muted">never</span>' ?></td>
          <td class="small align-middle text-end"><?= (int) $k['requests_24h'] ?><?= (int) $k['errors_24h'] ? ' <span class="text-warning" title="Errors">(' . (int) $k['errors_24h'] . ')</span>' : '' ?></td>
          <td class="align-middle text-end text-nowrap"><a class="btn btn-xs btn-default" href="/settings/api/keys/<?= (int) $k['id'] ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$keys): ?><tr><td colspan="8" class="text-muted p-3">No keys yet.<?= $enabled ? ' Press New API key.' : ' Turn the API on, then create a key.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list me-2"></i>Recent requests</h3>
    <div class="card-tools"><input type="search" class="form-control form-control-sm filter-input" data-filter-table="api-requests" placeholder="Filter…" aria-label="Filter requests"></div></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-striped table-borderless mb-0 small" id="api-requests">
      <thead class="text-dark"><tr><th>When</th><th>Key</th><th>Request</th><th>Status</th><th class="text-end">ms</th><th>IP</th><th>Request id</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td class="text-nowrap" title="<?= e(fmt_datetime($r['created_at'])) ?>"><?= e(rel_time($r['created_at'])) ?></td>
          <td><?= $r['key_name'] ? e($r['key_name']) : '<span class="text-muted">no valid key</span>' ?></td>
          <td class="font-monospace"><?= e($r['method'] . ' ' . $r['path']) ?></td>
          <td><span class="badge text-bg-<?= $r['status'] >= 500 ? 'danger' : ($r['status'] >= 400 ? 'warning' : 'success') ?>"><?= (int) $r['status'] ?></span><?= $r['error_code'] ? ' <span class="text-muted">' . e($r['error_code']) . '</span>' : '' ?></td>
          <td class="text-end"><?= (int) $r['ms'] ?></td>
          <td><?= e($r['ip']) ?></td>
          <td class="font-monospace text-muted"><?= e($r['request_id']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$requests): ?><tr><td colspan="7" class="text-muted p-3">No requests yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted py-2">Kept for 30 days. Changes made through the API are also in the <a href="/audit">audit log</a>.</div>
</div>

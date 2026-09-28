<?php
use Align\Api\Keys;

/** Settings -> API -> one key. @var array $apiKey (decoded); array $clients, $requests */
$tab = 'api';
require __DIR__ . '/_tabs.php';
$st = Keys::state($apiKey);
$revoked = $st === 'revoked';
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h2 class="h5 mb-0 mr-auto"><a href="/settings/api" class="text-muted mr-2"><i class="fas fa-arrow-left"></i></a><i class="fas fa-key text-secondary mr-2"></i><?= e($apiKey['name']) ?>
    <span class="badge badge-<?= ['active' => 'success', 'expiring' => 'warning', 'expired' => 'secondary', 'revoked' => 'dark', 'owner_inactive' => 'danger'][$st] ?> ml-1"><?= ['active' => 'Active', 'expiring' => 'Expires soon', 'expired' => 'Expired', 'revoked' => 'Revoked', 'owner_inactive' => 'Stopped: creator disabled'][$st] ?></span></h2>
  <span class="small text-muted"><code>msa_<?= e($apiKey['prefix']) ?>_…</code> · created <?= e(fmt_date($apiKey['created_at'])) ?><?= $apiKey['created_by_name'] ? ' by ' . e($apiKey['created_by_name']) : '' ?>
    · last used <?= $apiKey['last_used_at'] ? e(rel_time($apiKey['last_used_at'])) . ' from ' . e($apiKey['last_ip']) : 'never' ?></span>
</div>

<div class="row">
  <div class="col-xl-8">
    <?php if ($revoked): ?>
      <div class="alert alert-light border">Revoked <?= e(rel_time($apiKey['revoked_at'])) ?>. It can't be used or changed any more.</div>
    <?php else: ?>
    <form method="post" action="/settings/api/keys/<?= (int) $apiKey['id'] ?>" class="card card-body" data-api-form>
      <?= csrf_field() ?>
      <?php $formKey = $apiKey; require __DIR__ . '/_api_key_form.php'; ?>
      <div><button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save</button> <span class="small text-muted ml-2">The key itself doesn't change; only what it may do.</span></div>
    </form>
    <?php endif; ?>
  </div>
  <div class="col-xl-4">
    <div class="card card-outline card-danger"><div class="card-body">
      <?php if (!$revoked): ?>
        <h3 class="h6">Revoke</h3>
        <p class="small text-muted">Stops the key working immediately. Do this if it may have leaked, or the tool using it is retired.</p>
        <form method="post" action="/settings/api/keys/<?= (int) $apiKey['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="revoke">
          <button class="btn btn-sm btn-outline-danger" data-confirm="Revoke <?= e($apiKey['name']) ?>? Anything using it stops working now."><i class="fas fa-ban mr-1"></i>Revoke key</button></form>
      <?php else: ?>
        <h3 class="h6">Delete</h3>
        <p class="small text-muted">Removes the revoked key from the list. The audit log keeps what it did.</p>
        <form method="post" action="/settings/api/keys/<?= (int) $apiKey['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <button class="btn btn-sm btn-outline-danger" data-confirm="Delete <?= e($apiKey['name']) ?> for good?"><i class="fas fa-trash mr-1"></i>Delete key</button></form>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list mr-2"></i>Requests from this key</h3></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-striped table-borderless mb-0 small">
      <thead class="text-dark"><tr><th>When</th><th>Request</th><th>Status</th><th class="text-right">ms</th><th>IP</th><th>Request id</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $r): ?>
        <tr><td class="text-nowrap" title="<?= e(fmt_datetime($r['created_at'])) ?>"><?= e(rel_time($r['created_at'])) ?></td><td class="text-monospace"><?= e($r['method'] . ' ' . $r['path']) ?></td>
          <td><span class="badge badge-<?= $r['status'] >= 500 ? 'danger' : ($r['status'] >= 400 ? 'warning' : 'success') ?>"><?= (int) $r['status'] ?></span><?= $r['error_code'] ? ' <span class="text-muted">' . e($r['error_code']) . '</span>' : '' ?></td>
          <td class="text-right"><?= (int) $r['ms'] ?></td><td><?= e($r['ip']) ?></td><td class="text-monospace text-muted"><?= e($r['request_id']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$requests): ?><tr><td colspan="6" class="text-muted p-3">No requests yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

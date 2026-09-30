<?php
use Align\Api\Keys;

/**
 * Fields for creating or editing an API key.
 * @var array $clients; ?array $formKey (decoded key when editing; null when creating)
 */
$k = $formKey ?? null;
$scopes = array_flip($k['scope_list'] ?? Keys::preset('read_all'));
$limited = $k && $k['client_list'] !== null;
$picked = array_flip($k['client_list'] ?? []);
?>
<div class="row g-2">
  <div class="mb-3 col-md-6"><label for="api-name">Name</label>
    <input class="form-control" id="api-name" name="name" maxlength="120" required value="<?= e($k['name'] ?? '') ?>" placeholder="e.g. n8n billing workflow">
    <small class="form-text text-muted">Shown in the audit log next to every change the key makes.</small></div>
  <div class="mb-3 col-md-6"><label for="api-notes">Notes <small class="text-muted">(optional)</small></label>
    <input class="form-control" id="api-notes" name="notes" maxlength="500" value="<?= e($k['notes'] ?? '') ?>" placeholder="Where it's used and who looks after it"></div>
</div>

<label class="d-block">Permissions</label>
<div class="mb-2" role="group" aria-label="Presets">
  <?php foreach (Keys::PRESETS as $p => [$pl]): ?>
    <button type="button" class="btn btn-xs btn-outline-secondary me-1 mb-1" data-api-preset="<?= e(implode(' ', Keys::preset($p))) ?>"><?= e($pl) ?></button>
  <?php endforeach; ?>
  <button type="button" class="btn btn-xs btn-outline-secondary mb-1" data-api-preset="">Clear</button>
</div>
<div class="table-responsive mb-3">
  <table class="table table-sm table-bordered mb-0 api-scopes">
    <thead class="table-light"><tr><th>Area</th><th class="text-center" style="width:80px">Read</th><th class="text-center" style="width:80px">Write</th><th>What write allows</th></tr></thead>
    <tbody>
    <?php foreach (Keys::AREAS as $area => [$label, $readDesc, $writeDesc]): ?>
      <tr>
        <td><b><?= e($label) ?></b><div class="small text-muted"><?= e($readDesc) ?></div></td>
        <td class="text-center align-middle"><input type="checkbox" name="scopes[]" value="<?= $area ?>:read" data-api-scope="<?= $area ?>" data-api-read <?= isset($scopes["$area:read"]) ? 'checked' : '' ?> aria-label="<?= e($label) ?> read"></td>
        <td class="text-center align-middle"><?php if ($writeDesc !== null): ?><input type="checkbox" name="scopes[]" value="<?= $area ?>:write" data-api-scope="<?= $area ?>" data-api-write <?= isset($scopes["$area:write"]) ? 'checked' : '' ?> aria-label="<?= e($label) ?> write"><?php else: ?><span class="text-muted small">read only</span><?php endif; ?></td>
        <td class="small text-muted align-middle"><?= $writeDesc !== null ? e($writeDesc) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="row g-2">
  <div class="mb-3 col-lg-6">
    <label class="d-block">Clients</label>
    <div class="form-check form-check-inline"><input type="radio" class="form-check-input" id="cs-all" name="client_scope" value="all" <?= $limited ? '' : 'checked' ?>><label class="form-check-label" for="cs-all">All clients</label></div>
    <div class="form-check form-check-inline"><input type="radio" class="form-check-input" id="cs-some" name="client_scope" value="some" <?= $limited ? 'checked' : '' ?>><label class="form-check-label" for="cs-some">Only these clients</label></div>
    <div id="api-clients" class="mt-2<?= $limited ? '' : ' d-none' ?>">
      <input type="search" class="form-control form-control-sm mb-1 filter-input" data-filter-table="api-client-list" placeholder="Find a client…" aria-label="Find a client">
      <ul class="list-group api-client-list" id="api-client-list" style="max-height:220px;overflow:auto">
        <?php foreach ($clients as $c): ?>
          <li class="list-group-item py-1 small"><div class="form-check "><input type="checkbox" class="form-check-input" id="acl-<?= (int) $c['id'] ?>" name="client_ids[]" value="<?= (int) $c['id'] ?>" <?= isset($picked[(int) $c['id']]) ? 'checked' : '' ?>><label class="form-check-label" for="acl-<?= (int) $c['id'] ?>"><?= e($c['name']) ?></label></div></li>
        <?php endforeach; ?>
      </ul>
      <small class="form-text text-muted">Everything outside these clients answers "not found". Internal meetings and hosted-backup assignments need a key for all clients.</small>
    </div>
  </div>
  <div class="mb-3 col-lg-3 col-sm-6">
    <label for="api-exp">Expires</label>
    <select class="form-select" id="api-exp" name="expires" data-api-expires>
      <?php if ($k): ?><option value="keep" selected>Keep (<?= $k['expires_at'] ? e(fmt_date($k['expires_at'])) : 'never' ?>)</option><?php endif; ?>
      <?php foreach (['30' => 'In 30 days', '90' => 'In 90 days', '180' => 'In 6 months', '365' => 'In 1 year', 'never' => 'Never', 'date' => 'On a date…'] as $v => $l): ?>
        <option value="<?= $v ?>" <?= !$k && $v === '365' ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" class="form-control mt-1 d-none" name="expires_on" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" aria-label="Expiry date" data-api-expires-on>
    <small class="form-text text-muted">Admins see a warning on the dashboard two weeks before.</small>
  </div>
  <div class="mb-3 col-lg-3 col-sm-6">
    <label for="api-rate">Rate limit</label>
    <div class="input-group"><input type="number" class="form-control" id="api-rate" name="rate_limit" min="1" max="<?= Keys::MAX_RATE ?>" value="<?= (int) ($k['rate_limit'] ?? 120) ?>"><span class="input-group-text">per min</span></div>
    <small class="form-text text-muted">Extra requests get 429 with Retry-After.</small>
  </div>
</div>

<?php
/**
 * The client add/edit form (techs; the controller checks the role). @var ?array $c; array $users. Values are
 * escaped; fields synced from the PSA are shown read-only.
 */
use Align\Controllers\ClientController;
use Align\Meetings\Meetings;

$c = $c ?? null; // null = new client
$manual = !$c || $c['source'] === 'manual';
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
$psaFields = array_flip(array_filter(explode(',', (string) ($c['psa_fields'] ?? ''))));
// Text input that becomes read-only when the value comes from the PSA
$field = function (string $name, string $label, string $type = 'text', string $placeholder = '') use ($c, $psaFields) {
    $synced = isset($psaFields[$name]);
    return '<label>' . e($label) . ($synced ? ' <span class="badge text-bg-light border fw-normal" title="Synced from ' . psa_name() . '; edit it there">' . psa_name() . '</span>' : '') . '</label>'
        . '<input type="' . $type . '" name="' . $name . '" class="form-control" value="' . e($c[$name] ?? '') . '"' . ($synced ? ' readonly' : '')
        . ($placeholder ? ' placeholder="' . e($placeholder) . '"' : '') . '>';
};
?>
<div class="modal fade" id="modal-client" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $c ? '/clients/' . (int) $c['id'] : '/clients' ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-user-plus me-2"></i><?= $c ? 'Edit ' . e($c['name']) : 'New client' ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (!$manual): ?>
            <div class="alert alert-light border small py-2"><i class="fas fa-circle-info me-1"></i>This client syncs from <?= e(psa_name()) ?>. The name, and anything marked <span class="badge text-bg-light border"><?= e(psa_name()) ?></span>, comes from its primary contact and primary location and is updated automatically, so edit those in <?= e(psa_name()) ?>. Anything empty in <?= e(psa_name()) ?> can be filled in here.</div>
          <?php endif; ?>
          <div class="row g-2">
            <div class="mb-3 col-md-8">
              <label>Client name</label>
              <input name="name" class="form-control" value="<?= e($c['name'] ?? '') ?>" <?= $manual ? 'required' : 'readonly' ?>>
            </div>
            <div class="mb-3 col-md-4">
              <label>Industry</label>
              <select name="industry" class="form-select">
                <option value="">—</option>
                <?php foreach (ClientController::INDUSTRIES as $i): ?><option <?= $sel($i, $c['industry'] ?? '') ?>><?= e($i) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><?= $field('contact_name', 'Primary contact') ?></div>
            <div class="mb-3 col-md-4"><?= $field('contact_title', 'Title', 'text', 'Office manager, Owner…') ?></div>
            <div class="mb-3 col-md-4"><?= $field('contact_email', 'Email', 'email') ?></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><?= $field('contact_phone', 'Contact phone') ?></div>
            <div class="mb-3 col-md-4"><?= $field('contact_mobile', 'Mobile') ?></div>
            <div class="mb-3 col-md-4"><?= $field('main_phone', 'Main office phone') ?></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-6"><?= $field('website', 'Website') ?></div>
            <div class="mb-3 col-md-3">
              <label>Meeting cadence</label>
              <select name="meeting_cadence" class="form-select">
                <?php foreach (Meetings::CADENCES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $c['meeting_cadence'] ?? 'annual') ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3 col-md-3">
              <label>vCIO / account lead</label>
              <select name="vcio_user_id" class="form-select">
                <option value="">—</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $sel($u['id'], $c['vcio_user_id'] ?? '') ?>><?= e($u['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label>Logo <small class="text-muted">(PNG, JPG, WebP or GIF, up to 5 MB; shown on the client's pages and printed reports)</small></label>
            <div class="d-flex align-items-center">
              <?php $logo = $c ? client_logo_url($c) : null; ?>
              <div class="client-logo-preview me-3<?= $logo ? '' : ' is-empty' ?>" data-logo-preview>
                <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="Current logo"><?php else: ?><span><?= e(initials($c['name'] ?? '?')) ?></span><?php endif; ?>
              </div>
              <div class="flex-grow-1">
                <div>
                  <input type="file" class="form-control" id="client-logo-<?= (int) ($c['id'] ?? 0) ?>" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" data-logo-input>
                  
                </div>
                <?php if ($logo): ?>
                  <div class="form-check mt-1">
                    <input type="checkbox" class="form-check-input" id="remove-logo-<?= (int) $c['id'] ?>" name="remove_logo" value="1">
                    <label class="form-check-label fw-normal small" for="remove-logo-<?= (int) $c['id'] ?>">Remove logo</label>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="mb-3"><label>Address<?= isset($psaFields['address']) ? ' <span class="badge text-bg-light border fw-normal" title="Primary location in ' . psa_name() . '">' . psa_name() . '</span>' : '' ?></label><textarea name="address" class="form-control" rows="2" <?= isset($psaFields['address']) ? 'readonly' : '' ?>><?= e($c['address'] ?? '') ?></textarea></div>
          <div class="mb-3 mb-0"><label>Notes</label><textarea name="notes" class="form-control" rows="3"><?= e($c['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-check me-1"></i><?= $c ? 'Save' : 'Create client' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

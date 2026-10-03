<?php
use Align\Portal\PortalAuth;

/** Permission checkboxes. @var ?array $u existing portal user (null = defaults for a new one); $pid unique id prefix (fixed by the caller) */
$u = $u ?? null;
$def = ['can_roadmap' => 1, 'can_budget' => 0, 'can_devices' => 1, 'can_documents' => 1, 'can_approve' => 0, 'can_submit' => 1, 'can_contacts' => 0];
$val = fn(string $k) => $u ? (int) $u[$k] : $def[$k];
$hints = [
    'can_roadmap' => 'Roadmap, projects and replacement timeline',
    'can_budget' => 'Budget, licensing and costs everywhere (device and project prices)',
    'can_devices' => 'Device list, health and compliance scores',
    'can_documents' => 'Shared documents, contacts and meetings',
    'can_approve' => 'Needs Roadmap & projects',
    'can_submit' => 'Needs Budget & licensing. You review each one before it\'s added',
    'can_contacts' => 'Needs Documents, contacts & meetings. Contacts themselves are view-only',
];
?>
<div class="row">
  <div class="col-md-7">
    <div class="small fw-bold text-muted text-uppercase mb-1">Can see</div>
    <?php foreach (PortalAuth::SECTIONS as $k => $label): ?>
      <div class="form-check mb-1"><input type="checkbox" class="form-check-input" id="<?= $pid ?>-<?= $k ?>" name="<?= $k ?>" value="1" <?= $val($k) ? 'checked' : '' ?>>
        <label class="form-check-label fw-normal" for="<?= $pid ?>-<?= $k ?>"><?= e($label) ?> <span class="small text-muted d-block"><?= e($hints[$k]) ?></span></label></div>
    <?php endforeach; ?>
  </div>
  <div class="col-md-5">
    <div class="small fw-bold text-muted text-uppercase mb-1">Can do</div>
    <?php foreach (PortalAuth::ACTIONS as $k => $label): ?>
      <div class="form-check mb-1"><input type="checkbox" class="form-check-input" id="<?= $pid ?>-<?= $k ?>" name="<?= $k ?>" value="1" <?= $val($k) ? 'checked' : '' ?>>
        <label class="form-check-label fw-normal" for="<?= $pid ?>-<?= $k ?>"><?= e($label) ?> <span class="small text-muted d-block"><?= e($hints[$k]) ?></span></label></div>
    <?php endforeach; ?>
  </div>
</div>

<?php
use Align\Portal\PortalAuth;

/** Permission checkboxes. @var ?array $u existing portal user (null = defaults for a new one); $pid unique id prefix (fixed by the caller) */
$u = $u ?? null;
$def = ['can_roadmap' => 1, 'can_budget' => 0, 'can_devices' => 1, 'can_documents' => 1, 'can_approve' => 0, 'can_submit' => 1, 'can_contacts' => 0, 'can_closet' => 0, 'can_checkout' => 0, 'can_closet_edit' => 0, 'can_tickets' => 0, 'can_quotes' => 0, 'can_psa_docs' => 0, 'can_all_tickets' => 0, 'can_pay_bills' => 0];
$val = fn(string $k) => $u ? (int) $u[$k] : $def[$k];
$hints = [
    'can_roadmap' => 'Roadmap, projects and replacement timeline',
    'can_budget' => 'Budget, licensing and costs everywhere (device and project prices)',
    'can_devices' => 'Device list, health and compliance scores',
    'can_documents' => 'Shared documents, contacts and meetings',
    'can_approve' => 'Needs Roadmap & projects',
    'can_submit' => 'Needs Budget & licensing. You review each one before it\'s added',
    'can_contacts' => 'Needs Documents, contacts & meetings. Contacts themselves are view-only',
    'can_closet' => 'Spare parts and equipment kept for them, spare devices, and who has what',
    'can_checkout' => 'Needs Tech closet',
    'can_closet_edit' => 'Needs Tech closet. Add items, fix details, update counts and remove what\'s gone; you see who changed what',
    // 2.35.0 the PSA's tickets and quotes (ITFlow)
    'can_tickets' => 'Their tickets in ' . psa_name() . ', with the replies sent to them; open a new ticket, and reply on ' . psa_name() . '\'s page for it',
    'can_quotes' => psa_name() . ' quotes sent to their company; they accept or decline on ' . psa_name() . '\'s page for the quote',
    'can_psa_docs' => 'Needs Documents, contacts & meetings. The documents ' . psa_name() . ' shows to this client (its client-visible setting), under From ' . psa_name() . ' on the Documents page',
    'can_pay_bills' => \Align\Portal\Support::billingUrl() !== null ? 'For whoever pays your invoices. Opens your bill pay page (Integrations → ITFlow) in a new tab'
        : 'Set the bill pay address under Integrations → ITFlow first; until then the button doesn\'t show',
    'can_all_tickets' => 'Needs Tickets. For the owner or office manager; others see the tickets they opened or that are for them',
];
// 2.35.0 tickets and quotes only with a PSA that has them in the portal (ITFlow)
$psaPortal = \Align\Portal\Support::available();
?>
<div class="row">
  <div class="col-md-7">
    <div class="small fw-bold text-muted text-uppercase mb-1">Can see</div>
    <?php foreach (PortalAuth::SECTIONS as $k => $label): if (!$psaPortal && in_array($k, ['can_tickets', 'can_quotes', 'can_psa_docs'], true)) { continue; } ?>
      <div class="form-check mb-1"><input type="checkbox" class="form-check-input" id="<?= $pid ?>-<?= $k ?>" name="<?= $k ?>" value="1" <?= $val($k) ? 'checked' : '' ?>>
        <label class="form-check-label fw-normal" for="<?= $pid ?>-<?= $k ?>"><?= e($label) ?> <span class="small text-muted d-block"><?= e($hints[$k]) ?></span></label></div>
    <?php endforeach; ?>
  </div>
  <div class="col-md-5">
    <div class="small fw-bold text-muted text-uppercase mb-1">Can do</div>
    <?php foreach (PortalAuth::ACTIONS as $k => $label): if (!$psaPortal && $k === 'can_all_tickets') { continue; } ?>
      <div class="form-check mb-1"><input type="checkbox" class="form-check-input" id="<?= $pid ?>-<?= $k ?>" name="<?= $k ?>" value="1" <?= $val($k) ? 'checked' : '' ?>>
        <label class="form-check-label fw-normal" for="<?= $pid ?>-<?= $k ?>"><?= e($label) ?> <span class="small text-muted d-block"><?= e($hints[$k]) ?></span></label></div>
    <?php endforeach; ?>
  </div>
</div>

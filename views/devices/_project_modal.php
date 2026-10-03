<?php
/**
 * "Make projects" (2.1): devices due for replacement become projects on the roadmap, one per device or one for
 * several, with a QUOTE- ticket in the PSA. On the Devices page it takes the ticked devices; on a device's page, $device.
 * @var array $client; ?array $device (one device) ; string $back (where to return)
 * Techs and admins. The confirm texts go into data-confirm-rules as escaped JSON (never an inline script).
 */
use Align\Providers\Providers;
use Align\Roadmap\Plan;

$cid = (int) $client['id'];
$device = $device ?? null;
$ticketOn = !empty($client['psa_id']) && Providers::psaSupports('tickets.create');
$bulkSel = '[name="ids[]"][form="bulk-replace"]';
$plan = 'leave the automatic replacement plan: the project\'s quarter and cost count on the roadmap and in the budget instead.';
$rules = $device
    ? [['title' => 'Make a project to replace ' . $device['name'] . '?', 'text' => 'It will ' . $plan, 'ok' => 'Make the project']]
    : [['count' => 'input[data-copied]', 'is' => ['pick_n' => '1'], 'title' => 'Make a project for this device?', 'text' => 'It will ' . $plan, 'ok' => 'Make the project'],
       ['count' => 'input[data-copied]', 'min' => 2, 'when' => 'input[name="mode"][value="each"]:checked', 'title' => 'Make {n} projects, one per device?', 'text' => 'The devices ' . str_replace("project's quarter and cost", "projects' quarters and costs", $plan), 'ok' => 'Make projects'],
       ['count' => 'input[data-copied]', 'min' => 2, 'when' => 'input[name="mode"][value="together"]:checked', 'title' => 'Make one project for {n} devices?', 'text' => 'The devices ' . $plan, 'ok' => 'Make the project']];
?>
<div class="modal fade" id="modal-make-project" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="/clients/<?= $cid ?>/devices/projects" data-currency="<?= e(strtoupper((string) \Align\Settings::get('locale_currency', 'USD'))) ?>"
        <?= $device ? 'data-fixed-count="1"' : 'data-project-from="' . e($bulkSel) . '"' ?>
        data-confirm-rules="<?= e(json_encode($rules)) ?>">
        <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
        <?php if ($device): ?><input type="hidden" name="ids[]" value="<?= (int) $device['id'] ?>"><?php else: ?><input type="hidden" name="pick_n" value="0" data-pick-n><?php endif; ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-diagram-project me-2"></i><?= $device ? 'Make a project' : 'Make projects' ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">
            <?php if ($device): ?>
              Replace <b><?= e($device['name']) ?></b>, budgeted at <b><?= e(money($device['replacement_cost'])) ?></b>.
            <?php else: ?>
              <b data-pick-count>0</b> device<span data-pick-s>s</span>, budgeted at <b data-pick-total>$0</b> in all.
            <?php endif; ?>
            <span class="d-block small text-muted mt-1">Once the client approves, the project replaces them on the roadmap and in the budget, so nothing counts twice. Decline or delete it and they go back to their replacement dates.</span>
          </p>
          <?php if (!$device): ?>
          <div class="mb-3 d-none" data-pick-many>
            <div class="form-check"><input class="form-check-input" type="radio" name="mode" value="each" id="mp-each" checked>
              <label class="form-check-label" for="mp-each">One project per device <span class="text-muted small">(quoted separately)</span></label></div>
            <div class="form-check"><input class="form-check-input" type="radio" name="mode" value="together" id="mp-together">
              <label class="form-check-label" for="mp-together">One project for all of them <span class="text-muted small">(replaced together)</span></label></div>
          </div>
          <?php endif; ?>
          <div class="row g-2<?= $device ? '' : ' d-none' ?>" data-pick-one>
            <div class="mb-3 col-md-8"><label for="mp-title">Project</label>
              <input id="mp-title" name="title" class="form-control" maxlength="255" placeholder="<?= e($device ? \Align\Roadmap\DeviceProjects::title([$device]) : 'Filled in for you, e.g. Replace 3 laptops') ?>"></div>
            <div class="mb-3 col-md-4"><label for="mp-cost">Cost</label>
              <input id="mp-cost" name="cost" type="number" min="0" step="0.01" class="form-control" placeholder="Budgeted" title="Leave empty for the budgeted cost, or type the quote amount"></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-8"><label for="mp-quarter">Quarter</label>
              <select id="mp-quarter" name="quarter" class="form-select">
                <option value=""><?= $device ? 'Its replacement quarter' . ($device['replace_due'] ? ' (' . e((Plan::quarterFor(max($device['replace_due'], date('Y-m-d')))['label'] ?? '')) . ')' : '') : 'Each device\'s replacement quarter' ?></option>
                <?php foreach (Plan::choices(5) as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
              </select></div>
            <div class="mb-3 col-md-4"><label for="mp-status">Status</label>
              <select id="mp-status" name="status" class="form-select">
                <option value="approved">Approved</option><option value="scheduled">Scheduled</option><option value="proposed">Proposed</option>
              </select></div>
          </div>
          <div class="mb-3"><label for="mp-note">Note <span class="text-muted small">(optional)</span></label>
            <textarea id="mp-note" name="note" class="form-control" rows="2" maxlength="2000" placeholder="e.g. Client approved on the call, quote by Friday"></textarea></div>
          <?php if ($ticketOn): ?>
            <div class="form-check">
              <input type="checkbox" class="form-check-input" id="mp-ticket" name="ticket" value="1" checked>
              <label class="form-check-label" for="mp-ticket">Create a <b>QUOTE-</b> ticket in <?= e(psa_name()) ?> for each project</label>
              <div class="small text-muted">With the devices and budget in it, and no client contact, so it's a new ticket for your team to assign.</div>
            </div>
          <?php elseif (Providers::psaSupports('tickets.create')): ?>
            <div class="small text-muted"><?= e($client['name']) ?> isn't linked to <?= e(psa_name()) ?>, so no quote ticket is created.</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" data-pick-submit><?= $device ? 'Make the project' : 'Make projects' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
/**
 * The project window (add or edit), on the roadmap, the client overview and the Projects page (loaded by
 * FormController::project for one project). Shown to techs and admins only.
 * @var ?array $it project (null = new); int $cid client id; ?array $pickClients [id => name] to choose from; ?string $back
 * Every value from the database is escaped; the PSA ticket link comes from Providers::psaLink (the PSA's own https
 * address). $back is checked again by the controller (Security::safePath). 2.2.2: the ticket strip (Roadmap\ProjectTickets)
 * and, for a new project, "Make the QUOTE- ticket now" (off by default).
 */
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;

$it = $it ?? null;
$id = $it ? 'modal-roadmap-' . (int) $it['id'] : 'modal-roadmap';
$pickClients = $pickClients ?? null;   // global Projects page: [id => name] to choose the client
$back = $back ?? null;                 // where to return after saving
$action = $pickClients && !$it ? '/projects' : '/clients/' . (int) ($it['client_id'] ?? $cid) . '/roadmap' . ($it ? '/' . (int) $it['id'] : '');
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
$planQs = Plan::quarters();
$planEnd = $planQs[count($planQs) - 1]['end'];
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= e($action) ?>" data-unsaved>
        <?= csrf_field() ?>
        <?php if ($back): ?><input type="hidden" name="back" value="<?= e($back) ?>"><?php endif; ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-road me-2"></i><?= $it ? 'Edit project' : 'Add project' ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($it && !empty($it['decided_by_name'])): ?>
            <div class="alert alert-<?= $it['status'] === 'declined' ? 'secondary' : 'success' ?> small py-2"><i class="fas fa-door-open me-1"></i>
              <b><?= $it['status'] === 'declined' ? 'Declined' : 'Approved' ?> by <?= e($it['decided_by_name']) ?></b> (client portal) on <?= e(fmt_date($it['decided_at'])) ?>.
              <?php if ($it['decision_comment']): ?><div class="mt-1">&ldquo;<?= e($it['decision_comment']) ?>&rdquo;</div><?php endif; ?></div>
          <?php endif; ?>
          <?php if ($pickClients && !$it): ?>
            <div class="mb-3"><label>Client</label>
              <select name="client_id" class="form-select" required>
                <option value="">Choose a client…</option>
                <?php foreach ($pickClients as $pcId => $pcName): ?><option value="<?= (int) $pcId ?>" <?= (int) $pcId === (int) $cid ? 'selected' : '' ?>><?= e($pcName) ?></option><?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <div class="row g-2">
            <div class="mb-3 col-md-8"><label>Project</label><input name="title" class="form-control" required value="<?= e($it['title'] ?? '') ?>" placeholder="e.g. Replace firewall, Move file server to SharePoint, Add MDR"></div>
            <div class="mb-3 col-md-4"><label>Category</label>
              <select name="category" class="form-select">
                <?php foreach (Roadmap::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $it['category'] ?? 'project') ?>><?= e($label) ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label>Target quarter</label>
              <select name="target_quarter" class="form-select quarter-select">
                <option value="">Unscheduled</option>
                <?php $curYear = null; foreach (Plan::quarters() as $q): if ($q['past'] && ($it['target_quarter'] ?? '') !== $q['start']) continue; ?>
                  <?php if ($curYear !== $q['year_label']): if ($curYear !== null) echo '</optgroup>'; $curYear = $q['year_label']; echo '<optgroup label="' . e($q['year_label']) . '">'; endif; ?>
                  <option value="<?= e($q['start']) ?>" <?= $sel($q['start'], $it['target_quarter'] ?? '') ?>><?= e($q['label']) ?> (<?= e($q['months']) ?>)</option>
                <?php endforeach; if ($curYear !== null) echo '</optgroup>'; ?>
                <?php // 2.2.1: a quarter the list doesn't have (beyond the plan, or before it began) stays chosen; saving the window used to unschedule an overdue project from last year
                if ($it && $it['target_quarter'] && !in_array($it['target_quarter'], array_column($planQs, 'start'), true)): ?>
                  <option value="<?= e($it['target_quarter']) ?>" selected><?= e(fmt_date($it['target_quarter'])) ?> (<?= $it['target_quarter'] > $planEnd ? 'beyond plan' : 'passed' ?>)</option>
                <?php endif; ?>
              </select></div>
            <div class="mb-3 col-md-4"><label>Priority</label>
              <select name="priority" class="form-select"><?php foreach (Roadmap::PRIORITIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $it['priority'] ?? 'medium') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3 col-md-4"><label>Status</label>
              <select name="status" class="form-select"><?php foreach (Roadmap::STATUSES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($k, $it['status'] ?? 'proposed') ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-6"><label>Budget <small class="text-muted">(one-time)</small></label>
              <div class="input-group"><span class="input-group-text"><?= e(\Align\Fmt::symbol()) ?></span><input type="number" min="0" step="any" name="cost" class="form-control" value="<?= e($it['cost'] ?? '') ?>"></div></div>
            <div class="mb-3 col-md-6"><label>Recurring cost <small class="text-muted">(optional)</small></label>
              <div class="input-group"><span class="input-group-text"><?= e(\Align\Fmt::symbol()) ?></span><input type="number" min="0" step="1" name="recurring_monthly" class="form-control" value="<?= e($it['recurring_monthly'] ?? '') ?>"><span class="input-group-text">/ month</span></div></div>
          </div>
          <?php if ($it && ($pd = \Align\Roadmap\DeviceProjects::devicesFor((int) $it['id']))): ?>
            <div class="mb-3 small border rounded p-2 bg-body-tertiary">
              <div class="fw-bold mb-1"><i class="fas fa-desktop me-1 text-secondary"></i>Replaces <?= count($pd) ?> device<?= count($pd) === 1 ? '' : 's' ?>
                <span class="fw-normal text-muted">· they're out of the automatic replacement plan while this project isn't declined</span></div>
              <?= implode(', ', array_map(fn($x) => '<a href="/devices/' . (int) $x['id'] . '">' . e($x['name']) . '</a>' . ($x['removed_at'] ? ' <span class="text-muted">(retired)</span>' : ''), $pd)) ?>
            </div>
          <?php endif; ?>
          <?php if ($it && ($ts = \Align\Roadmap\ProjectTickets::state($it))['key'] !== 'off'):
              // 2.2.2: where the project stands with its one QUOTE- ticket; Ready to start opens the confirm window
              $tu = $ts['key'] === 'ticket' ? \Align\Providers\Providers::psaLink('ticket', (string) $it['psa_ticket_id']) : null;
              $tBy = $ts['key'] === 'ticket' ? \Align\Roadmap\ProjectTickets::userName(isset($it['ticket_by']) ? (int) $it['ticket_by'] : null) : null; ?>
            <?php if ($ts['key'] === 'ticket'): ?>
              <div class="mb-3 small border rounded p-2 bg-success-subtle border-success-subtle text-success-emphasis d-flex align-items-center gap-2" data-ticket-state="ticket"><i class="fas fa-ticket"></i>
                <div><b>Ticket <?= $tu ? '<a href="' . e($tu) . '" target="_blank" rel="noopener">' . e($ts['text']) . '</a>' : e($ts['text']) ?></b>
                  <?php if (!empty($it['ticket_at'])): ?><div>Made <?= e(fmt_date($it['ticket_at'])) ?><?= $tBy ? ' by ' . e((string) $tBy) : '' ?></div><?php endif; ?></div></div>
            <?php elseif ($ts['startable']): ?>
              <div class="mb-3 small border rounded p-2 bg-primary-subtle border-primary-subtle text-primary-emphasis d-flex flex-wrap align-items-center gap-2" data-ticket-state="<?= e($ts['key']) ?>"><i class="fas fa-ticket"></i>
                <div class="me-auto"><b>Ticket: not yet</b>
                  <div><?= e(match ($ts['key']) {
                      'due' => 'Its quarter is here: it\'s on To do now.',
                      'later' => 'Goes on To do ' . fmt_date($it['target_quarter']) . ', when its quarter starts. Or start it now.',
                      'snoozed' => 'Back on To do ' . fmt_date($it['ticket_snooze_until']) . ' (Not yet). Or start it now.',
                      'proposed' => 'Goes on To do once it\'s approved and its quarter is here. Or start it now.',
                      default => 'Goes on To do once it has a quarter and that quarter is here. Or start it now.',
                  }) ?></div></div>
                <a href="#" class="btn btn-sm btn-outline-primary" data-lazy-modal="/projects/<?= (int) $it['id'] ?>/start?back=<?= e(rawurlencode($back ?? '/clients/' . (int) $it['client_id'] . '/roadmap')) ?>" data-bs-target="#modal-start-<?= (int) $it['id'] ?>">Ready to start</a>
              </div>
            <?php elseif ($ts['key'] !== 'closed'): ?>
              <div class="mb-3 small text-muted" data-ticket-state="<?= e($ts['key']) ?>"><i class="fas fa-ticket me-1"></i>Ticket: <?= e($ts['text']) ?></div>
            <?php endif; ?>
          <?php endif; ?>
          <?php $cPsa = $it || $pickClients ? null : (string) \Align\DB::value('SELECT psa_id FROM clients WHERE id = ?', [(int) $cid]);
          if (!$it && \Align\Roadmap\ProjectTickets::enabled() && $cPsa === ''): ?>
            <div class="mb-3 small text-muted"><i class="fas fa-ticket me-1"></i>This client isn't linked to <?= e(psa_name()) ?>, so its projects get no ticket.</div>
          <?php elseif (!$it && \Align\Roadmap\ProjectTickets::enabled()): ?>
            <div class="mb-3 border rounded p-2">
              <div class="form-check mb-0">
                <input type="checkbox" class="form-check-input" id="<?= e($id) ?>-ticket" name="ticket" value="1">
                <label class="form-check-label fw-bold" for="<?= e($id) ?>-ticket">Make the QUOTE- ticket now</label>
              </div>
              <div class="small text-muted ms-4">Leave it off to keep the ticket board clear: once the project is approved and its quarter is here, it goes on To do and <b>Ready to start</b> makes the ticket then. Only for clients linked to <?= e(psa_name()) ?>.</div>
            </div>
          <?php endif; ?>
          <div class="mb-3 mb-0"><label>Description</label><textarea name="description" class="form-control" rows="4" placeholder="Scope, why it matters to the client, dependencies…"><?= e($it['description'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <?php if ($it): ?><button class="btn btn-outline-danger me-auto" name="action" value="delete" formnovalidate data-confirm="Delete this project? It comes off the roadmap and the budget<?= $it && \Align\Roadmap\DeviceProjects::devicesFor((int) $it['id']) ? ', and its devices go back to their replacement dates' : '' ?>. This can't be undone."><i class="fas fa-trash me-1"></i>Delete</button><?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

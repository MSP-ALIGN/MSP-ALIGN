<?php
/**
 * 2.2.2 Ready to start window for one project (loaded when opened, from To do, the project window or Projects):
 * what the QUOTE- ticket will hold, then Create the ticket. Techs and admins only (ProjectTicketController::form).
 * @var array $it project with client_name, client_psa_id; array $state (ProjectTickets::state); array $devices; string $note; string $back
 * Every value is escaped; $back was checked by Security::safePath.
 */
use Align\Roadmap\Plan;
use Align\Roadmap\ProjectTickets;
use Align\Roadmap\Roadmap;

$id = (int) $it['id'];
$quarter = $it['target_quarter'] ? (Plan::quarterFor((string) $it['target_quarter'])['label'] ?? '') : 'not scheduled yet';
?>
<div class="modal fade" id="modal-start-<?= $id ?>" tabindex="-1" aria-hidden="true" aria-labelledby="modal-start-<?= $id ?>-title">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/projects/<?= $id ?>/start">
        <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title" id="modal-start-<?= $id ?>-title"><i class="fas fa-fw fa-play me-2"></i>Ready to start?</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (!$state['startable']): ?>
            <p class="mb-0"><b><?= e($it['title']) ?></b> for <?= e($it['client_name']) ?>:
              <?= e($state['key'] === 'ticket' ? 'it has its ticket already (' . $state['text'] . ').' : ($state['key'] === 'off' ? 'tickets can\'t be made here.' : $state['text'] . '.')) ?></p>
          <?php else: ?>
            <p><b><?= e($it['title']) ?></b> for <b><?= e($it['client_name']) ?></b> goes to your team's board as one ticket in <?= e(psa_name()) ?>:</p>
            <dl class="row small border rounded bg-body-tertiary mx-0 py-2 mb-3">
              <dt class="col-sm-3 text-muted fw-normal">Subject</dt><dd class="col-sm-9 fw-bold"><?= e(mb_substr('QUOTE- ' . $it['title'], 0, 250)) ?></dd>
              <dt class="col-sm-3 text-muted fw-normal">Client</dt><dd class="col-sm-9"><?= e($it['client_name']) ?> <span class="text-muted">(no contact on it, so it's a new ticket for your team to assign)</span></dd>
              <dt class="col-sm-3 text-muted fw-normal">Priority</dt><dd class="col-sm-9"><?= e(ProjectTickets::priority((string) $it['priority'])) ?></dd>
              <dt class="col-sm-3 text-muted fw-normal">In the ticket</dt>
              <dd class="col-sm-9 mb-0">
                <?= e($quarter) ?> · budget <?= e(money((float) $it['cost'])) ?> · <?= e(Roadmap::STATUSES[$it['status']][0] ?? $it['status']) ?>
                <?php if (!empty($it['decided_by_name']) && $it['status'] !== 'declined'): ?> by <?= e($it['decided_by_name']) ?> (client portal)<?php endif; ?>
                <?php if ($note !== ''): ?><div class="mt-1 text-body-secondary" style="white-space: pre-line"><?= e(mb_strimwidth($note, 0, 600, '…')) ?></div><?php endif; ?>
                <?php if ($devices): ?>
                  <div class="table-responsive mt-2"><table class="table table-sm mb-1 font-monospace small">
                    <thead><tr><th scope="col">Device</th><th scope="col">Serial</th><th scope="col">User</th></tr></thead>
                    <?php foreach ($devices as $d): ?><tr><td><?= e($d['name']) ?></td><td><?= e($d['serial'] ?? '') ?></td><td><?= e(!empty($d['last_user']) ? short_user($d['last_user']) : '') ?></td></tr><?php endforeach; ?>
                  </table></div>
                <?php endif; ?>
                <div class="mt-1">A link back to the project in Align</div>
              </dd>
            </dl>
            <p class="small text-muted mb-0">The ticket number is saved on the project and in the audit log, and the project leaves To do.
              <?php if ($state['key'] !== 'due'): ?>It isn't due on To do yet (<?= e($state['text']) ?>); you can still start it now.<?php endif; ?></p>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= $state['startable'] ? 'Cancel' : 'Close' ?></button>
          <?php if ($state['startable']): ?><button class="btn btn-primary" data-default-submit><i class="fas fa-ticket me-1"></i>Create the ticket</button><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

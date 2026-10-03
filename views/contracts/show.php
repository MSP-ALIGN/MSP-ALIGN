<?php
use Align\Auth;
use Align\Contracts\Contracts;

/**
 * A sent, signed or uploaded contract. @var array $c, $events, $clients, $me; string $preview; bool $mailReady, $clientDeleted; ?string $link
 * Every value is escaped with e(); $preview is HTML from Render (values escaped there). Buttons only mirror the
 * controller's checks, which are the real ones.
 */
$id = (int) $c['id'];
[$label, $tone] = Contracts::status($c);
$built = $c['source'] === 'built';
$open = in_array($c['status'], ['sent', 'client_signed'], true);
$signed = $c['status'] === 'completed';
$hasPdf = (bool) Contracts::pdfPath($c);
// Signed and not linked to a client: can be made a client's (an admin only, when its client was deleted)
$canLink = $signed && !$c['client_id'] && (empty($clientDeleted) || Auth::can('admin'));
$icon = ['created' => 'fa-file-circle-plus', 'sent' => 'fa-paper-plane', 'resent' => 'fa-paper-plane', 'link' => 'fa-link', 'reminder' => 'fa-bell', 'opened' => 'fa-envelope-open',
    'code_sent' => 'fa-key', 'code_ok' => 'fa-user-check', 'code_bad' => 'fa-triangle-exclamation', 'provider_signed' => 'fa-pen-nib', 'client_signed' => 'fa-signature',
    'completed' => 'fa-circle-check', 'declined' => 'fa-circle-xmark', 'void' => 'fa-ban', 'expired' => 'fa-hourglass-end', 'downloaded' => 'fa-download',
    'uploaded' => 'fa-upload', 'client_created' => 'fa-building', 'linked' => 'fa-link', 'emailed' => 'fa-envelope'];
?>
<div class="small"><a href="/contracts?show=<?= $open ? 'open' : ($signed ? 'signed' : 'closed') ?>">Contracts</a> / <?= e(Contracts::number($c)) ?></div>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-file-signature text-secondary me-2"></i><?= e($c['title']) ?> <span class="badge text-bg-<?= $tone ?> align-middle"><?= e($label) ?></span></h1>
  <?php if ($hasPdf): ?>
    <a class="btn btn-sm btn-primary me-1" href="/contracts/<?= $id ?>/pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf me-1"></i><?= $built ? 'Signed PDF' : 'Open PDF' ?></a>
  <?php elseif ($built): ?>
    <a class="btn btn-sm btn-default me-1" href="/contracts/<?= $id ?>/pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf me-1"></i>Draft PDF</a>
  <?php endif; ?>
  <?php if ($c['status'] === 'sent'): ?>
    <?php if ($mailReady): ?><form method="post" action="/contracts/<?= $id ?>/remind" class="me-1"><?= csrf_field() ?><button class="btn btn-sm btn-default" data-confirm="Email <?= e((string) $c['signer_email']) ?> a reminder with the same signing link?" data-confirm-danger="0" data-confirm-ok="Send reminder"><i class="fas fa-bell me-1"></i>Send a reminder</button></form><?php endif; ?>
    <form method="post" action="/contracts/<?= $id ?>/send" class="me-1"><?= csrf_field() ?><input type="hidden" name="action" value="link"><button class="btn btn-sm btn-default" data-confirm="Make a new signing link to send yourself? The current link stops working." data-confirm-danger="0" data-confirm-ok="Make a new link"><i class="fas fa-link me-1"></i>New link</button></form>
  <?php elseif ($c['status'] === 'expired' && $built): ?>
    <form method="post" action="/contracts/<?= $id ?>/send" class="me-1"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $mailReady ? 'send' : 'link' ?>">
      <button class="btn btn-sm btn-primary" data-confirm="<?= $mailReady ? 'Email ' . e((string) $c['signer_email']) . ' a new link?' : 'Make a new link to send yourself?' ?>" data-confirm-danger="0" data-confirm-ok="<?= $mailReady ? 'Send again' : 'Make a link' ?>"><i class="fas fa-paper-plane me-1"></i>Send again</button></form>
  <?php endif; ?>
  <?php if ($canLink): ?>
    <form method="post" action="/contracts/<?= $id ?>/client" class="me-1"><?= csrf_field() ?><button class="btn btn-sm btn-success" data-confirm="Add <?= e((string) $c['lead_company']) ?> as a client in Align, with <?= e((string) $c['signer_name']) ?> as the main contact? If a client with exactly this name is already in Align, the contract is linked to it instead." data-confirm-danger="0" data-confirm-ok="Add the client"><i class="fas fa-building me-1"></i>Add as a client</button></form>
  <?php elseif ($signed && $c['client_id'] && $built): ?>
    <a class="btn btn-sm btn-success me-1" href="/clients/<?= (int) $c['client_id'] ?>/onboarding"><i class="fas fa-mountain-sun me-1"></i>Onboarding</a>
  <?php endif; ?>
  <?php
  $canVoid = in_array($c['status'], ['sent', 'client_signed', 'expired', 'declined'], true);
  $delSigned = \Align\Controllers\ContractController::signed($c) && Auth::can('admin'); // typed confirmation, below
  $delPlain = in_array($c['status'], ['void', 'declined', 'expired'], true) && $c['source'] === 'built' && empty($c['client_signed_at']); // a cancelled one the client signed counts as signed
  if ($canVoid || $delSigned || $delPlain || $canLink): ?>
  <div class="btn-group">
    <button class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown" aria-label="More actions"><i class="fas fa-ellipsis"></i></button>
    <div class="dropdown-menu dropdown-menu-end">
      <?php if ($canLink): ?><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#modal-link-client"><i class="fas fa-fw fa-link me-1"></i>Link to an existing client</button><?php endif; ?>
      <?php if ($canVoid): ?><form method="post" action="/contracts/<?= $id ?>/void"><?= csrf_field() ?><button class="dropdown-item text-danger" data-confirm="Cancel this contract? The signing link stops working. The record and its history are kept." data-confirm-ok="Cancel contract"><i class="fas fa-fw fa-ban me-1"></i>Cancel contract</button></form><?php endif; ?>
      <?php if ($delPlain): ?><form method="post" action="/contracts/<?= $id ?>/delete"><?= csrf_field() ?><button class="dropdown-item text-danger" data-confirm="Delete this contract and its history? It was never signed." data-confirm-ok="Delete"><i class="fas fa-fw fa-trash me-1"></i>Delete</button></form><?php endif; ?>
      <?php if ($delSigned): ?><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#modal-delete-contract"><i class="fas fa-fw fa-trash me-1"></i>Delete…</button><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($link): ?>
  <div class="alert alert-success">
    <div class="fw-bold mb-1"><i class="fas fa-link me-1"></i>Signing link (shown once)</div>
    <div class="input-group"><input class="form-control select-all" id="ct-link" readonly value="<?= e($link) ?>" aria-label="Signing link"><button type="button" class="btn btn-light" data-copy="#ct-link"><i class="fas fa-copy me-1"></i>Copy</button></div>
    <div class="small mt-1">Send it to <?= e((string) $c['signer_email']) ?> from your own email. It works until <?= e(fmt_date($c['token_expires_at'])) ?>.</div>
  </div>
<?php endif; ?>
<?php if ($signed && $built && !$hasPdf): ?>
  <div class="alert alert-warning"><i class="fas fa-hourglass-half me-1"></i>Signed by everyone. The signed PDF couldn't be made yet; Align tries again within the hour<?= str_starts_with((string) $c['pdf_file'], 'pending') ? ' (it may be being made right now)' : '' ?>.</div>
<?php endif; ?>
<?php if ($c['status'] === 'declined'): ?>
  <div class="alert alert-danger"><b><?= e((string) $c['signer_name']) ?> declined</b> on <?= e(fmt_datetime($c['declined_at'])) ?><?= $c['decline_reason'] ? ': “' . e($c['decline_reason']) . '”' : '.' ?> Make a new contract to send a changed version.</div>
<?php elseif ($c['status'] === 'void'): ?>
  <div class="alert alert-secondary">Cancelled <?= e(fmt_datetime($c['voided_at'])) ?><?= $c['void_reason'] ? ': ' . e($c['void_reason']) : '' ?>.</div>
<?php endif; ?>

<div class="row">
  <div class="col-xl-7">
    <?php if ($c['status'] === 'client_signed'): ?>
      <form method="post" action="/contracts/<?= $id ?>/countersign" class="card card-warning card-outline" id="countersign">
        <?= csrf_field() ?>
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-pen-nib me-2"></i>Your countersignature</h3></div>
        <div class="card-body">
          <p class="small text-muted"><?= e((string) $c['signer_name']) ?> signed on <?= e(fmt_datetime($c['client_signed_at'])) ?>. Sign to complete the contract: the signed PDF is made, saved here and emailed to them.</p>
          <?= \Align\View::fetch('contracts/_sigpad', ['p' => 'cs', 'sigName' => (string) $me['name'], 'sigTitle' => '', 'company' => Contracts::party($c), 'photo' => $c['def']['style']['photo'] ? avatar_url($me) : null]) ?>
        </div>
        <div class="card-footer text-end"><button class="btn btn-primary"><i class="fas fa-signature me-1"></i>Sign and complete</button></div>
      </form>
    <?php endif; ?>
    <?php if ($built): ?>
      <div class="ct-paper"><?= $preview ?></div>
    <?php else: ?>
      <div class="card"><div class="card-body text-center py-5">
        <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
        <div class="fw-bold"><?= e((string) $c['pdf_name']) ?></div>
        <div class="text-muted small mb-3">Uploaded by <?= e((string) $c['created_by_name']) ?> <?= e(rel_time($c['created_at'])) ?></div>
        <?php if ($hasPdf): ?><a class="btn btn-primary" href="/contracts/<?= $id ?>/pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf me-1"></i>Open PDF</a>
          <a class="btn btn-default" href="/contracts/<?= $id ?>/pdf?download=1"><i class="fas fa-download me-1"></i>Download</a><?php else: ?><div class="text-danger">The file is missing from the server.</div><?php endif; ?>
      </div></div>
    <?php endif; ?>
  </div>
  <div class="col-xl-5">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-info me-1"></i>Details</h3></div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-5">Number</dt><dd class="col-7"><?= e(Contracts::number($c)) ?></dd>
          <dt class="col-5">For</dt><dd class="col-7"><?= $c['client_id'] ? '<a href="/clients/' . (int) $c['client_id'] . '">' . e($c['client_name']) . '</a>' : e((string) $c['lead_company']) . ' <span class="badge text-bg-light border">not a client yet</span>' ?></dd>
          <?php if ($built): ?>
            <dt class="col-5">Signer</dt><dd class="col-7"><?= e((string) $c['signer_name']) ?><?= $c['signer_title'] ? ', ' . e($c['signer_title']) : '' ?><div class="text-muted"><?= e((string) $c['signer_email']) ?></div></dd>
            <dt class="col-5">Template</dt><dd class="col-7"><?= e((string) ($c['template_name'] ?? 'deleted')) ?> (version <?= (int) $c['template_version'] ?>)</dd>
            <?php if ($open): ?><dt class="col-5">Link works until</dt><dd class="col-7"><?= e(fmt_date($c['token_expires_at'])) ?><?= $c['verify_code'] ? '<div class="text-muted">with an emailed code</div>' : '' ?></dd><?php endif; ?>
          <?php endif; ?>
          <?php if ($signed): ?>
            <dt class="col-5">Signed</dt><dd class="col-7"><?= e(fmt_date($c['signed_on'] ?: $c['completed_at'])) ?></dd>
            <?php if ($c['pdf_hash']): ?><dt class="col-5">PDF fingerprint</dt><dd class="col-7"><code class="small text-break" title="SHA-256"><?= e($c['pdf_hash']) ?></code>
              <div class="text-muted">Check any copy against it under <a href="/contracts/verify">Check a signed PDF</a>.</div></dd><?php endif; ?>
          <?php endif; ?>
        </dl>
      </div>
      <?php if ($signed): ?>
      <form method="post" action="/contracts/<?= $id ?>/details" class="card-footer">
        <?= csrf_field() ?>
        <?php if (!$built): ?>
          <div class="mb-2"><label for="cd-title" class="small">Title</label><input id="cd-title" name="title" class="form-control form-control-sm" maxlength="190" value="<?= e($c['title']) ?>"></div>
          <div class="mb-2"><label for="cd-signed" class="small">Signed on</label><input type="date" id="cd-signed" name="signed_on" class="form-control form-control-sm" value="<?= e((string) $c['signed_on']) ?>"></div>
        <?php endif; ?>
        <div class="row g-2">
          <div class="col-6 mb-2"><label for="cd-start" class="small">Starts</label><input type="date" id="cd-start" name="starts_on" class="form-control form-control-sm" value="<?= e((string) $c['starts_on']) ?>"></div>
          <div class="col-6 mb-2"><label for="cd-end" class="small">Ends / renews</label><input type="date" id="cd-end" name="ends_on" class="form-control form-control-sm" value="<?= e((string) $c['ends_on']) ?>"></div>
        </div>
        <div class="mb-2"><label for="cd-notes" class="small">Notes</label><textarea id="cd-notes" name="notes" class="form-control form-control-sm" rows="2" maxlength="4000"><?= e((string) $c['notes']) ?></textarea></div>
        <div class="text-end"><button class="btn btn-sm btn-default">Save</button></div>
      </form>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left me-1"></i>History</h3></div>
      <ul class="list-group list-group-flush small ct-history">
        <?php foreach (array_reverse($events) as $ev): ?>
          <li class="list-group-item d-flex"><i class="fas fa-fw <?= e($icon[$ev['event']] ?? 'fa-circle') ?> text-secondary me-2 mt-1"></i>
            <div><b><?= e(Contracts::EVENT_LABELS[$ev['event']] ?? $ev['event']) ?></b><?= $ev['actor'] ? ' · ' . e($ev['actor']) : '' ?>
              <div class="text-muted"><?= e(fmt_datetime($ev['created_at'])) ?><?= $ev['ip'] ? ' · ' . e($ev['ip']) : '' ?></div>
              <?php if ($ev['detail']): ?><div class="text-break"><?= e($ev['detail']) ?></div><?php endif; ?></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?php if ($canLink): ?>
<div class="modal fade" id="modal-link-client" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="/contracts/<?= $id ?>/client"><?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title">Link to an existing client</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><label for="lc-client">Client</label><select id="lc-client" name="client_id" class="form-select" required><option value="">Choose…</option>
        <?php foreach ($clients as $cl): ?><option value="<?= (int) $cl['id'] ?>"><?= e($cl['name']) ?></option><?php endforeach; ?></select></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Link</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>
<?php if ($delSigned): ?>
<div class="modal fade" id="modal-delete-contract" tabindex="-1" aria-labelledby="del-contract-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="post" action="/contracts/<?= $id ?>/delete">
      <?= csrf_field() ?>
      <div class="modal-header"><h5 class="modal-title" id="del-contract-title">Delete a signed contract</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="small">This deletes <b><?= e(\Align\Contracts\Contracts::number($c)) ?></b>, its signed PDF and its signing history from Align for good. Copies already emailed aren't affected. The audit log keeps a note of what was deleted.</p>
        <label for="del-confirm" class="small">Type <b><?= e(\Align\Contracts\Contracts::number($c)) ?></b> to confirm</label>
        <?php /* The number ("C-0001") has no regex characters. preg_quote's "\-" is invalid in a browser's (u/v-flag) pattern, which then checked nothing. */ ?>
        <input id="del-confirm" name="confirm" class="form-control" autocomplete="off" required pattern="<?= e(\Align\Contracts\Contracts::number($c)) ?>">
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Delete for good</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

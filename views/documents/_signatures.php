<?php
/**
 * 2.34.0 A client document's signatures (Docs\Signing), on the document page (written or uploaded): "Send for
 * signature", each request's status and signers, and its actions (remind, send again, cancel, countersign, the signed
 * PDF), "Needs signing again" when a signed document changed, and the send and countersign windows.
 * The actions row ([data-doc-requests]) also holds (2.34.0) "Ask staff to acknowledge", whose window and requests are
 * documents/_acknowledgments.
 * @var array $doc; bool $canEdit; array $signatures (Signing::forDocument), $signContacts (Signing::contacts);
 *   ?array $resign (Signing::needsResign); array $me (the signed-in user)
 * Every value is escaped; forms post with CSRF to DocumentSignatureController, which checks the role and the ids again.
 */
use Align\Docs\Signing;

$id = (int) $doc['id'];
$problem = Signing::problem($doc);
$countersignOpen = array_values(array_filter($signatures, fn($r) => $r['status'] === 'countersign'));
?>
<div class="card card-dark" id="signatures">
  <div class="card-header py-2 d-flex align-items-center"><h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-file-signature me-2"></i>Signatures</h3></div>
  <div class="card-body small">
    <?php if ($canEdit): ?>
      <div class="d-flex flex-wrap gap-1 mb-2" data-doc-requests>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-send-sign"><i class="fas fa-pen-nib me-1"></i>Send for signature</button>
        <button type="button" class="btn btn-sm btn-default" data-bs-toggle="modal" data-bs-target="#modal-send-ack"><i class="fas fa-user-check me-1"></i>Ask staff to acknowledge</button>
      </div>
      <?php if ($problem): ?><div class="text-muted mb-2"><?= e($problem) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if ($resign): ?>
      <div class="alert alert-warning py-2 px-2 mb-2" data-needs-resign>
        <b>Needs signing again.</b> The document changed after it was signed. Nothing is sent by itself.
        <?php if ($canEdit): ?>
          <form method="post" action="/documents/<?= $id ?>/signatures/<?= (int) $resign['id'] ?>/again" class="mt-1"><?= csrf_field() ?>
            <button class="btn btn-xs btn-warning" data-confirm="Send the document as it is now to the same people to sign again?" data-confirm-danger="0" data-confirm-ok="Send again"><i class="fas fa-rotate me-1"></i>Send again</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if (!$signatures): ?>
      <p class="text-muted mb-0"><?= $doc['client_id'] ? 'Not sent for signature yet.' : 'Only client documents can be sent for signature.' ?></p>
    <?php endif; ?>
    <?php foreach (array_slice($signatures, 0, 5) as $i => $r): [$label, $tone] = Signing::status($r); $rid = (int) $r['id']; ?>
      <div class="<?= $i ? 'border-top pt-2 mt-2' : '' ?>" data-sign-request="<?= $rid ?>">
        <div class="d-flex align-items-center">
          <span class="badge text-bg-<?= e($tone) ?> me-2" data-sign-status><?= e($label) ?></span>
          <span class="text-muted me-auto">S-<?= str_pad((string) $rid, 4, '0', STR_PAD_LEFT) ?> · <?= e(fmt_date($r['sent_at'])) ?></span>
        </div>
        <div class="text-muted">Version <?= (int) $r['doc_version'] ?><?= $r['sent_by_name'] ? ' · sent by ' . e($r['sent_by_name']) : '' ?><?= $r['countersign'] ? ' · with countersignature' : '' ?><?= $r['verify_code'] ? ' · emailed code' : '' ?></div>
        <ul class="list-unstyled mb-1 mt-1">
          <?php foreach ($r['signers'] as $s): ?>
            <li class="text-break"><i class="fas fa-fw <?= $s['status'] === 'signed' ? 'fa-circle-check text-success' : ($s['status'] === 'declined' ? 'fa-circle-xmark text-danger' : 'fa-clock text-muted') ?> me-1"></i><?= e($s['name']) ?>
              <span class="text-muted">&lt;<?= e($s['email']) ?>&gt;<?= $s['signed_at'] ? ' · signed ' . e(fmt_datetime($s['signed_at'])) : ($s['status'] === 'waiting' && $s['viewed_at'] ? ' · opened' : '') ?></span></li>
          <?php endforeach; ?>
          <?php if ($r['countersign'] && $r['countersigned_at']): ?><li><i class="fas fa-fw fa-circle-check text-success me-1"></i><?= e((string) $r['countersign_name']) ?> <span class="text-muted">· countersigned <?= e(fmt_datetime($r['countersigned_at'])) ?></span></li><?php endif; ?>
        </ul>
        <?php if ($r['status'] === 'declined' && $r['decline_reason']): ?><div class="text-muted mb-1">Declined: <?= e($r['decline_reason']) ?></div><?php endif; ?>
        <div class="d-flex flex-wrap gap-1">
          <?php if ($r['status'] === 'completed' && $r['signed_file'] && !str_starts_with((string) $r['signed_file'], 'pending')): ?>
            <a class="btn btn-xs btn-success" href="/documents/<?= $id ?>/signatures/<?= $rid ?>/pdf"><i class="fas fa-download me-1"></i>Signed PDF</a>
          <?php elseif ($r['status'] === 'completed' && !empty($r['source_changed'])): /* (2.34.0 review) */ ?>
            <span class="text-danger" data-source-changed>The document's file changed after it was sent: send it again.</span>
            <?php if ($canEdit): ?><form method="post" action="/documents/<?= $id ?>/signatures/<?= $rid ?>/again"><?= csrf_field() ?><button class="btn btn-xs btn-warning" data-confirm="Send the document as it is now to the same people to sign again?" data-confirm-danger="0" data-confirm-ok="Send again"><i class="fas fa-rotate me-1"></i>Send again</button></form><?php endif; ?>
          <?php elseif ($r['status'] === 'completed'): ?><span class="text-muted">The signed PDF is being made.</span><?php endif; ?>
          <a class="btn btn-xs btn-default" href="/documents/<?= $id ?>/signatures/<?= $rid ?>/pdf?sent=1" title="The PDF as it was sent to sign">PDF sent</a>
          <?php if ($canEdit): ?>
            <?php if ($r['status'] === 'sent'): ?>
              <form method="post" action="/documents/<?= $id ?>/signatures/<?= $rid ?>/remind"><?= csrf_field() ?><button class="btn btn-xs btn-default"><i class="fas fa-bell me-1"></i>Remind</button></form>
            <?php endif; ?>
            <?php if (in_array($r['status'], ['sent', 'expired'], true)): ?>
              <form method="post" action="/documents/<?= $id ?>/signatures/<?= $rid ?>/resend"><?= csrf_field() ?><button class="btn btn-xs btn-default" data-confirm="Send new links to everyone who hasn't signed? The earlier links stop working." data-confirm-danger="0" data-confirm-ok="Send again"><i class="fas fa-paper-plane me-1"></i>Send again</button></form>
            <?php endif; ?>
            <?php if ($r['status'] === 'countersign'): ?>
              <button type="button" class="btn btn-xs btn-warning" data-bs-toggle="modal" data-bs-target="#modal-countersign-<?= $rid ?>"><i class="fas fa-pen-nib me-1"></i>Countersign</button>
            <?php endif; ?>
            <?php if (in_array($r['status'], ['sent', 'countersign', 'expired'], true)): ?>
              <form method="post" action="/documents/<?= $id ?>/signatures/<?= $rid ?>/cancel"><?= csrf_field() ?><button class="btn btn-xs btn-outline-danger" data-confirm="Cancel this signature request? The signing links stop working." data-confirm-ok="Cancel request">Cancel</button></form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="modal-send-sign" tabindex="-1" aria-labelledby="modal-send-sign-title" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/documents/<?= $id ?>/signatures"><?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title" id="modal-send-sign-title"><i class="fas fa-pen-nib me-2"></i>Send for signature</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= \Align\View::fetch('documents/_sign_fields', ['contacts' => $signContacts, 'what' => $doc['kind'] === 'file' ? 'The PDF is sent as it is.' : 'The document is sent as a PDF, the way it prints now (version ' . (int) $doc['version'] . ').']) ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Send</button></div>
    </form>
  </div></div>
</div>
<?php foreach ($countersignOpen as $r): $rid = (int) $r['id']; ?>
<div class="modal fade" id="modal-countersign-<?= $rid ?>" tabindex="-1" aria-labelledby="modal-cs-title-<?= $rid ?>" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/documents/<?= $id ?>/signatures/<?= $rid ?>/countersign"><?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title" id="modal-cs-title-<?= $rid ?>"><i class="fas fa-pen-nib me-2"></i>Your countersignature</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="small text-muted">Everyone has signed. Sign to complete it: the signed PDF is made, saved with the document and emailed to the signers.</p>
        <?= \Align\View::fetch('contracts/_sigpad', ['p' => 'cs' . $rid, 'sigName' => (string) $me['name'], 'sigTitle' => '', 'company' => (string) $doc['client_name'], 'what' => 'document']) ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="fas fa-pen-nib me-1"></i>Countersign</button></div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php
/**
 * 2.34.0 A client document's staff acknowledgments (Docs\Acknowledging), on the document page (written or uploaded):
 * the Acknowledgments card (each request with who acknowledged, who is waiting and whose link expired, with dates),
 * its actions (record, remind, send again, add people, cancel, set as compliance evidence), "Needs acknowledging
 * again" after a change, and the windows to ask and to add people. The "Ask staff to acknowledge" button sits next to
 * "Send for signature" (documents/_signatures); the card shows once something was asked.
 * @var array $doc; bool $canEdit; array $acks (Acknowledging::forDocument), $contacts (Signing::contacts), $controls
 *   (Acknowledging::controls); ?array $ackAgain (Acknowledging::needsAgain)
 * Every value is escaped; forms post with CSRF to DocumentAckController, which checks the role and the ids again.
 */
use Align\Docs\Acknowledging;

$id = (int) $doc['id'];
$problem = Acknowledging::problem($doc);
$open = array_values(array_filter(array_slice($acks, 0, 5), fn($r) => $r['status'] !== 'cancelled'));
?>
<?php if ($acks): ?>
<div class="card card-dark" id="acknowledgments">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-user-check me-2"></i>Acknowledgments</h3></div>
  <div class="card-body small">
    <?php if ($ackAgain): ?>
      <div class="alert alert-warning py-2 px-2 mb-2" data-needs-ack-again>
        <b>Needs acknowledging again.</b> The document changed after <?= e(Acknowledging::ref($ackAgain)) ?> was sent. Nothing is sent by itself.
        <?php if ($canEdit): ?>
          <div class="d-flex flex-wrap gap-1 mt-1">
            <form method="post" action="/documents/<?= $id ?>/acknowledgments/<?= (int) $ackAgain['id'] ?>/again"><?= csrf_field() ?><input type="hidden" name="who" value="all">
              <button class="btn btn-xs btn-warning" data-confirm="Ask everyone on <?= e(Acknowledging::ref($ackAgain)) ?> to acknowledge the document as it is now?" data-confirm-danger="0" data-confirm-ok="Ask again"><i class="fas fa-rotate me-1"></i>Ask everyone again</button></form>
            <form method="post" action="/documents/<?= $id ?>/acknowledgments/<?= (int) $ackAgain['id'] ?>/again"><?= csrf_field() ?><input type="hidden" name="who" value="acked">
              <button class="btn btn-xs btn-default" data-confirm="Ask only the people who acknowledged the earlier version to acknowledge it again?" data-confirm-danger="0" data-confirm-ok="Ask again">Only those who acknowledged</button></form>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php foreach (array_slice($acks, 0, 5) as $i => $r): [$label, $tone] = Acknowledging::status($r); $rid = (int) $r['id']; $changed = Acknowledging::changed($doc, $r); ?>
      <div class="<?= $i ? 'border-top pt-2 mt-2' : '' ?>" data-ack-request="<?= $rid ?>">
        <div class="d-flex align-items-center">
          <span class="badge text-bg-<?= e($tone) ?> me-2" data-ack-status><?= e($label) ?></span>
          <span class="text-muted me-auto"><?= e(Acknowledging::ref($r)) ?> · <?= e(fmt_date($r['sent_at'])) ?></span>
        </div>
        <div class="text-muted">Version <?= (int) $r['doc_version'] ?><?= $r['sent_by_name'] ? ' · sent by ' . e($r['sent_by_name']) : '' ?> ·
          <span data-ack-counts><?= (int) $r['acked'] ?> acknowledged · <?= (int) $r['waiting'] ?> waiting<?= $r['expired'] ? ' · ' . (int) $r['expired'] . ' not acknowledged' : '' ?></span></div>
        <?php if ($r['ctl_ref'] || $r['ctl_title']): ?>
          <div class="text-muted">Counts as evidence for <?= e($r['fw_name']) ?> <?= e(trim($r['ctl_ref'] . ' ' . $r['ctl_title'])) ?><?= $r['evidence_at'] ? ' · set ' . e(fmt_date($r['evidence_at'])) : '' ?></div>
        <?php endif; ?>
        <ul class="list-unstyled mb-1 mt-1" style="max-height:320px;overflow:auto">
          <?php foreach ($r['people'] as $s): [$sl, $st, $si] = Acknowledging::PERSON[$s['state']]; ?>
            <li class="text-break" data-ack-person="<?= e($s['state']) ?>"><i class="fas fa-fw <?= e($si) ?> text-<?= e($st) ?> me-1" title="<?= e($sl) ?>"></i><?= e($s['name']) ?>
              <span class="text-muted">&lt;<?= e($s['email']) ?>&gt; · <?= e($sl) ?><?= $s['signed_at'] ? ' ' . e(fmt_datetime($s['signed_at'])) : ($s['state'] === 'waiting' ? ($s['viewed_at'] ? ', opened' : '') . ' until ' . e(fmt_date($s['token_expires_at'])) : '') ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($r['status'] === 'cancelled' && $r['cancel_reason']): ?><div class="text-muted mb-1">Cancelled: <?= e($r['cancel_reason']) ?></div><?php endif; ?>
        <div class="d-flex flex-wrap gap-1">
          <a class="btn btn-xs btn-default" href="/documents/<?= $id ?>/acknowledgments/<?= $rid ?>/record" target="_blank"><i class="fas fa-print me-1"></i>Record</a>
          <a class="btn btn-xs btn-default" href="/documents/<?= $id ?>/acknowledgments/<?= $rid ?>/pdf" title="The PDF people were asked to read">PDF sent</a>
          <?php if ($canEdit): ?>
            <?php if ($r['status'] === 'sent' && $r['waiting']): ?>
              <form method="post" action="/documents/<?= $id ?>/acknowledgments/<?= $rid ?>/remind"><?= csrf_field() ?><button class="btn btn-xs btn-default"><i class="fas fa-bell me-1"></i>Remind</button></form>
            <?php endif; ?>
            <?php if (in_array($r['status'], ['sent', 'expired'], true) && ($r['waiting'] || $r['expired'])): ?>
              <form method="post" action="/documents/<?= $id ?>/acknowledgments/<?= $rid ?>/resend"><?= csrf_field() ?><button class="btn btn-xs btn-default" data-confirm="Send new links to everyone who hasn't acknowledged? The earlier links stop working." data-confirm-danger="0" data-confirm-ok="Send again"><i class="fas fa-paper-plane me-1"></i>Send again</button></form>
            <?php endif; ?>
            <?php if ($r['status'] !== 'cancelled' && !$changed): ?>
              <button type="button" class="btn btn-xs btn-default" data-bs-toggle="modal" data-bs-target="#modal-add-ack-<?= $rid ?>"><i class="fas fa-user-plus me-1"></i>Add people</button>
            <?php endif; ?>
            <?php if ($r['status'] !== 'cancelled'): ?>
              <form method="post" action="/documents/<?= $id ?>/acknowledgments/<?= $rid ?>/cancel"><?= csrf_field() ?><button class="btn btn-xs btn-outline-danger" data-confirm="Cancel this request? The links stop working; acknowledgments already given stay on record." data-confirm-ok="Cancel request">Cancel</button></form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($canEdit && $r['acked'] && $controls): ?>
          <form method="post" action="/documents/<?= $id ?>/acknowledgments/<?= $rid ?>/evidence" class="d-flex flex-wrap gap-1 mt-1" data-ack-evidence><?= csrf_field() ?>
            <select name="control_id" class="form-select form-select-sm" style="flex:1 1 160px;min-width:0;max-width:100%" aria-label="Compliance control">
              <?php $fw = null; foreach ($controls as $c): if ($c['fw_name'] !== $fw): ?><?= $fw !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($c['fw_name']) ?>"><?php $fw = $c['fw_name']; endif; ?>
                <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $r['control_id'] ? ' selected' : '' ?>><?= e(trim(($c['ref'] ?? '') . ' ' . $c['title'])) ?></option>
              <?php endforeach; ?><?= $fw !== null ? '</optgroup>' : '' ?>
            </select>
            <div class="form-check small mb-0 align-self-center"><input class="form-check-input" type="checkbox" name="replace_doc" value="1" id="ack-rep-<?= $rid ?>">
              <label class="form-check-label" for="ack-rep-<?= $rid ?>">Replace the linked document</label></div>
            <button class="btn btn-xs btn-<?= $r['status'] === 'completed' ? 'success' : 'default' ?>" data-confirm="Add &quot;<?= (int) $r['acked'] ?> of <?= (int) $r['total'] ?> staff acknowledged …&quot; to this control's evidence? What is written there stays. This document is linked if none is (or if you ticked Replace). Its status and notes stay as they are." data-confirm-danger="0" data-confirm-ok="Set evidence"><i class="fas fa-clipboard-check me-1"></i>Set as evidence</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal fade" id="modal-send-ack" tabindex="-1" aria-labelledby="modal-send-ack-title" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/documents/<?= $id ?>/acknowledgments"><?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title" id="modal-send-ack-title"><i class="fas fa-user-check me-2"></i>Ask staff to acknowledge</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?php if ($problem): ?><div class="alert alert-warning py-2"><?= e($problem) ?></div><?php endif; ?>
        <p class="small text-muted mb-2"><?= $doc['kind'] === 'file' ? 'The PDF is sent as it is.' : 'The document is sent as a PDF, the way it prints now (version ' . (int) $doc['version'] . ').' ?> Each person gets their own private link by email. They read it and tick <b>I have read and agree to this policy</b> with their name: no signature or code.</p>
        <?= \Align\View::fetch('documents/_ack_fields', ['p' => 'ak', 'contacts' => $contacts, 'controls' => $controls, 'full' => true]) ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Send</button></div>
    </form>
  </div></div>
</div>
<?php foreach ($open as $r): $rid = (int) $r['id']; if (Acknowledging::changed($doc, $r)) continue; ?>
<div class="modal fade" id="modal-add-ack-<?= $rid ?>" tabindex="-1" aria-labelledby="modal-add-ack-title-<?= $rid ?>" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/documents/<?= $id ?>/acknowledgments/<?= $rid ?>/add"><?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title" id="modal-add-ack-title-<?= $rid ?>"><i class="fas fa-user-plus me-2"></i>Add people to <?= e(Acknowledging::ref($r)) ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="small text-muted mb-2">They get their own link to the same PDF (version <?= (int) $r['doc_version'] ?>). People already on it are left out.<?= $r['status'] === 'expired' ? ' This request has expired: everyone who hadn\'t acknowledged gets a new link too.' : '' ?></p>
        <?= \Align\View::fetch('documents/_ack_fields', ['p' => 'aa' . $rid, 'contacts' => $contacts, 'controls' => [], 'full' => false,
            'skip' => array_map(fn($s) => strtolower(trim((string) $s['email'])), $r['people'])]) ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Add and send</button></div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

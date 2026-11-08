<?php
/**
 * 2.34.0 Send a compliance report for signature (DocumentSignatureController::reportForm): who signs and the options.
 * The report sent is the client's version (what the portal's framework page shows: no notes, evidence or guidance);
 * it's kept as a client document first, then sent like any other.
 * @var array $client, $fw, $contacts; bool $mailReady. Every value is escaped; the form posts with CSRF.
 */
require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$fid = (int) $fw['id'];
?>
<div class="small mb-1"><a href="/clients/<?= $cid ?>/compliance/<?= $fid ?>"><?= e($fw['name']) ?></a> / <a href="/clients/<?= $cid ?>/report/compliance/<?= $fid ?>" target="_blank">Compliance report</a> /</div>
<h1 class="h4 mb-3"><i class="fas fa-pen-nib me-2 text-secondary"></i>Send the compliance report for signature</h1>
<?php if (!$mailReady): ?><div class="alert alert-warning">Email isn't set up (Integrations → Email): use "Make the PDF, links only" and send the links yourself.</div><?php endif; ?>
<div class="card" style="max-width:820px">
  <form method="post" action="/clients/<?= $cid ?>/report/compliance/<?= $fid ?>/sign"><?= csrf_field() ?>
    <div class="card-body">
      <?= \Align\View::fetch('documents/_sign_fields', ['contacts' => $contacts, 'what' => 'Align makes a PDF of the ' . $fw['name'] . ' report as the client sees it (status, owner, due date and shared documents; no notes, evidence or guidance) and keeps it as one of ' . $client['name'] . '\'s documents.']) ?>
    </div>
    <div class="card-footer d-flex gap-2"><button class="btn btn-primary" name="action" value="send"<?= $mailReady ? '' : ' disabled' ?>><i class="fas fa-paper-plane me-1"></i>Make the PDF and send</button>
      <button class="btn btn-outline-primary" name="action" value="link" title="Nothing is emailed: you copy the links and send them yourself"><i class="fas fa-link me-1"></i>Make the PDF, links only</button><?php /* (2.34.0) works without email */ ?>
      <a class="btn btn-light" href="/clients/<?= $cid ?>/compliance/<?= $fid ?>">Cancel</a></div>
  </form>
</div>

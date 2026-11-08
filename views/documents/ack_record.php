<?php
use Align\Docs\Acknowledging;
use Align\Reports\Ui;

/**
 * 2.34.0 The acknowledgment record (DocumentAckController::record), for audits and insurance: the document, version,
 * the SHA-256 of the PDF people read, when it was sent and by whom, the counts, and each person with their status,
 * time, the name they typed, IP address and how they got there. Techs and admins also get "Save as a client document"
 * (a POST form, never printed).
 * @var array $r (Acknowledging::load), $rows (Acknowledging::recordRows), $client, $brand; bool $canEdit
 * Every value is escaped; the form posts with CSRF to DocumentAckController::recordSave, which checks the role again.
 */
$c = array_count_values(array_column($rows, 'state'));
$tone = ['acknowledged' => 'ok', 'waiting' => 'info', 'expired' => 'muted', 'cancelled' => 'muted'];
?>
<?php if ($canEdit): ?>
  <form method="post" action="/documents/<?= (int) $r['document_id'] ?>/acknowledgments/<?= (int) $r['id'] ?>/record" class="no-print mb-3 d-flex align-items-center gap-2" data-ack-save><?= csrf_field() ?>
    <button class="btn btn-sm btn-outline-primary"><i class="fas fa-floppy-disk me-1"></i>Save as a client document</button>
    <span class="small muted">Keeps this record as a PDF in <?= e($client['name']) ?>'s documents (Assessment, not shared in the portal).</span>
  </form>
<?php endif; ?>
<section class="rsection">
  <?= Ui::head('Summary', '01', Acknowledging::ref($r)) ?>
  <table class="rtable fixed compact">
    <tbody>
      <tr><td class="name" style="width:28%">Document</td><td><?= e($r['title']) ?> (version <?= (int) $r['doc_version'] ?>)</td></tr>
      <tr><td class="name">Statement</td><td>"I have read and agree to this policy"</td></tr>
      <tr><td class="name">Sent</td><td><?= e(fmt_datetime($r['sent_at'])) ?><?= $r['sent_by_name'] ? ' by ' . e($r['sent_by_name']) : '' ?></td></tr>
      <tr><td class="name">PDF people read (SHA-256)</td><td style="overflow-wrap:anywhere"><code><?= e($r['source_hash']) ?></code></td></tr>
      <tr><td class="name">Status</td><td><?= e(Acknowledging::STATUSES[$r['status']][0] ?? $r['status']) ?><?= $r['completed_at'] ? ' · ' . e(fmt_datetime($r['completed_at'])) : '' ?></td></tr>
    </tbody>
  </table>
  <div class="kpi-row">
    <?= Ui::kpi((string) ($c['acknowledged'] ?? 0), 'Acknowledged', '', 'ok') ?>
    <?= Ui::kpi((string) ($c['waiting'] ?? 0), 'Waiting', '', 'info') ?>
    <?= Ui::kpi((string) (($c['expired'] ?? 0) + ($c['cancelled'] ?? 0)), 'Not acknowledged', 'expired or cancelled', 'muted') ?>
    <?= Ui::kpi((string) count($rows), 'Asked') ?>
  </div>
</section>
<section class="rsection">
  <?= Ui::head('People', '02', count($rows) . ' asked') ?>
  <table class="rtable fixed compact" data-ack-rows>
    <?= Ui::cols(['person' => 34, 'status' => 14, 'when' => 20, 'detail' => 32]) ?>
    <thead><tr><th>Person</th><th>Status</th><th>When</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $x): ?>
      <tr><td><div class="name"><?= e($x['name']) ?></div><div class="sub"><?= e($x['email']) ?><?= $x['pasted'] ? ' · not a contact' : '' ?></div></td>
        <td><?= Ui::pill($x['label'], $tone[$x['state']] ?? 'muted') ?></td>
        <td><?= $x['at'] ? e(fmt_datetime($x['at'])) : '<span class="muted">—</span>' ?></td>
        <td><?php if ($x['at']): ?>As "<?= e($x['typed']) ?>"<div class="sub">IP <?= e($x['ip']) ?> · <?= e($x['via']) ?></div><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="small muted">Each person opened a private link emailed to the address shown (or signed in to the client portal as that address), read the PDF above and ticked "I have read and agree to this policy" with their name.</p>
</section>

<?php
/**
 * 2.29.0 Review what AI read in a document: the suggested changes to the client's vendors and licenses, each with
 * what Align has now, the suggestion, the document's own words and a tick box. Apply changes only what is ticked.
 * @var array $client, $doc (Documents::load), $rows (Plan::rows; empty once applied or discarded); string $from
 * Security: everything from the document or AI (names, values, quotes, notes, file names) is escaped; locked rows
 * have no tick box value that Apply would take (Apply works the rows out again and skips locked ones anyway).
 */
use Align\Ai\Documents\Extract;
use Align\Ai\Documents\Plan;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$r = $doc['result'];
$d = $r['document'];
$backLabel = $from === 'vendors' ? 'Vendors' : 'Licensing';
$groups = [
    'Vendors' => array_values(array_filter($rows, fn($x) => in_array($x['kind'], ['vendor_new', 'vendor_set'], true))),
    'Licenses' => array_values(array_filter($rows, fn($x) => in_array($x['kind'], ['license_new', 'license_set'], true))),
    'Not added' => array_values(array_filter($rows, fn($x) => $x['kind'] === 'skip')),
];
// The document names another company as the one billed: worth a second look before applying it to this client
$billed = $d['billed_to'] ?? null;
$otherClient = $billed !== null && Plan::loose($billed) !== '' && !str_contains(Plan::loose($client['name']), Plan::loose($billed)) && !str_contains(Plan::loose($billed), Plan::loose($client['name']));
?>
<div class="small mb-1"><a href="/clients/<?= $cid ?>/<?= $from ?>"><?= e($backLabel) ?></a> / <a href="/clients/<?= $cid ?>/ai-documents?from=<?= e($from) ?>">Read a document</a> /</div>
<h1 class="h4 mb-1"><i class="fas fa-wand-magic-sparkles text-secondary me-2"></i><?= e(Extract::KINDS[$d['kind'] ?? 'other'] ?? 'Document') ?><?= !empty($d['number']) ? ' ' . e($d['number']) : '' ?>
  <?= !empty($r['vendors'][0]['name']) ? '<span class="text-muted fw-normal">from ' . e($r['vendors'][0]['name']) . '</span>' : '' ?></h1>
<p class="small text-muted mb-3">
  <?= !empty($d['date']) ? e(\Align\Fmt::date($d['date'])) . ' · ' : '' ?><?= e(implode(', ', array_column($doc['files'], 'name'))) ?> · read by <?= e($doc['created_name'] ?: '—') ?> <?= e(rel_time($doc['created_at'])) ?>
</p>
<?php if ($otherClient): ?>
  <div class="alert alert-warning py-2"><i class="fas fa-triangle-exclamation me-1"></i>This document is billed to <b><?= e($billed) ?></b>. Check it's <?= e($client['name']) ?>'s before applying it here.</div>
<?php endif; ?>
<?php foreach ($doc['notes'] as $n): ?><div class="alert alert-secondary py-2 small"><i class="fas fa-circle-info me-1"></i><?= e($n) ?></div><?php endforeach; ?>

<?php if ($doc['status'] !== 'review'): ?>
  <div class="card card-body">
    <p class="mb-2"><b><?= $doc['status'] === 'applied' ? 'Applied' : 'Discarded' ?></b> <?= e(rel_time($doc['done_at'])) ?>.</p>
    <?php if ($doc['summary']): ?><ul class="small mb-0"><?php foreach (explode("\n", (string) $doc['summary']) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
<?php else: ?>
<form method="post" action="/clients/<?= $cid ?>/ai-documents/<?= (int) $doc['id'] ?>" data-unsaved>
  <?= csrf_field() ?><input type="hidden" name="from" value="<?= e($from) ?>">
  <p class="small text-muted">Tick what to change. Values AI wasn't sure of are left out, and replacing something Align already has starts unticked. Check each value against the document's words under it.</p>
  <?php foreach ($groups as $title => $list): if (!$list) continue; $canTick = $title !== 'Not added'; ?>
    <div class="card mb-3">
      <div class="card-header py-2"><h3 class="card-title mt-1"><?= e($title) ?></h3></div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr>
            <th class="ps-3" style="width:2rem"><?php if ($canTick): ?><input type="checkbox" class="form-check-input" data-ai-all aria-label="Tick all <?= e(mb_strtolower($title)) ?>"><?php endif; ?></th>
            <th>Change</th><th>In Align now</th><th>Suggested</th>
          </tr></thead>
          <tbody>
          <?php foreach ($list as $row): $rid = 'ai-row-' . e(str_replace(':', '-', $row['id'])); ?>
            <tr class="<?= $row['locked'] !== null ? 'text-muted' : '' ?>">
              <td class="ps-3"><?php if ($canTick): ?><input type="checkbox" class="form-check-input" id="<?= $rid ?>" name="rows[]" value="<?= e($row['id']) ?>"<?= $row['checked'] ? ' checked' : '' ?><?= $row['locked'] !== null ? ' disabled' : '' ?>><?php endif; ?></td>
              <td><label class="fw-semibold mb-0" for="<?= $rid ?>"><?= e($row['what']) ?></label>
                <?php if ($row['flag'] !== null): ?> <span class="badge text-bg-warning ms-1"><?= e($row['flag']) ?></span><?php endif; ?>
                <?php if ($row['quote'] !== null): ?><div class="small text-muted fst-italic">“<?= e($row['quote']) ?>”</div><?php endif; ?>
                <?php if ($row['locked'] !== null): ?><div class="small"><i class="fas fa-lock me-1"></i><?= e($row['locked']) ?></div><?php endif; ?></td>
              <td class="small"><?= $row['current'] !== null ? e($row['current']) : '<span class="text-muted">—</span>' ?></td>
              <td class="small"><?= e($row['value']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!array_filter($rows, fn($x) => $x['kind'] !== 'skip')): ?>
    <p class="text-muted">Everything this document says is already in Align.</p>
  <?php endif; ?>
  <div class="form-check mb-3"><input type="checkbox" class="form-check-input" id="ai-keep" name="keep" value="1" checked>
    <label class="form-check-label fw-normal" for="ai-keep">Keep the PDF, Word, Excel and CSV files (an email's attachments too) as <?= e($client['name']) ?>'s documents</label></div>
  <button class="btn btn-primary" name="action" value="apply"><i class="fas fa-check me-1"></i>Apply ticked changes</button>
  <button class="btn btn-default ms-1" name="action" value="discard" data-confirm="Discard this review? Nothing is changed and the uploaded files are deleted."><i class="fas fa-trash me-1"></i>Discard</button>
</form>
<?php endif; ?>

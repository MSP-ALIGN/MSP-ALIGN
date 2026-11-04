<?php
/**
 * 2.30.0 An AI compliance review. While batches remain: a progress bar that app.js moves on by posting the step URL
 * until every batch is done (a failure shows its message and a Try again button). Then, per framework, the controls
 * AI found something about (the answer now, the suggestion, the reason and the quoted facts with their document),
 * each with a tick box; the controls nothing was found for (gaps); and documents that look out of date.
 * @var array $client, $review (Review::load), $rows (Review::rows), $gaps (Review::gaps), $stale (Review::stale),
 *      $assigned (framework id => index), $suggest (Templates::suggest), $templates (Templates::all); int $total;
 *      bool $ready, $drafts (the Draft documents section: matched or applied, and AI usable for drafts)
 * 2.30.0 Draft documents: each suggested template (with how many gaps it covers) and every template can be drafted
 * from this review's facts (a POST per template), and each client document the review read can be updated with AI.
 * Security: everything from the documents or AI (reasons, facts, quotes, titles, file names, notes) is escaped. Only
 * row ids Review::rows offers are written as tick boxes, and Apply works the rows out again anyway. The step URL is
 * posted with this page's CSRF token (data-csrf).
 */
use Align\Ai\Compliance\Review;
use Align\Compliance\Compliance;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$rid = (int) $review['id'];
$names = array_values(array_unique(array_column($review['sources'], 'name')));
$badge = function (string $status): string {
    [$label, $tone, $icon] = Compliance::STATUSES[$status] ?? Compliance::STATUSES['not_assessed'];
    return '<span class="badge text-bg-' . e($tone) . '"><i class="fas ' . e($icon) . ' me-1"></i>' . e($label) . '</span>';
};
$hasUploads = (bool) $review['files'];
?>
<div class="small mb-1"><a href="/clients/<?= $cid ?>/compliance">Compliance</a> / <a href="/clients/<?= $cid ?>/ai-reviews">Review documents with AI</a> /</div>
<h1 class="h4 mb-1"><i class="fas fa-wand-magic-sparkles text-secondary me-2"></i>AI compliance review</h1>
<p class="small text-muted mb-3">
  <?= e(implode(', ', $names)) ?> · against <?= e(implode(', ', array_column($review['frameworks'], 'name'))) ?> · started by <?= e($review['created_name'] ?: '—') ?> <?= e(rel_time($review['created_at'])) ?>
</p>
<?php foreach (array_unique(array_filter(array_column($review['sources'], 'note'))) as $n): ?><div class="alert alert-secondary py-2 small"><i class="fas fa-circle-info me-1"></i><?= e($n) ?></div><?php endforeach; ?>

<?php if ($review['status'] !== 'review'): ?>
  <div class="card card-body">
    <p class="mb-2"><b><?= $review['status'] === 'applied' ? 'Applied' : 'Discarded' ?></b> <?= e(rel_time($review['done_at'])) ?>.</p>
    <?php if ($review['summary']): ?><ul class="small mb-0"><?php foreach (array_filter(explode("\n", (string) $review['summary']), 'strlen') as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
<?php elseif (!$ready): ?>
  <div class="card" data-ai-steps="/clients/<?= $cid ?>/ai-reviews/<?= $rid ?>/step" data-csrf="<?= e(csrf_token()) ?>" data-step="<?= (int) $review['step'] ?>" data-total="<?= $total ?>">
    <div class="card-body">
      <p class="mb-2">AI read <?= count($review['facts']) ?> fact<?= count($review['facts']) === 1 ? '' : 's' ?> in the documents and is now checking them against the controls, in <?= $total ?> step<?= $total === 1 ? '' : 's' ?>. Keep this page open; it shows the review when it's done.</p>
      <div class="progress mb-2" role="progressbar" aria-label="Checking controls" aria-valuemin="0" aria-valuemax="<?= $total ?>" aria-valuenow="<?= (int) $review['step'] ?>">
        <div class="progress-bar" style="width: <?= $total ? round((int) $review['step'] / $total * 100) : 100 ?>%" data-ai-steps-bar></div>
      </div>
      <div class="small text-muted" data-ai-steps-text>Step <?= min($total, (int) $review['step'] + 1) ?> of <?= $total ?>…</div>
      <div class="alert alert-danger py-2 mt-2 d-none" role="alert" data-ai-steps-error></div>
      <button type="button" class="btn btn-sm btn-primary d-none" data-ai-steps-retry><i class="fas fa-rotate-right me-1"></i>Try again</button>
    </div>
  </div>
  <form method="post" action="/clients/<?= $cid ?>/ai-reviews/<?= $rid ?>">
    <?= csrf_field() ?>
    <button class="btn btn-default btn-sm" name="action" value="discard" data-confirm="Discard this review? Nothing is changed and any uploaded files are deleted."><i class="fas fa-trash me-1"></i>Discard</button>
  </form>
<?php else: ?>
<form method="post" action="/clients/<?= $cid ?>/ai-reviews/<?= $rid ?>" data-unsaved>
  <?= csrf_field() ?>
  <p class="small text-muted">Tick the answers to save. Controls that aren't assessed yet start ticked; replacing an answer Align already has starts unticked. Check each suggestion against the document's words under it. The reason is added to the control's notes, and the words become its evidence.</p>
  <?php foreach ($review['frameworks'] as $f): $fid = (int) $f['id']; $list = array_values(array_filter($rows, fn($x) => $x['framework_id'] === $fid)); ?>
    <div class="card mb-3">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-check me-2"></i><?= e($f['name']) ?></h3>
        <?php if (!isset($assigned[$fid])): ?><div class="card-tools small text-muted mt-1"><i class="fas fa-circle-info me-1"></i>Applying assigns this framework to <?= e($client['name']) ?></div><?php endif; ?>
      </div>
      <?php if (!$list): ?>
        <div class="card-body small text-muted">AI found nothing in the documents about this framework's controls.</div>
      <?php else: ?>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr>
            <th class="ps-3" style="width:2rem"><input type="checkbox" class="form-check-input" data-ai-all aria-label="Tick all in <?= e($f['name']) ?>"></th>
            <th>Control</th><th>In Align now</th><th>Suggested</th>
          </tr></thead>
          <tbody>
          <?php foreach ($list as $row): $id = 'ai-row-' . e($row['id']); ?>
            <tr>
              <td class="ps-3 align-top"><input type="checkbox" class="form-check-input" id="<?= $id ?>" name="rows[]" value="<?= e($row['id']) ?>"<?= $row['checked'] ? ' checked' : '' ?>></td>
              <td class="align-top"><label class="fw-semibold mb-0" for="<?= $id ?>"><span class="text-muted"><?= e($row['ref']) ?></span> <?= e($row['title']) ?></label>
                <?php if ($row['flag'] !== null): ?> <span class="badge text-bg-warning ms-1"><?= e($row['flag']) ?></span><?php endif; ?>
                <?php if ($row['reason'] !== ''): ?><div class="small mt-1"><?= e($row['reason']) ?></div><?php endif; ?>
                <?php foreach ($row['facts'] as $fact): ?>
                  <div class="small text-muted fst-italic mt-1">“<?= e($fact['quote'] ?? $fact['text']) ?>” <span class="fst-normal">— <?= e($fact['source_title']) ?><?= $fact['date'] ? ', ' . e(fmt_date($fact['date'])) : '' ?></span></div>
                <?php endforeach; ?></td>
              <td class="small align-top"><?= $badge($row['current']) ?>
                <?php if (trim((string) $row['current_notes']) !== ''): ?><div class="text-muted mt-1"><?= e(mb_strimwidth(trim((string) $row['current_notes']), 0, 120, '…')) ?></div><?php endif; ?></td>
              <td class="small align-top"><?= $badge($row['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($gaps): ?>
    <div class="card mb-3">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-exclamation me-2"></i>Gaps</h3></div>
      <div class="card-body small">
        <p class="text-muted mb-2">Controls of assigned frameworks the documents say nothing about. A policy or procedure may be missing for these.</p>
        <?php foreach ($review['frameworks'] as $f): if (!isset($gaps[(int) $f['id']])) continue; $g = $gaps[(int) $f['id']]; ?>
          <details class="mb-2"><summary><b><?= e($f['name']) ?></b>: <?= count($g) ?> control<?= count($g) === 1 ? '' : 's' ?> with nothing found</summary>
            <ul class="mb-0 mt-1"><?php foreach ($g as $c): ?><li><span class="text-muted"><?= e($c['ref']) ?></span> <?= e($c['title']) ?></li><?php endforeach; ?></ul>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($stale): ?>
    <div class="card mb-3">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left me-2"></i>Stale documents</h3></div>
      <div class="card-body small">
        <p class="text-muted mb-2">These look out of date (not reviewed in the last <?= Review::STALE_MONTHS ?> months, or past their review date). Answers based on them may need checking.</p>
        <ul class="mb-0"><?php foreach ($stale as $s): ?>
          <li><?php if ($s['document_id']): ?><a href="/documents/<?= (int) $s['document_id'] ?>"><?= e($s['title']) ?></a><?php else: ?><?= e($s['title']) ?><?php endif; ?> <span class="text-muted">— <?= e($s['why']) ?></span></li>
        <?php endforeach; ?></ul>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($hasUploads): ?>
    <div class="form-check mb-3"><input type="checkbox" class="form-check-input" id="ai-keep" name="keep" value="1" checked>
      <label class="form-check-label fw-normal" for="ai-keep">Keep the uploaded PDF, Word, Excel and CSV files as <?= e($client['name']) ?>'s documents, and link them as evidence</label></div>
  <?php endif; ?>
  <button class="btn btn-primary" name="action" value="apply"><i class="fas fa-check me-1"></i>Apply ticked answers</button>
  <button class="btn btn-default ms-1" name="action" value="discard" data-confirm="Discard this review? Nothing is changed and any uploaded files are deleted."><i class="fas fa-trash me-1"></i>Discard</button>
</form>
<?php endif; ?>

<?php if ($drafts): /* 2.30.0 drafting from this review's facts; each draft is a new document */ ?>
  <div class="card mt-3 mb-3" id="ai-drafts">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-file-pen me-2"></i>Draft documents</h3></div>
    <div class="card-body small">
      <p class="text-muted mb-2">AI fills one of Align's templates for <?= e($client['name']) ?> with the facts this review read, and marks anything it doesn't know as <b>[To confirm: …]</b>. Each draft is a new document (a Draft, not shared in the portal) to check before you set it Active.</p>
      <?php if ($suggest): ?>
        <h4 class="h6">Suggested for the gaps</h4>
        <ul class="list-unstyled mb-3">
          <?php foreach ($suggest as $t): ?>
            <li class="d-flex align-items-center gap-2 mb-1">
              <form method="post" action="/clients/<?= $cid ?>/ai-reviews/<?= $rid ?>/draft" data-ai-upload class="mb-0"><?= csrf_field() ?>
                <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                <button class="btn btn-sm btn-default" data-ai-submit aria-label="Draft <?= e($t['name']) ?>"><i class="fas fa-wand-magic-sparkles me-1"></i>Draft</button>
                <span class="text-muted d-none" data-ai-wait><span class="spinner-border spinner-border-sm" aria-hidden="true"></span></span>
              </form>
              <span><?= e($t['name']) ?> <span class="text-muted">· covers <?= (int) $t['gaps'] ?> gap<?= (int) $t['gaps'] === 1 ? '' : 's' ?></span></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <form method="post" action="/clients/<?= $cid ?>/ai-reviews/<?= $rid ?>/draft" data-ai-upload class="d-flex flex-wrap align-items-center gap-2 mb-3"><?= csrf_field() ?>
        <label for="ai-draft-template" class="mb-0">Any template</label>
        <select id="ai-draft-template" name="template_id" class="form-select form-select-sm w-auto">
          <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary" data-ai-submit><i class="fas fa-wand-magic-sparkles me-1"></i>Draft</button>
        <span class="text-muted d-none" data-ai-wait><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Writing… this can take a few minutes.</span>
      </form>
      <?php $docs = []; foreach ($review['sources'] as $s) { if (!empty($s['document_id'])) { $docs[(int) $s['document_id']] = (string) $s['name']; } } ?>
      <?php if ($docs): ?>
        <h4 class="h6">Update a document this review read</h4>
        <ul class="mb-0">
          <?php foreach ($docs as $docId => $name): ?>
            <li><?= e($name) ?> · <a href="/clients/<?= $cid ?>/ai-drafts/<?= $docId ?>?review=<?= $rid ?>"><i class="fas fa-wand-magic-sparkles me-1"></i>Update with AI</a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

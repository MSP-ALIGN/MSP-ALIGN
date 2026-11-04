<?php
/**
 * 2.30.0 Update a client document with AI: keep its structure and wording, or move it onto one of Align's templates
 * (the best match preselected), using the facts of one of the client's compliance reviews (or none). The result is
 * always a new draft document; this one stays as it is.
 * @var array $client, $doc, $templates (Templates::all), $reviews (id => row); ?int $best; int $pick, $remaining;
 *      bool $usable, $readable; ?string $why
 * Security: titles, template names and people's names are escaped; the form posts with a CSRF token. Template and
 * review ids are checked again by the controller (a review must be this client's).
 */
use Align\Ai\Drafts\Draft;
use Align\Docs\Documents;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$did = (int) $doc['id'];
?>
<div class="small mb-1"><a href="/clients/<?= $cid ?>/documents">Documents</a> / <a href="/documents/<?= $did ?>"><?= e($doc['title']) ?></a> /</div>
<h1 class="h4 mb-3"><i class="fas fa-wand-magic-sparkles text-secondary me-2"></i>Update with AI</h1>
<div class="row">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-body">
        <p class="small text-muted">AI writes an updated copy of <b><?= e($doc['title']) ?></b> as a new draft document. This document isn't changed. AI uses only what the document, the review's facts and Align's details about <?= e($client['name']) ?> say, and marks anything it doesn't know as <b>[To confirm: …]</b>. Check every part before you set the draft Active.</p>
        <?php if (!$usable): ?>
          <div class="alert alert-secondary py-2 mb-0"><i class="fas fa-circle-info me-1"></i><?= e($why ?? 'AI isn\'t available.') ?></div>
        <?php elseif (!$readable): ?>
          <div class="alert alert-secondary py-2 mb-0"><i class="fas fa-circle-info me-1"></i>AI can only read pages and PDF, Word, Excel and CSV files.</div>
        <?php else: ?>
          <form method="post" action="/clients/<?= $cid ?>/ai-drafts/<?= $did ?>" data-ai-upload>
            <?= csrf_field() ?>
            <h2 class="h6 mt-1">How</h2>
            <?php foreach (Draft::MODES as $k => $label): ?>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="mode" value="<?= e($k) ?>" id="aim-<?= e($k) ?>"<?= $k === 'refresh' ? ' checked' : '' ?>>
                <label class="form-check-label" for="aim-<?= e($k) ?>"><?= e($label) ?></label>
                <div class="form-text mt-0 mb-2"><?= $k === 'refresh'
                    ? 'Keeps its headings, order and voice. Updates what\'s out of date or contradicted by the facts, adds missing points marked <b>[Added]</b>, and lists what changed at the top.'
                    : 'Fills the template below for ' . e($client['name']) . ' with this document\'s content and the facts.' ?></div>
              </div>
            <?php endforeach; ?>
            <div class="mb-3 ms-4">
              <label for="ai-template" class="small">Template</label>
              <select id="ai-template" name="template_id" class="form-select form-select-sm">
                <?php foreach ($templates as $t): ?>
                  <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === (int) $best ? ' selected' : '' ?>><?= e($t['name']) ?> (<?= e(Documents::category((string) $t['category'])[0]) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <h2 class="h6">Facts</h2>
            <select name="review_id" class="form-select form-select-sm mb-1" aria-label="Facts from a compliance review">
              <option value="0">None: only the document and Align's details</option>
              <?php foreach ($reviews as $r): ?>
                <option value="<?= (int) $r['id'] ?>"<?= (int) $r['id'] === $pick ? ' selected' : '' ?>>Compliance review #<?= (int) $r['id'] ?> · <?= e(fmt_date($r['created_at'])) ?> · <?= e($r['created_name'] ?: '—') ?><?= $r['status'] === 'applied' ? ' (applied)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text mb-3">What an AI compliance review read in <?= e($client['name']) ?>'s documents. <a href="/clients/<?= $cid ?>/ai-reviews">Review documents with AI</a> to get newer facts.</div>
            <p class="small text-muted"><?= e(number_format($remaining)) ?> tokens left this month. A long document can use 20,000 or more and take a few minutes.</p>
            <button class="btn btn-primary" data-ai-submit><i class="fas fa-wand-magic-sparkles me-1"></i>Write the draft</button>
            <a class="btn btn-default ms-1" href="/documents/<?= $did ?>">Cancel</a>
            <span class="small text-muted ms-2 d-none" data-ai-wait><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Writing… this can take a few minutes.</span>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

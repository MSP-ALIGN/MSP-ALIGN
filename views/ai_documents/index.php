<?php
/**
 * 2.29.0 Read a document with AI (a client's Licensing or Vendors page): the upload form and the reviews still waiting.
 * @var array $client, $waiting; string $from ('licenses' or 'vendors'); bool $usable; ?string $why
 * Security: names and file names come from people (escaped); the form posts with a CSRF token.
 */
use Align\Ai\Documents\Documents;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$backLabel = $from === 'vendors' ? 'Vendors' : 'Licensing';
?>
<div class="small mb-1"><a href="/clients/<?= $cid ?>/<?= $from ?>"><?= e($backLabel) ?></a> /</div>
<h1 class="h4 mb-3"><i class="fas fa-wand-magic-sparkles text-secondary me-2"></i>Read a document</h1>
<div class="row">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-file-arrow-up me-2"></i>Upload</h3></div>
      <div class="card-body">
        <p class="small text-muted">A vendor invoice, quote, order, renewal notice or contract for <?= e($client['name']) ?>, or an email with them attached. AI reads it and suggests new vendors and licenses and changes to the ones you have. Nothing changes until you review it and press <b>Apply</b>.</p>
        <?php if (!$usable): ?>
          <div class="alert alert-secondary py-2 mb-0"><i class="fas fa-circle-info me-1"></i><?= e($why ?? 'AI isn\'t available.') ?></div>
        <?php else: ?>
          <form method="post" action="/clients/<?= $cid ?>/ai-documents?from=<?= e($from) ?>" enctype="multipart/form-data" data-ai-upload>
            <?= csrf_field() ?>
            <div class="mb-2">
              <label for="ai-files">Files</label>
              <input type="file" id="ai-files" name="files[]" class="form-control" multiple required accept="<?= e(Documents::ACCEPT) ?>">
              <div class="form-text">PDF, Word (.docx), Excel (.xlsx), CSV, text, or an email (.eml or Outlook .msg) with its attachments. Up to <?= Documents::MAX_FILES ?> files, <?= Documents::MAX_BYTES >> 20 ?> MB each.</div>
            </div>
            <button class="btn btn-primary" data-ai-submit><i class="fas fa-wand-magic-sparkles me-1"></i>Read with AI</button>
            <span class="small text-muted ms-2 d-none" data-ai-wait><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Reading… this can take a minute.</span>
          </form>
          <p class="small text-muted mt-3 mb-0">What you upload is sent to your AI provider (Integrations → AI) and counts toward the monthly limit. The files are kept only until you apply or discard the review; you can keep them as client documents when you apply.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check me-2"></i>Waiting for review</h3></div>
      <div class="card-body">
        <?php if (!$waiting): ?>
          <p class="small text-muted mb-0">None. Reviews left for 14 days are discarded.</p>
        <?php else: ?>
          <ul class="list-unstyled small mb-0">
            <?php foreach ($waiting as $w): $names = array_column(json_decode((string) $w['files'], true) ?: [], 'name'); ?>
              <li class="mb-2"><a href="/clients/<?= $cid ?>/ai-documents/<?= (int) $w['id'] ?>?from=<?= e($from) ?>"><?= e(implode(', ', $names) ?: 'Document') ?></a>
                <div class="text-muted"><?= e($w['created_name'] ?: '—') ?>, <?= e(rel_time($w['created_at'])) ?></div></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

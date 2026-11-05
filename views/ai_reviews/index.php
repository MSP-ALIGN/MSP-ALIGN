<?php
/**
 * 2.30.0 Review documents with AI (a client's Compliance page, or a document's page with that document ticked): pick
 * the client's documents, upload more if you like, choose the frameworks, see roughly how many tokens it may use,
 * and the reviews still waiting.
 * @var array $client, $docs (Review::documents), $frameworks (Review::frameworks), $waiting; int $preselect, $remaining;
 *      bool $usable; ?string $why
 * Security: titles, names and file names come from people (escaped); the form posts with a CSRF token. The token
 * estimate is worked out in app.js from the data-tokens / data-groups numbers written here (integers).
 */
use Align\Ai\Compliance\Review;
use Align\Ai\Documents\Documents as Uploads;
use Align\Docs\Documents;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
?>
<div class="small mb-1"><a href="/clients/<?= $cid ?>/compliance">Compliance</a> /</div>
<h1 class="h4 mb-3"><i class="fas fa-wand-magic-sparkles text-secondary me-2"></i>Review documents with AI</h1>
<div class="row">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-body">
        <p class="small text-muted">AI reads <?= e($client['name']) ?>'s policies, procedures and plans, and suggests an answer for each control they say something about, with the words it found. It also lists the controls nothing was found for, and documents that look out of date. Nothing changes until you review it and press <b>Apply</b>.</p>
        <?php if (!$usable): ?>
          <div class="alert alert-secondary py-2 mb-0"><i class="fas fa-circle-info me-1"></i><?= e($why ?? 'AI isn\'t available.') ?></div>
        <?php else: ?>
          <form method="post" action="/clients/<?= $cid ?>/ai-reviews" enctype="multipart/form-data" data-ai-upload data-ai-estimate-form>
            <?= csrf_field() ?>
            <h2 class="h6 mt-1">Documents</h2>
            <?php if (!$docs): ?>
              <p class="small text-muted">This client has no active documents AI can read (pages, or PDF, Word, Excel and CSV files). Upload files below.</p>
            <?php else: ?>
              <div class="border rounded p-2 mb-2" style="max-height:18rem;overflow:auto">
                <?php foreach ($docs as $d): $cat = Documents::category($d['category']); ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="docs[]" value="<?= (int) $d['id'] ?>" id="aid-<?= (int) $d['id'] ?>" data-tokens="<?= (int) $d['tokens'] ?>"<?= $preselect === (int) $d['id'] ? ' checked' : '' ?>>
                    <label class="form-check-label fw-normal" for="aid-<?= (int) $d['id'] ?>"><?= e($d['title']) ?>
                      <span class="small text-muted">· <?= e($cat[0]) ?><?= $d['kind'] === 'file' ? ' · ' . e(strtoupper((string) $d['file_type'])) : '' ?></span></label>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="form-text mb-2">Active documents only: pages, and PDF, Word, Excel and CSV files. Up to <?= Review::MAX_DOCS ?> at a time.</div>
            <?php endif; ?>
            <div class="mb-3">
              <label for="ai-files">Upload more (optional)</label>
              <input type="file" id="ai-files" name="files[]" class="form-control" multiple accept="<?= e(Uploads::ACCEPT) ?>">
              <div class="form-text">PDF, Word (.docx), Excel (.xlsx), CSV, text, or an email (.eml or Outlook .msg). Up to <?= Uploads::MAX_FILES ?> files, <?= Uploads::MAX_BYTES >> 20 ?> MB each. You can keep them as client documents when you apply.</div>
            </div>
            <h2 class="h6">Frameworks</h2>
            <?php if (!$frameworks): ?>
              <p class="small text-muted">No active frameworks. An admin can turn one on under Compliance → Frameworks.</p>
            <?php endif; ?>
            <?php $shownOther = false; foreach ($frameworks as $f): ?>
              <?php if (!$f['assigned'] && !$shownOther): $shownOther = true; ?>
                <div class="small text-muted mt-2 mb-1">Also check against (not assigned to <?= e($client['name']) ?>; applying an answer adds the framework):</div>
              <?php endif; ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="frameworks[]" value="<?= (int) $f['id'] ?>" id="aif-<?= (int) $f['id'] ?>" data-groups="<?= (int) $f['groups'] ?>"<?= $f['assigned'] ? ' checked' : '' ?>>
                <label class="form-check-label fw-normal" for="aif-<?= (int) $f['id'] ?>"><?= e($f['name']) ?></label>
              </div>
            <?php endforeach; ?>
            <p class="small mt-3 mb-2" data-ai-estimate data-batch="<?= Review::BATCH ?>" data-remaining="<?= (int) $remaining ?>">
              <i class="fas fa-gauge me-1 text-secondary"></i>This may use up to about <b data-ai-estimate-tokens>0</b> tokens. <?= e(number_format($remaining)) ?> are left this month.
              <span class="text-danger d-none" data-ai-estimate-over>That's more than is left: choose fewer documents or frameworks.</span>
            </p>
            <button class="btn btn-primary" data-ai-submit><i class="fas fa-wand-magic-sparkles me-1"></i>Read with AI</button>
            <span class="small text-muted ms-2 d-none" data-ai-wait><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Reading… this can take a minute.</span>
          </form>
          <p class="small text-muted mt-3 mb-0">What you choose is sent to your AI provider (Integrations → AI) and counts toward the monthly limit. AI first reads the documents, then checks the controls in steps on the next page. Uploaded files are kept only until you apply or discard the review.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check me-2"></i>Waiting for review</h3></div>
      <div class="card-body">
        <?php if (!$waiting): ?>
          <p class="small text-muted mb-0">None. Reviews left for 14 days are discarded.</p>
        <?php else: ?>
          <ul class="list-unstyled small mb-0">
            <?php foreach ($waiting as $w): $names = array_column(json_decode((string) $w['sources'], true) ?: [], 'name'); ?>
              <li class="mb-2"><a href="/clients/<?= $cid ?>/ai-reviews/<?= (int) $w['id'] ?>"><?= e(mb_strimwidth(implode(', ', array_unique($names)) ?: 'Review', 0, 120, '…')) ?></a>
                <div class="text-muted"><?= e(implode(', ', array_column(json_decode((string) $w['frameworks'], true) ?: [], 'name'))) ?></div>
                <div class="text-muted"><?= e($w['created_name'] ?: '—') ?>, <?= e(rel_time($w['created_at'])) ?></div></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

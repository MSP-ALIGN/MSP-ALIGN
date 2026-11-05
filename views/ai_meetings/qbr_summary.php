<?php
/**
 * 2.31.0 A client's QBR executive summary: write it with AI (or by hand), edit it, and "Use in report" so the staff
 * QBR pack prints it at the top of its executive summary page. Never in the client portal.
 * @var array $client; ?array $saved (qbr_summaries row); ?string $draft (AI's text, not saved yet); bool $usable;
 *   ?string $why; ?string $digest (what AI is sent, when asked for with ?digest=1)
 * Security: the summary, AI's draft and the digest are untrusted text, escaped here; the preview is Summary::html()
 * (escaped, then only its own tags). The forms post with a CSRF token; the controller checks the role again.
 */
use Align\Ai\Meetings\Summary;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$text = $draft ?? ($saved['body'] ?? '');
?>
<div class="small mb-1"><a href="/clients/<?= $cid ?>/reports">Reports</a> /</div>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-wand-magic-sparkles text-secondary me-2"></i>QBR executive summary</h1>
  <a class="btn btn-sm btn-default mt-2 mt-md-0" href="/clients/<?= $cid ?>/report/qbr" target="_blank" rel="noopener"><i class="fas fa-book-open me-1"></i>Open the QBR pack</a>
</div>
<p class="text-muted small">A few plain-language paragraphs for the business owner and the top 3 recommendations, printed at the top of the QBR pack's executive summary. It's in the pack you print; the client portal's pack never shows it.</p>

<div class="row">
  <div class="col-lg-7">
    <div class="card" id="ai-summary">
      <div class="card-header py-2 d-flex align-items-center"><h3 class="card-title mt-1 me-auto"><?= $draft !== null ? 'Written by AI: check and edit it' : 'Summary' ?></h3>
        <?php if ($usable): ?>
          <form method="post" action="/clients/<?= $cid ?>/qbr-summary/ai" data-ai-upload><?= csrf_field() ?>
            <span class="small text-muted d-none me-1" data-ai-wait><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Writing… this can take a minute.</span>
            <button class="btn btn-sm btn-primary" data-ai-submit<?= $text !== '' ? ' data-confirm="Write a new summary with AI? It replaces the text below until you save it." data-confirm-ok="Write"' : '' ?>><i class="fas fa-wand-magic-sparkles me-1"></i>Write a summary with AI</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$usable && $why !== null): ?><div class="alert alert-secondary py-2 small"><i class="fas fa-circle-info me-1"></i><?= e($why) ?> You can still write the summary yourself.</div><?php endif; ?>
        <?php if ($draft !== null): ?><div class="alert alert-warning py-2 small"><i class="fas fa-triangle-exclamation me-1"></i>Not saved yet. AI can be wrong: check every number and statement against the pack before you use it.</div><?php endif; ?>
        <form method="post" action="/clients/<?= $cid ?>/qbr-summary" data-unsaved><?= csrf_field() ?>
          <textarea name="body" class="form-control mb-2" rows="16" aria-label="Summary" placeholder="Three to five short paragraphs, then Top 3 recommendations with 1. 2. 3."><?= e($text) ?></textarea>
          <div class="form-text mb-2">Plain text. A blank line starts a new paragraph; lines starting with 1. or - make a list; **two stars** make bold.</div>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Use in report</button>
          <?php if ($saved): ?>
            <button class="btn btn-default ms-1" name="action" value="delete" data-confirm="Remove the summary from the QBR pack?" data-confirm-ok="Remove"><i class="fas fa-trash me-1"></i>Remove</button>
            <span class="small text-muted ms-2">Saved by <?= e($saved['updated_name'] ?: '—') ?> <?= e(rel_time($saved['updated_at'])) ?><?= (int) $saved['from_ai'] ? ' (written with AI)' : '' ?>.</span>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <?php if ($saved): ?>
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1">In the QBR pack</h3></div>
        <div class="card-body small ai-summary"><?= Summary::html((string) $saved['body']) ?></div>
      </div>
    <?php endif; ?>
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1">What AI is sent</h3></div>
      <div class="card-body small">
        <p class="text-muted">Numbers and short labels about <?= e($client['name']) ?>: health, changes since the last review, service levels, devices, licensing, backups, security, compliance, alignment, roadmap and budget. No contacts' names, email addresses, phone numbers, device names or serial numbers. Titles your team types (projects, renewals, budget lines) are sent as written, so keep people's names out of them.</p>
        <?php if ($digest !== null): ?><pre class="small border rounded p-2 mb-0" style="white-space: pre-wrap"><?= e($digest) ?></pre>
        <?php else: ?><a href="/clients/<?= $cid ?>/qbr-summary?digest=1#ai-summary">Show it</a><?php endif; ?>
      </div>
    </div>
  </div>
</div>

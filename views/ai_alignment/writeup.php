<?php
/**
 * 2.32.0 A finished alignment review's write-up: write the recommendations with AI (or by hand), edit them and Save
 * them with the review. The review page shows them; the staff QBR pack prints them in its Alignment section (for the
 * newest review). Never in the client portal.
 * @var array $client, $review (a finished alignment_reviews row of this client); ?array $saved (alignment_writeups row);
 *   ?string $draft (AI's text, not saved yet); bool $usable; ?string $why; ?string $digest (what AI is sent, with ?digest=1)
 * Security: the write-up, AI's draft and the digest are untrusted text, escaped here; the preview is Summary::html()
 * (escaped, then only its own tags). The forms post with a CSRF token; the controller checks the role and the review again.
 */
use Align\Ai\Meetings\Summary;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$rid = (int) $review['id'];
$text = $draft ?? ($saved['body'] ?? '');
?>
<div class="small mb-1"><a href="/clients/<?= $cid ?>/alignment">Alignment</a> / <a href="/clients/<?= $cid ?>/alignment/reviews/<?= $rid ?>">Review of <?= e(fmt_date($review['finished_at'])) ?></a> /</div>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-wand-magic-sparkles text-secondary me-2"></i>Alignment write-up</h1>
</div>
<p class="text-muted small">About a page for the business owner: where they stand, then recommendations by priority. It's kept with this review (<?= $review['score'] !== null ? (int) $review['score'] . '%' : 'no score' ?>, <?= (int) $review['misaligned'] ?> misaligned) and printed in the QBR pack's Alignment section while it's the newest review. The client portal never shows it.</p>

<div class="row">
  <div class="col-lg-7">
    <div class="card" id="ai-writeup">
      <div class="card-header py-2 d-flex align-items-center"><h3 class="card-title mt-1 me-auto"><?= $draft !== null ? 'Written by AI: check and edit it' : 'Write-up' ?></h3>
        <?php if ($usable): ?>
          <form method="post" action="/clients/<?= $cid ?>/alignment/reviews/<?= $rid ?>/writeup/ai" data-ai-upload><?= csrf_field() ?>
            <span class="small text-muted d-none me-1" data-ai-wait><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Writing… this can take a minute.</span>
            <button class="btn btn-sm btn-primary" data-ai-submit<?= $text !== '' ? ' data-confirm="Write new recommendations with AI? They replace the text below until you save." data-confirm-ok="Write"' : '' ?>><i class="fas fa-wand-magic-sparkles me-1"></i>Write recommendations with AI</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$usable && $why !== null): ?><div class="alert alert-secondary py-2 small"><i class="fas fa-circle-info me-1"></i><?= e($why) ?> You can still write it yourself.</div><?php endif; ?>
        <?php if ($draft !== null): ?><div class="alert alert-warning py-2 small"><i class="fas fa-triangle-exclamation me-1"></i>Not saved yet. AI can be wrong: check every statement against the review before you save it.</div><?php endif; ?>
        <form method="post" action="/clients/<?= $cid ?>/alignment/reviews/<?= $rid ?>/writeup" data-unsaved><?= csrf_field() ?>
          <textarea name="body" class="form-control mb-2" rows="20" aria-label="Write-up" placeholder="A short opening on where they stand, then ## High priority, ## Medium priority and ## Low priority with a line starting - for each recommendation."><?= e($text) ?></textarea>
          <div class="form-text mb-2">Plain text. A blank line starts a new paragraph; a line starting ## is a heading; lines starting with 1. or - make a list; **two stars** make bold.</div>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Save</button>
          <?php if ($saved): ?>
            <button class="btn btn-default ms-1" name="action" value="delete" data-confirm="Remove the write-up from this review?" data-confirm-ok="Remove"><i class="fas fa-trash me-1"></i>Remove</button>
            <span class="small text-muted ms-2">Saved by <?= e($saved['updated_name'] ?: '—') ?> <?= e(rel_time($saved['updated_at'])) ?><?= (int) $saved['from_ai'] ? ' (written with AI)' : '' ?>.</span>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <?php if ($saved): ?>
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1">Saved with the review</h3></div>
        <div class="card-body small ai-summary"><?= Summary::html((string) $saved['body']) ?></div>
      </div>
    <?php endif; ?>
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1">What AI is sent</h3></div>
      <div class="card-body small">
        <p class="text-muted">About this review of <?= e($client['name']) ?>: the score and counts, how each area did, and each standard not met with its priority, why it matters, how you check it and its usual fix (from your standards library), and the roadmap projects linked to it. Never the notes on answers, contacts' names, email addresses, phone numbers, device names or serial numbers. Titles your team types (projects, renewals, budget lines) are sent as written, so keep people's names out of them.</p>
        <?php if ($digest !== null): ?><pre class="small border rounded p-2 mb-0" style="white-space: pre-wrap"><?= e($digest) ?></pre>
        <?php else: ?><a href="/clients/<?= $cid ?>/alignment/reviews/<?= $rid ?>/writeup?digest=1#ai-writeup">Show it</a><?php endif; ?>
      </div>
    </div>
  </div>
</div>

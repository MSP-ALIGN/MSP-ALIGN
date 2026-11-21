<?php
/**
 * 2.38.1 Related documents checklist (Docs\Related): the documents a program document relies on, or the documents a
 * framework needs, each with where the client stands (Active, review overdue, Draft, missing) and, for techs and
 * admins, a way to create a missing one from its template or draft it with AI (with the facts of the client's newest
 * compliance review, when there is one).
 * @var array $client, $rows (Related::status); string $heading, $intro; bool $canEdit, $ai; int $review
 * 2.38.2 Required documents (Related::REQUIRED, each with the section that asks for it) are listed first and are the
 * only ones counted; supporting ones are folded into "Supporting documents (optional)", closed by default. With no
 * required documents, only that fold shows, with $none as the line above it.
 * @var string $none (2.38.2) the line shown when nothing is required; $word 'required' or 'expected' (Related::word); $note a line under the list (Related::FRAMEWORK_NOTE)
 * Security: template names, descriptions and document titles are escaped; every form posts with a CSRF token, and the
 * handlers check the role and that the template exists.
 */
use Align\Docs\Related;

if (!$rows) {
    return;
}
$cid = (int) $client['id'];
$word = ($word ?? 'required') === 'expected' ? 'expected' : 'required';
$req = array_values(array_filter($rows, fn($r) => !empty($r['required'])));
$opt = array_values(array_filter($rows, fn($r) => empty($r['required'])));
$active = fn(array $list) => count(array_filter($list, fn($r) => $r['state'] === 'active'));
/** One row: name, state, note or control count, and what to do next. */
$row = function (array $r) use ($cid, $canEdit, $ai, $review): void {
    [$label, $tone] = Related::STATES[$r['state']];
    $d = $r['doc']; ?>
        <li class="list-group-item d-flex flex-wrap align-items-center gap-2" data-related="<?= e($r['slug']) ?>" data-state="<?= e($r['state']) ?>" data-required="<?= empty($r['required']) ? '0' : '1' ?>">
          <div class="me-auto" style="min-width: 0; flex: 1 1 16rem">
            <div><?php if ($d): ?><a href="/documents/<?= (int) $d['id'] ?>"><?= e($r['name']) ?></a><?php else: ?><?= e($r['name']) ?><?php endif; ?>
              <span class="badge text-bg-<?= e($tone) ?> ms-1"><?= e($label) ?></span>
              <?php if (empty($r['required']) && ($r['controls'] ?? null) !== null): ?><span class="small text-muted ms-1"><?= (int) $r['controls'] ?> control<?= (int) $r['controls'] === 1 ? '' : 's' ?></span><?php endif; ?></div>
            <?php if (!empty($r['note'])): ?><div class="small text-muted"><?= e($r['note']) ?></div><?php endif; ?>
            <?php if ($d && $d['title'] !== $r['name']): ?><div class="small text-muted text-truncate"><?= e($d['title']) ?></div><?php endif; ?>
            <?php if ($r['state'] === 'overdue'): ?><div class="small text-warning-emphasis">Review was due <?= e(fmt_date($d['review_due'])) ?></div><?php endif; ?>
          </div>
          <?php if ($canEdit && $r['state'] === 'missing'): ?>
            <div class="d-flex flex-wrap gap-1">
              <?php /* 2.41.0 the template's form: answer its questions, then make the draft */ ?>
              <a class="btn btn-sm btn-primary" href="/clients/<?= $cid ?>/document-answers?template=<?= (int) $r['id'] ?>"><i class="fas fa-list-check me-1"></i>Fill the form</a>
              <form method="post" action="/documents" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="client_id" value="<?= $cid ?>"><input type="hidden" name="template_id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm btn-default"><i class="fas fa-plus me-1"></i>Create from template</button>
              </form>
              <?php if ($ai): ?>
                <form method="post" action="/clients/<?= $cid ?>/ai-drafts/template/<?= (int) $r['id'] ?>" class="d-inline" data-ai-upload>
                  <?= csrf_field() ?><input type="hidden" name="review_id" value="<?= (int) $review ?>">
                  <button class="btn btn-sm btn-default" data-ai-submit title="<?= $review ? 'Uses the facts of the newest compliance review' : 'Uses Align\'s details about the client' ?>"><i class="fas fa-wand-magic-sparkles me-1"></i>Draft with AI</button>
                  <span class="small text-muted d-none" data-ai-wait><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Writing…</span>
                </form>
              <?php endif; ?>
            </div>
          <?php elseif ($r['state'] === 'draft'): ?>
            <a class="btn btn-sm btn-default" href="/documents/<?= (int) $d['id'] ?>">Finish the draft</a>
          <?php elseif ($r['state'] === 'overdue'): ?>
            <a class="btn btn-sm btn-default" href="/documents/<?= (int) $d['id'] ?>">Review it</a>
          <?php else: ?>
            <i class="fas fa-circle-check text-success" aria-hidden="true"></i>
          <?php endif; ?>
        </li>
<?php };
?>
<div class="card card-dark" id="related-docs">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check me-2"></i><?= e($heading) ?></h3>
    <?php if ($req): ?><div class="card-tools small text-body-secondary" data-related-count><?= $active($req) ?> of <?= count($req) ?> <?= e($word) ?> in place</div><?php endif; ?>
  </div>
  <div class="card-body p-0">
    <p class="small text-muted px-3 pt-2 mb-2"><?= e($req ? $intro : ($none ?? $intro)) ?></p>
    <?php if ($req): ?>
      <ul class="list-group list-group-flush" data-related-required><?php foreach ($req as $r) { $row($r); } ?></ul>
    <?php endif; ?>
    <?php if (!empty($note)): ?><p class="small text-muted px-3 py-2 mb-0 border-top" data-related-note><?= e($note) ?></p><?php endif; ?>
    <?php if ($opt): /* supporting documents: closed, so the required ones stand out */ ?>
      <details class="border-top" data-related-optional>
        <summary class="px-3 py-2 small">Supporting documents (optional) · <?= $active($opt) ?> of <?= count($opt) ?> in place</summary>
        <ul class="list-group list-group-flush"><?php foreach ($opt as $r) { $row($r); } ?></ul>
      </details>
    <?php endif; ?>
  </div>
</div>

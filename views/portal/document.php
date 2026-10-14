<?php
/**
 * One shared document. The body is printed as HTML on purpose: it was cleaned by the allowlist sanitizer
 * (Docs\Html::clean) when it was saved, and the page's CSP allows no inline script. Everything else is escaped.
 * 2.20.0: an uploaded file is shown (PDFs, pictures) or offered as a download instead (documents/_file_body).
 * @var array $doc
 */
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <div class="me-auto"><a href="/portal/documents" class="small"><i class="fas fa-arrow-left me-1"></i>Documents</a>
    <h1 class="h4 mb-0"><?= e($doc['title']) ?></h1>
    <div class="small text-muted">Version <?= (int) $doc['version'] ?> · updated <?= e(fmt_date($doc['updated_at'])) ?><?= $doc['review_due'] ? ' · next review ' . e(fmt_date($doc['review_due'])) : '' ?></div></div>
  <?php if ($doc['kind'] !== 'file'): ?><a class="btn btn-sm btn-default mt-2 mt-md-0" href="/portal/documents/<?= (int) $doc['id'] ?>?print=1" target="_blank"><i class="fas fa-print me-1"></i>Print / PDF</a><?php endif; ?>
</div>
<?php if ($doc['kind'] === 'file'): // 2.20.0 an uploaded file ?>
<div class="card"><div class="card-body"><?= \Align\View::fetch('documents/_file_body', ['src' => '/portal/documents/' . (int) $doc['id'] . '/file', 'f' => $doc]) ?></div></div>
<?php else: ?>
<div class="card"><div class="card-body doc-print portal-doc"><?= $doc['body_html'] ?></div></div>
<?php endif; ?>

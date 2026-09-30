<?php /** @var array $doc */ ?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <div class="me-auto"><a href="/portal/documents" class="small"><i class="fas fa-arrow-left me-1"></i>Documents</a>
    <h1 class="h4 mb-0"><?= e($doc['title']) ?></h1>
    <div class="small text-muted">Version <?= (int) $doc['version'] ?> · updated <?= e(fmt_date($doc['updated_at'])) ?><?= $doc['review_due'] ? ' · next review ' . e(fmt_date($doc['review_due'])) : '' ?></div></div>
  <a class="btn btn-sm btn-default mt-2 mt-md-0" href="/portal/documents/<?= (int) $doc['id'] ?>?print=1" target="_blank"><i class="fas fa-print me-1"></i>Print / PDF</a>
</div>
<div class="card"><div class="card-body doc-print portal-doc"><?= $doc['body_html'] ?></div></div>

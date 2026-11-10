<?php
/**
 * 2.35.0 A document the PSA (ITFlow) shares with this client, read live. The body is printed as HTML on purpose: it
 * was cleaned by the allowlist sanitizer (Docs\Html::clean, which also drops images: they live behind the PSA's own
 * sign-in) in Portal\Support::document, and the page's CSP allows no inline script. Everything else is escaped.
 * (2.36.0) "From" names the MSP ($provider), not the PSA product.
  * @var array $d ['name', 'description', 'updated_at', 'html']
 */
?>
<div class="portal-page-head"><a href="/portal/documents" class="small"><i class="fas fa-arrow-left me-1"></i>Documents</a>
  <h1 class="h4 mb-0"><?= e($d['name']) ?></h1>
  <div class="small text-muted"><?= e(implode(' · ', array_filter(['From ' . ((string) ($provider['company'] ?? '') ?: 'your IT team'), $d['updated_at'] ? 'updated ' . fmt_date($d['updated_at']) : null, $d['description'] ?: null]))) ?></div></div>
<div class="card"><div class="card-body doc-print portal-doc"><?= $d['html'] !== '' ? $d['html'] : '<p class="text-muted">This document is empty.</p>' ?></div></div>

<?php
/**
 * 2.20.0 An uploaded document's file in the page: PDFs painted by PDF.js (pdfview.js, no scripts or forms), images
 * in an <img>, anything else (Word, Excel, CSV) as a download card. Shared by the staff page, a version in the
 * history and the client portal.
 * @var string $src the file's same-site URL (the server built it); array $f file_orig, file_type, file_size
 * Every value is escaped.
 */
use Align\Docs\Files;

$t = Files::TYPES[$f['file_type']] ?? ['application/octet-stream', 'File', 'fa-file', false];
$dl = $src . (str_contains($src, '?') ? '&' : '?') . 'download=1';
?>
<div class="doc-file">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <i class="fas fa-fw <?= e($t[2]) ?> fa-lg text-secondary"></i>
    <span class="me-auto text-break"><b><?= e((string) $f['file_orig']) ?></b> <span class="text-muted small">· <?= e($t[1]) ?> · <?= e(Files::size((int) $f['file_size'])) ?></span></span>
    <a class="btn btn-sm btn-primary" href="<?= e($dl) ?>"><i class="fas fa-download me-1"></i>Download</a>
  </div>
  <?php if ($f['file_type'] === 'pdf'): ?>
    <div class="pv" data-pv data-mode="view" data-src="<?= e($src) ?>" data-v="<?= e(APP_VERSION) ?>">
      <script type="application/json" class="pv-data">{"items":[],"pages":[]}</script>
      <div class="pv-pages"><div class="pv-loading text-muted small p-3"><i class="fas fa-spinner fa-spin me-1"></i>Loading the pages…</div></div>
    </div>
  <?php elseif (in_array($f['file_type'], ['png', 'jpg'], true)): ?>
    <div class="text-center doc-file-image"><img src="<?= e($src) ?>" alt="<?= e((string) $f['file_orig']) ?>" class="img-fluid border rounded"></div>
  <?php else: ?>
    <div class="border rounded p-4 text-center text-muted">
      <i class="fas <?= e($t[2]) ?> fa-3x mb-2"></i>
      <div><?= e($t[1]) ?> files open in their own program: press <b>Download</b>.</div>
    </div>
  <?php endif; ?>
</div>

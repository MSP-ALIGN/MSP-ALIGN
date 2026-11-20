<?php
/**
 * 2.39.0 "N blanks still to fill" in the Send for signature and Ask to acknowledge dialogs of a written document.
 * The count is the saved body's (Docs\Blanks::count) when the page opened; docs.js keeps it current as the editor
 * changes ([data-blank-warning]). Uploaded files have no blanks. A warning only: sending is still allowed.
 * Expects $doc. Numbers only, nothing from the document's text.
 */
if (($doc['kind'] ?? 'page') !== 'page') {
    return;
}
$n = \Align\Docs\Blanks::count((string) $doc['body_html']);
?>
<div class="alert alert-warning small py-2" data-blank-warning<?= $n ? '' : ' hidden' ?>>
  <i class="fas fa-pen-to-square me-1"></i>This document still has <b data-blank-n><?= $n ?></b> <span data-blank-word><?= $n === 1 ? 'blank' : 'blanks' ?></span> to fill, like <code>[name, title]</code>. The Blanks list beside the editor shows where.
</div>

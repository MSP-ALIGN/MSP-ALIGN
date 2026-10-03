<?php /* Staff and portal printouts. body_html is printed as is: it was cleaned by Docs\Html::clean when saved. */ ?>
<div class="doc-print"><?= $doc['body_html'] ?></div>
<?php if ($doc['status'] === 'draft'): ?><div class="doc-draft-mark no-print-hide">DRAFT</div><?php endif; ?>

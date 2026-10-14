<?php
use Align\Auth;

if ($client) {
    require __DIR__ . '/../partials/client_header.php';
}
$kindLabel = ['auto' => 'Autosave', 'manual' => 'Saved version', 'restore' => 'Restored', 'created' => 'Created', 'upload' => 'File uploaded'];
$isFile = !empty($v['file_name']); // 2.20.0 a version of an uploaded document keeps its own file
$canRestore = !$isFile ? $doc['kind'] !== 'file' : $doc['kind'] === 'file' && $v['file_name'] !== $doc['file_name'];
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <div class="me-auto">
    <div class="small"><a href="/documents/<?= (int) $doc['id'] ?>"><?= e($doc['title']) ?></a> / history</div>
    <h1 class="h4 mb-0">Version <?= (int) $v['version'] ?> <small class="text-muted"><?= e($kindLabel[$v['kind']] ?? $v['kind']) ?> · <?= e(fmt_datetime($v['saved_at'])) ?><?= $v['saved_by_name'] ? ' · ' . e($v['saved_by_name']) : '' ?></small></h1>
    <?php if ($v['note']): ?><div class="text-muted small"><?= e($v['note']) ?></div><?php endif; ?>
  </div>
  <div class="btn-group btn-group-sm">
    <a class="btn btn-default" href="/documents/<?= (int) $doc['id'] ?>"><i class="fas fa-arrow-left me-1"></i>Back to current</a>
    <?php if (Auth::can('tech') && $canRestore): ?>
      <form method="post" action="/documents/<?= (int) $doc['id'] ?>/versions/<?= (int) $v['id'] ?>/restore" class="d-inline"><?= csrf_field() ?>
        <button class="btn btn-primary btn-sm rounded-0" data-confirm="Replace the current document with this version? The current content is kept in the history."><i class="fas fa-rotate-left me-1"></i>Restore this version</button></form>
    <?php endif; ?>
  </div>
</div>
<div class="card doc-card">
  <div class="card-body">
    <h2 class="h4"><?= e($v['title']) ?></h2>
    <?php if ($isFile): ?>
      <?= \Align\View::fetch('documents/_file_body', ['src' => '/documents/' . (int) $doc['id'] . '/file?v=' . (int) $v['id'], 'f' => $v]) ?>
    <?php else: ?>
    <div class="ql-snow"><div class="ql-editor doc-readonly"><?= $v['body_html'] /* cleaned when saved */ ?></div></div>
    <?php endif; ?>
  </div>
</div>

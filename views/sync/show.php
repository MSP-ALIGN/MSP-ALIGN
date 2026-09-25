<?php $sum = json_decode((string) $run['summary'], true) ?: []; ?>
<header class="page-head">
  <div>
    <div class="crumbs"><a href="/sync">Sync</a></div>
    <h1>Sync #<?= (int) $run['id'] ?></h1>
    <div class="muted"><?= e(fmt_date($run['started_at'])) ?> <?= e(date('g:i a', strtotime($run['started_at']))) ?> · <?= e($run['triggered_by']) ?> · <?= e($run['status']) ?></div>
  </div>
</header>
<div class="card">
  <h2>Steps</h2>
  <dl>
    <?php foreach ($sum as $step => $result): ?>
      <div class="kv"><dt><?= e($step) ?></dt><dd class="<?= str_starts_with((string) $result, 'ERROR') ? 'text-bad' : '' ?>"><?= e($result) ?></dd></div>
    <?php endforeach; ?>
  </dl>
</div>
<div class="card">
  <h2>Log</h2>
  <pre class="log"><?= e($run['log']) ?></pre>
</div>

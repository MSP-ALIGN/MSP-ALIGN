<?php
use Align\Contracts\Contracts;

/** Check a PDF against the signed copies Align keeps. @var ?array $result */
?>
<div class="small"><a href="/contracts">Contracts</a> /</div>
<?= \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-fingerprint', 'title' => 'Check a signed contract',
    'desc' => 'Someone sent you a copy of a contract? Check it\'s exactly the signed copy kept here: its fingerprint (SHA-256) has to match. Any change to the file, even one character, gives a different fingerprint.',
]) ?>
<div class="row"><div class="col-xl-7">
  <form method="post" action="/contracts/verify" enctype="multipart/form-data" class="card card-body">
    <?= csrf_field() ?>
    <label for="vf-file">PDF to check</label>
    <div class="input-group"><input type="file" id="vf-file" name="file" class="form-control" accept="application/pdf,.pdf" required><button class="btn btn-primary">Check</button></div>
    <div class="form-text">The file isn't kept.</div>
  </form>
  <?php if ($result): ?>
    <?php if ($result['contract']): $k = $result['contract']; ?>
      <div class="alert alert-success"><i class="fas fa-circle-check me-2"></i><b>It matches.</b> "<?= e($result['name']) ?>" is the signed copy of
        <a href="/contracts/<?= (int) $k['id'] ?>" class="alert-link"><?= e($k['title']) ?> (<?= e(Contracts::number($k)) ?>)</a> for <?= e(Contracts::party($k)) ?>, <?= $k['source'] === 'uploaded' ? 'uploaded' : 'completed' ?> <?= e(fmt_date($k['completed_at'])) ?>.</div>
    <?php else: ?>
      <div class="alert alert-danger"><i class="fas fa-circle-xmark me-2"></i><b>No match.</b> "<?= e($result['name']) ?>" isn't a signed copy kept here, or it was changed after signing.</div>
    <?php endif; ?>
    <div class="small text-muted">Fingerprint of the file you checked: <code class="text-break"><?= e($result['hash']) ?></code></div>
  <?php endif; ?>
</div></div>

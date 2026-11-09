<?php
use Align\Docs\Documents;

/** @var array $docs; array $signWaiting (2.34.0, portal/_signatures); array $ackWaiting (2.34.0, portal/_acknowledgments); array $shared (2.35.0, the PSA's shared documents) */
$shared = $shared ?? [];
$groups = [];
foreach ($docs as $d) {
    $groups[$d['category']][] = $d;
}
?>
<div class="mb-3"><h1 class="h4 mb-0"><i class="fas fa-file-lines me-2 text-secondary"></i>Documents</h1>
  <div class="small text-muted">Policies, plans and procedures your IT provider maintains for your organization.</div></div>
<?= \Align\View::fetch('portal/_signatures', ['signWaiting' => $signWaiting ?? []]) /* 2.34.0 */ ?>
<?= \Align\View::fetch('portal/_acknowledgments', ['ackWaiting' => $ackWaiting ?? []]) /* 2.34.0 */ ?>
<?php if (!$docs && !$shared): ?><div class="card"><?= \Align\View::fetch('partials/empty', ['icon' => 'fa-file-lines', 'text' => 'No documents have been shared with you yet.']) ?></div><?php endif; ?>
<?php foreach (Documents::CATEGORIES as $cat => [$label, $icon, $tone]): if (empty($groups[$cat])) continue; ?>
  <div class="card">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw <?= $icon ?> text-<?= $tone ?> me-2"></i><?= e($label) ?></h3></div>
    <ul class="list-group list-group-flush">
      <?php foreach ($groups[$cat] as $d): ?>
        <li class="list-group-item py-2 d-flex align-items-center">
          <a href="/portal/documents/<?= (int) $d['id'] ?>" class="fw-bold me-auto"><?= e($d['title']) ?></a>
          <span class="small text-muted text-nowrap ms-2">Updated <?= e(fmt_date($d['updated_at'])) ?></span>
          <?php if ($d['kind'] === 'file'): // 2.20.0 ?><a href="/portal/documents/<?= (int) $d['id'] ?>/file?download=1" class="btn btn-xs btn-default ms-2" title="Download (<?= e((\Align\Docs\Files::TYPES[$d['file_type']][1] ?? 'File') . ', ' . \Align\Docs\Files::size((int) $d['file_size'])) ?>)" aria-label="Download <?= e($d['title']) ?>"><i class="fas fa-download"></i></a>
          <?php else: ?><a href="/portal/documents/<?= (int) $d['id'] ?>?print=1" target="_blank" class="btn btn-xs btn-default ms-2" title="Print or save as PDF"><i class="fas fa-print"></i></a><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endforeach; ?>
<?php if ($shared): // 2.35.0 documents the PSA (ITFlow) shares with this client, read live when opened ?>
  <div class="card">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-book text-secondary me-2"></i>From <?= e(psa_name()) ?></h3></div>
    <ul class="list-group list-group-flush">
      <?php foreach ($shared as $d): ?>
        <li class="list-group-item py-2 d-flex align-items-center">
          <div class="me-auto"><a href="/portal/shared/<?= e(rawurlencode((string) $d['psa_id'])) ?>" class="fw-bold"><?= e($d['name']) ?></a>
            <?php if ($d['description']): ?><div class="small text-muted"><?= e($d['description']) ?></div><?php endif; ?></div>
          <?php if ($d['updated_at']): ?><span class="small text-muted text-nowrap ms-2">Updated <?= e(fmt_date($d['updated_at'])) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

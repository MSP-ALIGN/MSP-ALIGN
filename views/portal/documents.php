<?php
use Align\Docs\Documents;

/** @var array $docs */
$groups = [];
foreach ($docs as $d) {
    $groups[$d['category']][] = $d;
}
?>
<div class="mb-3"><h1 class="h4 mb-0"><i class="fas fa-file-lines mr-2 text-secondary"></i>Documents</h1>
  <div class="small text-muted">Policies, plans and procedures your IT provider maintains for your organization.</div></div>
<?php if (!$docs): ?><div class="card card-body text-muted">No documents have been shared with you yet.</div><?php endif; ?>
<?php foreach (Documents::CATEGORIES as $cat => [$label, $icon, $tone]): if (empty($groups[$cat])) continue; ?>
  <div class="card">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw <?= $icon ?> text-<?= $tone ?> mr-2"></i><?= e($label) ?></h3></div>
    <ul class="list-group list-group-flush">
      <?php foreach ($groups[$cat] as $d): ?>
        <li class="list-group-item py-2 d-flex align-items-center">
          <a href="/portal/documents/<?= (int) $d['id'] ?>" class="font-weight-bold mr-auto"><?= e($d['title']) ?></a>
          <span class="small text-muted text-nowrap ml-2">Updated <?= e(fmt_date($d['updated_at'])) ?></span>
          <a href="/portal/documents/<?= (int) $d['id'] ?>?print=1" target="_blank" class="btn btn-xs btn-default ml-2" title="Print or save as PDF"><i class="fas fa-print"></i></a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endforeach; ?>

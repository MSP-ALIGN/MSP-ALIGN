<?php
use Align\Docs\Documents;
use Align\Docs\Html;

/** @var array $docs  @var bool $showClient */
?>
<table class="table table-striped table-borderless table-hover mb-0" id="doc-table">
  <thead class="text-dark"><tr><th>Document</th><?php if ($showClient): ?><th>Client</th><?php endif; ?><th>Category</th><th>Status</th><th>Review due</th><th>Last updated</th></tr></thead>
  <tbody>
  <?php foreach ($docs as $d): [$cl, $ci, $cc] = Documents::category($d['category']); $overdue = $d['review_due'] && $d['review_due'] < date('Y-m-d') && $d['status'] === 'active'; ?>
    <tr class="<?= $d['status'] === 'archived' ? 'text-muted' : '' ?>">
      <td>
        <i class="fas fa-fw <?= $ci ?> text-<?= $cc ?> me-1"></i><a href="/documents/<?= (int) $d['id'] ?>" class="fw-bold"><?= e($d['title']) ?></a>
        <?php if ($d['evidence_count']): ?><span class="badge text-bg-light border ms-1" title="Linked as compliance evidence"><i class="fas fa-clipboard-check me-1"></i><?= (int) $d['evidence_count'] ?></span><?php endif; ?>
        <div class="small text-muted ms-4 text-truncate doc-excerpt"><?= e(Html::excerpt($d['body_html'], 140)) ?></div>
      </td>
      <?php if ($showClient): ?><td class="small"><?= $d['client_id'] ? '<a href="/clients/' . (int) $d['client_id'] . '/documents">' . e($d['client_name']) . '</a>' : '<span class="text-muted">Internal</span>' ?></td><?php endif; ?>
      <td class="small"><?= e($cl) ?></td>
      <td><span class="badge text-bg-<?= Documents::STATUSES[$d['status']][1] ?>"><?= e(Documents::STATUSES[$d['status']][0]) ?></span></td>
      <td class="small text-nowrap"><?= $d['review_due'] ? ($overdue ? '<span class="badge text-bg-warning">' . e(fmt_date($d['review_due'])) . '</span>' : e(fmt_date($d['review_due']))) : '—' ?></td>
      <td class="small text-nowrap"><?= e(rel_time($d['updated_at'])) ?><?= $d['updated_by_name'] ? '<div class="text-muted">' . e($d['updated_by_name']) . ' · v' . (int) $d['version'] . '</div>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$docs): ?><tr><td colspan="<?= $showClient ? 6 : 5 ?>" class="text-muted p-3">No documents yet.</td></tr><?php endif; ?>
  </tbody>
</table>

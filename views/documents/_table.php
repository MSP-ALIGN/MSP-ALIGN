<?php
use Align\Docs\Documents;
use Align\Docs\Html;

/** @var array $docs  @var bool $showClient (2.20.0: uploaded files show their type, name and size instead of an excerpt) */
?>
<table class="table table-striped table-borderless table-hover mb-0" id="doc-table">
  <thead class="text-dark"><tr><th>Document</th><?php if ($showClient): ?><th>Client</th><?php endif; ?><th>Category</th><th>Status</th><th>Review due</th><th>Last updated</th></tr></thead>
  <tbody>
  <?php foreach ($docs as $d): [$cl, $ci, $cc] = Documents::category($d['category']); $overdue = $d['review_due'] && $d['review_due'] < date('Y-m-d') && $d['status'] === 'active'; ?>
    <tr class="<?= $d['status'] === 'archived' ? 'text-muted' : '' ?>">
      <td>
        <?php $ft = ($d['kind'] ?? 'page') === 'file' ? (\Align\Docs\Files::TYPES[$d['file_type']] ?? null) : null; // 2.20.0 an uploaded file ?>
        <i class="fas fa-fw <?= $ft ? e($ft[2]) : $ci ?> text-<?= $cc ?> me-1"></i><a href="/documents/<?= (int) $d['id'] ?>" class="fw-bold"><?= e($d['title']) ?></a>
        <?php if ($d['evidence_count']): ?><span class="badge text-bg-light border ms-1" title="Linked as compliance evidence"><i class="fas fa-clipboard-check me-1"></i><?= (int) $d['evidence_count'] ?></span><?php endif; ?>
        <div class="small text-muted ms-4 text-truncate doc-excerpt"><?= $ft ? '<i class="fas fa-paperclip me-1"></i>' . e($ft[1] . ' file · ' . $d['file_orig'] . ' · ' . \Align\Docs\Files::size((int) $d['file_size'])) : e(Html::excerpt($d['body_html'], 140)) ?></div>
      </td>
      <?php if ($showClient): ?><td class="small"><?= $d['client_id'] ? '<a href="/clients/' . (int) $d['client_id'] . '/documents">' . e($d['client_name']) . '</a>' : '<span class="text-muted">Internal</span>' ?></td><?php endif; ?>
      <td class="small"><?= e($cl) ?></td>
      <td><span class="badge text-bg-<?= Documents::STATUSES[$d['status']][1] ?>"><?= e(Documents::STATUSES[$d['status']][0]) ?></span></td>
      <td class="small text-nowrap"><?= $d['review_due'] ? ($overdue ? '<span class="badge text-bg-warning">' . e(fmt_date($d['review_due'])) . '</span>' : e(fmt_date($d['review_due']))) : '—' ?></td>
      <td class="small text-nowrap"><?= e(rel_time($d['updated_at'])) ?><?= $d['updated_by_name'] ? '<div class="text-muted">' . e($d['updated_by_name']) . ' · v' . (int) $d['version'] . '</div>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$docs): ?><tr><td colspan="<?= $showClient ? 6 : 5 ?>" class="empty-state">No documents yet.</td></tr><?php endif; ?>
  </tbody>
</table>

<?php
/** @var array $fw, $sections, $score */
$labels = ['met' => ['Met', 'success'], 'partial' => ['Partial', 'warning'], 'not_met' => ['Not met', 'danger'], 'na' => ['Not applicable', 'light'], 'not_assessed' => ['Not assessed', 'light']];
?>
<div class="d-flex flex-wrap align-items-center portal-page-head">
  <div class="me-auto"><a href="/portal/compliance" class="small"><i class="fas fa-arrow-left me-1"></i>Compliance</a>
    <h1 class="h4 mb-0"><?= e($fw['name']) ?></h1><?php if ($fw['description']): ?><div class="small text-muted"><?= e($fw['description']) ?></div><?php endif; ?></div>
  <a class="btn btn-sm btn-default me-3 mt-2 mt-md-0" href="/portal/compliance/<?= (int) $fw['id'] ?>/report" target="_blank" rel="noopener"><i class="fas fa-print me-1"></i>Print report</a><?php /* 2.33.0 */ ?>
  <div class="text-end"><span class="h3 mb-0 text-<?= $score['tone'] ?>"><?= (int) $score['score'] ?>%</span><div class="small text-muted"><?= (int) $score['assessed'] ?>% assessed</div></div>
</div>
<?php foreach ($sections as $name => $controls): ?>
  <div class="card">
    <div class="card-header py-2"><h3 class="card-title mt-1"><?= e($name) ?></h3></div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm mb-0">
        <tbody>
        <?php foreach ($controls as $c): [$sl, $st] = $labels[$c['status']] ?? $labels['not_assessed']; ?>
          <tr>
            <td class="text-nowrap small text-muted" style="width:70px"><?= e($c['ref']) ?></td>
            <td><b><?= e($c['title']) ?></b><?php if ($c['doc_title'] && $c['doc_status'] === 'active' && $c['doc_shared']): // (2.36.0) a link only with Documents access; else its name ?><div class="small"><?php if (!empty($canDocs)): ?><a href="/portal/documents/<?= (int) $c['document_id'] ?>"><i class="fas fa-file-lines me-1"></i><?= e($c['doc_title']) ?></a><?php else: ?><i class="fas fa-file-lines me-1 text-muted"></i><?= e($c['doc_title']) ?><?php endif; ?></div><?php endif; ?></td>
            <td class="small text-nowrap"><?= $c['owner'] ? e($c['owner']) : '' ?><?php if ($c['due_date'] && !in_array($c['status'], ['met', 'na'], true)): ?><div class="text-muted">due <?= e(fmt_date($c['due_date'])) ?></div><?php endif; ?></td>
            <td class="text-end"><span class="badge text-bg-<?= $st ?> border"><?= e($sl) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

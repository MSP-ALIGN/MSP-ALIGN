<?php
use Align\Auth;
use Align\Compliance\Compliance;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$fid = (int) $fw['id'];
$canEdit = Auth::can('tech');
$s = $score;
$filters = ['' => 'All', 'open' => 'Needs work', 'not_assessed' => 'Not assessed', 'met' => 'Met'];
$show = function (array $c) use ($filter) {
    return match ($filter) {
        'open' => in_array($c['status'], ['not_met', 'partial'], true),
        'not_assessed' => $c['status'] === 'not_assessed',
        'met' => $c['status'] === 'met',
        default => true,
    };
};
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <div class="mr-auto">
    <div class="small"><a href="/clients/<?= $cid ?>/compliance">Compliance</a> /</div>
    <h1 class="h4 mb-0"><?= e($fw['name']) ?> <span class="badge badge-<?= $s['tone'] ?> align-middle"><?= $s['score'] ?>%</span></h1>
  </div>
  <div class="btn-group btn-group-sm mt-2">
    <?php foreach ($filters as $k => $l): ?><a class="btn <?= $filter === $k ? 'btn-primary' : 'btn-default' ?>" href="?filter=<?= $k ?>"><?= $l ?></a><?php endforeach; ?>
    <a class="btn btn-default" href="/clients/<?= $cid ?>/compliance/<?= $fid ?>/export"><i class="fas fa-file-csv mr-1"></i>CSV</a>
  </div>
</div>
<?php if ($fw['description']): ?><p class="text-muted small"><?= e($fw['description']) ?></p><?php endif; ?>

<form method="post" action="/clients/<?= $cid ?>/compliance/<?= $fid ?>" id="checklist-form">
  <?= csrf_field() ?>
  <input type="hidden" name="filter" value="<?= e($filter) ?>">
  <?php foreach ($sections as $section => $controls): $visible = array_filter($controls, $show); if (!$visible) continue; ?>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><?= e($section) ?></h3>
        <div class="card-tools small text-light"><?= count(array_filter($controls, fn($c) => $c['status'] === 'met')) ?>/<?= count($controls) ?> met</div></div>
      <div class="card-body p-0">
        <table class="table table-sm table-borderless mb-0 checklist">
          <?php foreach ($visible as $c): $k = (int) $c['id']; $ind = $c['auto_check'] ? ($indicators[$c['auto_check']] ?? null) : null; ?>
            <tr class="border-bottom">
              <td class="w-50">
                <div><span class="text-muted small mr-1"><?= e($c['ref']) ?></span><span class="font-weight-bold"><?= e($c['title']) ?></span></div>
                <?php if ($c['guidance']): ?><div class="small text-muted"><?= e($c['guidance']) ?></div><?php endif; ?>
                <?php if ($ind && !$ind['unknown']): ?>
                  <div class="small mt-1 <?= $ind['ok'] ? 'text-success' : 'text-danger' ?>"><i class="fas fa-robot mr-1"></i><?= e($ind['text']) ?>
                    <?php if ($canEdit && $ind['suggest'] && $ind['suggest'] !== $c['status']): ?>
                      <button type="button" class="btn btn-xs btn-outline-secondary ml-1" data-set-status="c<?= $k ?>" data-value="<?= e($ind['suggest']) ?>">Use "<?= e(Compliance::STATUSES[$ind['suggest']][0]) ?>"</button>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if ($c['s_updated'] && $c['status'] !== 'not_assessed'): ?><div class="small text-muted mt-1">Updated <?= e(rel_time($c['s_updated'])) ?><?= $c['updated_by_name'] ? ' by ' . e($c['updated_by_name']) : '' ?></div><?php endif; ?>
              </td>
              <td class="w-50">
                <div class="btn-group btn-group-toggle btn-group-sm flex-wrap mb-1 status-group" data-toggle="buttons" id="c<?= $k ?>">
                  <?php foreach (Compliance::STATUSES as $sk => [$label, $tone, $icon]): $on = $c['status'] === $sk; ?>
                    <label class="btn btn-outline-<?= $tone === 'light' ? 'secondary' : $tone ?> <?= $on ? 'active' : '' ?> <?= $canEdit ? '' : 'disabled' ?>">
                      <input type="radio" name="c[<?= $k ?>][status]" value="<?= $sk ?>" <?= $on ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>><i class="fas <?= $icon ?> mr-1"></i><?= e($label) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="form-row">
                  <div class="col-md-6 mb-1"><input class="form-control form-control-sm" name="c[<?= $k ?>][owner]" value="<?= e($c['owner']) ?>" placeholder="Owner" <?= $canEdit ? '' : 'disabled' ?>></div>
                  <div class="col-md-6 mb-1"><input type="date" class="form-control form-control-sm" name="c[<?= $k ?>][due_date]" value="<?= e($c['due_date']) ?>" title="Due date" <?= $canEdit ? '' : 'disabled' ?>></div>
                </div>
                <textarea class="form-control form-control-sm mb-1" name="c[<?= $k ?>][notes]" rows="1" placeholder="Notes / remediation plan" <?= $canEdit ? '' : 'disabled' ?>><?= e($c['notes']) ?></textarea>
                <div class="form-row">
                  <div class="col-md-6 mb-1"><input class="form-control form-control-sm" name="c[<?= $k ?>][evidence]" value="<?= e($c['evidence']) ?>" placeholder="Evidence (link, file location…)" <?= $canEdit ? '' : 'disabled' ?>></div>
                  <div class="col-md-6 mb-1">
                    <div class="input-group input-group-sm">
                      <select class="custom-select custom-select-sm evidence-select" name="c[<?= $k ?>][document_id]" <?= $canEdit ? '' : 'disabled' ?> aria-label="Evidence document">
                        <option value="">— Link a document —</option>
                        <?php foreach ($docs as $doc): ?><option value="<?= (int) $doc['id'] ?>" <?= (int) $c['document_id'] === (int) $doc['id'] ? 'selected' : '' ?>><?= e($doc['title']) ?><?= $doc['status'] === 'draft' ? ' (draft)' : '' ?></option><?php endforeach; ?>
                      </select>
                      <?php if ($c['document_id']): ?><div class="input-group-append"><a class="btn btn-outline-secondary" href="/documents/<?= (int) $c['document_id'] ?>" title="Open <?= e($c['doc_title']) ?>"><i class="fas fa-up-right-from-square"></i></a></div><?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($canEdit): ?>
    <div class="card card-body sticky-save">
      <div class="form-inline">
        <label class="mr-2 small">Next review</label>
        <input type="date" name="next_review" class="form-control form-control-sm mr-3" value="<?= e($link['next_review']) ?>">
        <div class="custom-control custom-checkbox mr-auto">
          <input type="checkbox" class="custom-control-input" id="mark_reviewed" name="mark_reviewed" value="1">
          <label class="custom-control-label small" for="mark_reviewed">Mark reviewed today<?= $link['last_reviewed'] ? ' (last: ' . e(fmt_date($link['last_reviewed'])) . ')' : '' ?></label>
        </div>
        <button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save checklist</button>
      </div>
    </div>
  <?php endif; ?>
</form>
<?php if ($canEdit): ?>
  <form method="post" action="/clients/<?= $cid ?>/compliance/<?= $fid ?>/remove" class="text-right mt-2">
    <?= csrf_field() ?><button class="btn btn-xs btn-outline-danger" data-confirm="Remove <?= e($fw['name']) ?> from this client? Answers are kept if you add it back.">Remove framework from client</button>
  </form>
<?php endif; ?>

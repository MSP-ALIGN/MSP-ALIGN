<?php
/**
 * A client's checklist for one framework. @var array $client, $fw, $link, $sections, $score, $indicators, $docs,
 * $crosswalk; string $filter (from the query string: only compared, never printed unescaped). Control texts and answers are
 * escaped (data-* values too); inputs are disabled for viewers, and the controller checks the role again on save.
 */
use Align\Auth;
use Align\Compliance\Compliance;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$fid = (int) $fw['id'];
$canEdit = Auth::can('tech');
$s = $score;
$xwReady = array_filter($crosswalk, fn($x) => $x['suggest'] !== null);
$short = fn(string $n) => trim(preg_replace('/\s*\(.*\)\s*$/', '', $n) ?? $n);
$xwFrameworks = [];
foreach ($crosswalk as $x) {
    foreach ($x['matches'] as $m) {
        $xwFrameworks[$short($m['fw_name'])] = true;
    }
}
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
  <div class="me-auto">
    <div class="small"><a href="/clients/<?= $cid ?>/compliance">Compliance</a> /</div>
    <h1 class="h4 mb-0"><?= e($fw['name']) ?> <span class="badge text-bg-<?= $s['tone'] ?> align-middle"><?= $s['score'] ?>%</span></h1>
  </div>
  <div class="btn-group btn-group-sm mt-2">
    <?php foreach ($filters as $k => $l): ?><a class="btn <?= $filter === $k ? 'btn-primary' : 'btn-default' ?>" href="?filter=<?= $k ?>"><?= $l ?></a><?php endforeach; ?>
    <a class="btn btn-default" href="/clients/<?= $cid ?>/compliance/<?= $fid ?>/export"><i class="fas fa-file-csv me-1"></i>CSV</a>
  </div>
</div>
<?php if ($fw['description']): ?><p class="text-muted small"><?= e($fw['description']) ?></p><?php endif; ?>
<?php if ($xwFrameworks): $fillable = 0;
  foreach ($sections as $controls) { foreach (array_filter($controls, $show) as $c) { if ($c['status'] === 'not_assessed' && isset($xwReady[(int) $c['id']])) { $fillable++; } } } ?>
  <div class="callout callout-info py-2 d-flex flex-wrap align-items-center xw-callout">
    <div class="me-auto small"><i class="fas fa-link me-1 text-info"></i><strong>Crosswalk:</strong> controls that ask for the same thing in
      <?= e(implode(', ', array_keys($xwFrameworks))) ?> are listed under each control, so you can reuse an answer and its evidence instead of starting over.</div>
    <?php if ($canEdit && $fillable): ?>
      <button type="button" class="btn btn-sm btn-info mt-1 mt-md-0" id="xw-fill-all" data-count="<?= $fillable ?>"><i class="fas fa-wand-magic-sparkles me-1"></i>Fill <?= $fillable ?> from matching answers</button>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" action="/clients/<?= $cid ?>/compliance/<?= $fid ?>" id="checklist-form" data-post-changed data-unsaved
  data-confirm-rules="<?= e(json_encode([['count' => '[data-row][data-dirty]', 'min' => 10, 'title' => 'Save {n} changed answers?', 'text' => 'Every answer you changed or filled in on this page is saved for this client.', 'ok' => 'Save']])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="filter" value="<?= e($filter) ?>">
  <?php foreach ($sections as $section => $controls): $visible = array_filter($controls, $show); if (!$visible) continue; ?>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><?= e($section) ?></h3>
        <div class="card-tools small text-light"><?= count(array_filter($controls, fn($c) => $c['status'] === 'met')) ?>/<?= count($controls) ?> met</div></div>
      <div class="card-body p-0">
        <table class="table table-sm table-borderless mb-0 checklist">
          <?php foreach ($visible as $c): $k = (int) $c['id']; $ind = $c['auto_check'] ? ($indicators[$c['auto_check']] ?? null) : null; ?>
            <tr class="border-bottom" data-row>
              <td class="w-50">
                <div><span class="text-muted small me-1"><?= e($c['ref']) ?></span><span class="fw-bold"><?= e($c['title']) ?></span></div>
                <?php if ($c['guidance']): ?><div class="small text-muted"><?= e($c['guidance']) ?></div><?php endif; ?>
                <?php if ($ind && !$ind['unknown']): ?>
                  <div class="small mt-1 <?= $ind['ok'] ? 'text-success' : 'text-danger' ?>"><i class="fas fa-robot me-1"></i><?= e($ind['text']) ?>
                    <?php if ($canEdit && $ind['suggest'] && $ind['suggest'] !== $c['status']): ?>
                      <button type="button" class="btn btn-xs btn-outline-secondary ms-1" data-set-status="c<?= $k ?>" data-value="<?= e($ind['suggest']) ?>">Use "<?= e(Compliance::STATUSES[$ind['suggest']][0]) ?>"</button>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if ($xw = $crosswalk[$k] ?? null): $sug = $xw['suggest']; ?>
                  <details class="xw small mt-1">
                    <summary><i class="fas fa-link me-1"></i>Matches <?= count($xw['matches']) ?> control<?= count($xw['matches']) === 1 ? '' : 's' ?> in other frameworks<?= $xw['answered'] ? ' · ' . $xw['answered'] . ' answered' : '' ?></summary>
                    <ul class="list-unstyled mb-0 mt-1">
                      <?php foreach (array_slice($xw['matches'], 0, 8) as $m): [$ml, $mt] = Compliance::STATUSES[$m['status']]; ?>
                        <li class="xw-match">
                          <span class="badge text-bg-<?= $mt === 'light' ? 'secondary' : $mt ?>"><?= e($ml) ?></span>
                          <span class="text-muted" title="<?= e($m['fw_name']) ?>"><?= e($short($m['fw_name'])) ?> ·</span> <strong><?= e($m['ref']) ?></strong> <?= e(mb_strimwidth($m['title'], 0, 110, '…')) ?>
                          <?php if ($canEdit && $m['answered']): ?>
                            <button type="button" class="btn btn-xs btn-outline-info ms-1" data-xw-use="<?= $k ?>" data-status="<?= e($m['status']) ?>"
                              data-notes="<?= e((string) $m['notes']) ?>" data-evidence="<?= e((string) $m['evidence']) ?>" data-doc="<?= (int) $m['document_id'] ?>"
                              data-from="<?= e($short($m['fw_name']) . ' ' . $m['ref']) ?>">Use this answer</button>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                      <?php if (count($xw['matches']) > 8): ?><li class="text-muted">…and <?= count($xw['matches']) - 8 ?> more</li><?php endif; ?>
                    </ul>
                  </details>
                  <?php if ($canEdit && $sug && $c['status'] === 'not_assessed'): ?>
                    <button type="button" class="d-none" data-xw-suggest data-xw-use="<?= $k ?>" data-status="<?= e($sug['status']) ?>"
                      data-notes="<?= e((string) $sug['notes']) ?>" data-evidence="<?= e((string) $sug['evidence']) ?>" data-doc="<?= (int) $sug['document_id'] ?>"
                      data-from="<?= e($short($sug['fw_name']) . ' ' . $sug['ref']) ?>"></button>
                  <?php endif; ?>
                  <div class="xw-filled-note small text-info d-none"></div>
                <?php endif; ?>
                <?php if ($c['s_updated'] && $c['status'] !== 'not_assessed'): ?><div class="small text-muted mt-1">Updated <?= e(rel_time($c['s_updated'])) ?><?= $c['updated_by_name'] ? ' by ' . e($c['updated_by_name']) : '' ?></div><?php endif; ?>
              </td>
              <td class="w-50">
                <div class="btn-group btn-group-sm flex-wrap mb-1 status-group" data-radio-buttons id="c<?= $k ?>">
                  <?php foreach (Compliance::STATUSES as $sk => [$label, $tone, $icon]): $on = $c['status'] === $sk; ?>
                    <label class="btn btn-outline-<?= $tone === 'light' ? 'secondary' : $tone ?> <?= $on ? 'active' : '' ?> <?= $canEdit ? '' : 'disabled' ?>">
                      <input type="radio" name="c[<?= $k ?>][status]" value="<?= $sk ?>" <?= $on ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>><i class="fas <?= $icon ?> me-1"></i><?= e($label) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="row g-2">
                  <div class="col-md-6 mb-1"><input class="form-control form-control-sm" name="c[<?= $k ?>][owner]" value="<?= e($c['owner']) ?>" placeholder="Owner" <?= $canEdit ? '' : 'disabled' ?>></div>
                  <div class="col-md-6 mb-1"><input type="date" class="form-control form-control-sm" name="c[<?= $k ?>][due_date]" value="<?= e($c['due_date']) ?>" title="Due date" <?= $canEdit ? '' : 'disabled' ?>></div>
                </div>
                <textarea class="form-control form-control-sm mb-1" name="c[<?= $k ?>][notes]" rows="1" placeholder="Notes / remediation plan" <?= $canEdit ? '' : 'disabled' ?>><?= e($c['notes']) ?></textarea>
                <div class="row g-2">
                  <div class="col-md-6 mb-1"><input class="form-control form-control-sm" name="c[<?= $k ?>][evidence]" value="<?= e($c['evidence']) ?>" placeholder="Evidence (link, file location…)" <?= $canEdit ? '' : 'disabled' ?>></div>
                  <div class="col-md-6 mb-1">
                    <div class="input-group input-group-sm">
                      <select class="form-select form-select-sm evidence-select" name="c[<?= $k ?>][document_id]" <?= $canEdit ? '' : 'disabled' ?> aria-label="Evidence document">
                        <option value="">— Link a document —</option>
                        <?php foreach ($docs as $doc): ?><option value="<?= (int) $doc['id'] ?>" <?= (int) $c['document_id'] === (int) $doc['id'] ? 'selected' : '' ?>><?= e($doc['title']) ?><?= $doc['status'] === 'draft' ? ' (draft)' : '' ?></option><?php endforeach; ?>
                      </select>
                      <?php if ($c['document_id'] && $c['doc_title'] !== null): ?><a class="btn btn-outline-secondary" href="/documents/<?= (int) $c['document_id'] ?>" title="Open <?= e($c['doc_title']) ?>"><i class="fas fa-up-right-from-square"></i></a><?php endif; ?>
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
      <div class="d-flex flex-wrap align-items-center">
        <label class="me-2 small">Next review</label>
        <input type="date" name="next_review" class="form-control form-control-sm me-3" value="<?= e($link['next_review']) ?>">
        <div class="form-check me-auto">
          <input type="checkbox" class="form-check-input" id="mark_reviewed" name="mark_reviewed" value="1">
          <label class="form-check-label small" for="mark_reviewed">Mark reviewed today<?= $link['last_reviewed'] ? ' (last: ' . e(fmt_date($link['last_reviewed'])) . ')' : '' ?></label>
        </div>
        <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save checklist</button>
      </div>
    </div>
  <?php endif; ?>
</form>
<?php if ($canEdit): ?>
  <form method="post" action="/clients/<?= $cid ?>/compliance/<?= $fid ?>/remove" class="text-end mt-2">
    <?= csrf_field() ?><button class="btn btn-xs btn-outline-danger" data-confirm="Remove <?= e($fw['name']) ?> from this client? Answers are kept if you add it back.">Remove framework from client</button>
  </form>
<?php endif; ?>

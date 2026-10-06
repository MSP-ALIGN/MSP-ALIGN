<?php
/**
 * 2.3.0 an alignment review: the open draft as a form (techs and admins) or a finished review, read-only.
 * @var array $client, $review, $groups (category => rows), $score, $lastAnswers, $indicators, $matches; ?array $last; bool $edit
 * Every standard title, note and hint is escaped (data-* values too). The form posts only changed rows (data-post-changed);
 * the controller checks the role and every answer again.
 */
use Align\Alignment\Alignment;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$draft = $review['status'] === 'draft';
$pill = fn(string $p) => '<span class="badge rounded-pill text-bg-' . (Alignment::PRIORITIES[$p][1] ?? 'secondary') . ' al-prio">' . e(Alignment::PRIORITIES[$p][0] ?? $p) . '</span>';
$total = array_sum(array_map('count', $groups));
$answered = $total - (int) $score['unanswered'];
if (!$draft) {
    // A finished review lists only what was answered: its own stored counts give the full picture
    $answered = (int) $review['aligned'] + (int) $review['misaligned'] + (int) $review['na'];
    $total = $answered + (int) $review['unanswered'];
}
$fillable = 0;
foreach ($groups as $rows) {
    foreach ($rows as $r) {
        if ($r['answer'] === null && !empty($matches[(int) $r['id']]['suggest'])) {
            $fillable++;
        }
    }
}
?>
<div class="small mb-1"><a href="/clients/<?= $cid ?>/alignment">Alignment</a> /</div>
<div class="card card-body mb-3 al-revbar" id="al-revbar">
  <div class="d-flex flex-wrap align-items-center gap-3">
    <div class="al-minw">
      <?php if ($draft): ?>
        <div class="fw-semibold">Review in progress · started <?= e(fmt_date($review['started_at'])) ?><?= $review['started_by_name'] ? ' by ' . e($review['started_by_name']) : '' ?></div>
        <div class="small text-muted"><?= $last ? 'Started from the ' . e(fmt_date($last['finished_at'])) . ' review\'s answers: change what\'s different.' : 'The first review for this client.' ?>
          <?= $edit ? 'Save as you go; finish when every standard has an answer.' : '' ?></div>
      <?php else: ?>
        <div class="fw-semibold">Review of <?= e(fmt_date($review['finished_at'])) ?><?= $review['finished_by_name'] ? ' by ' . e($review['finished_by_name']) : '' ?></div>
        <div class="small text-muted">Finished reviews can't be changed. Start a new review to update the answers.</div>
      <?php endif; ?>
    </div>
    <div class="ms-md-auto d-flex align-items-center gap-3 flex-wrap">
      <div class="text-center"><div class="small text-muted">Answered</div><b id="al-answered"><?= $answered ?> / <?= $total ?></b></div>
      <div class="text-center"><div class="small text-muted"><?= $draft ? 'Score so far' : 'Score' ?></div>
        <b id="al-live" class="fs-5"><?= $score['score'] !== null ? (int) ($draft ? $score['score'] : ($review['score'] ?? $score['score'])) . '%' : '–' ?></b>
        <span id="al-live-band" class="badge text-bg-<?= $score['tone'] ?>"><?= e($score['band']) ?></span></div>
      <?php if ($edit): ?>
        <?php if ($fillable): ?><button type="button" class="btn btn-sm btn-default" id="al-fill-all"><i class="fas fa-clipboard-check me-1"></i>Fill <?= $fillable ?> from compliance</button><?php endif; ?>
        <button type="submit" form="al-form" class="btn btn-sm btn-primary"><i class="fas fa-floppy-disk me-1"></i>Save</button>
        <button type="submit" form="al-form" name="finish" value="1" class="btn btn-sm btn-success" data-confirm="Finish the review? Its score is saved and it can't be changed afterwards." data-confirm-ok="Finish review" data-confirm-danger="0"><i class="fas fa-check me-1"></i>Finish review</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="progress mt-2" style="height:6px" role="img" aria-label="Answered"><div class="progress-bar" id="al-prog" style="width: <?= $total ? (int) round($answered / $total * 100) : 0 ?>%"></div></div>
</div>

<form method="post" action="/clients/<?= $cid ?>/alignment/review" id="al-form" <?= $edit ? 'data-post-changed data-unsaved' : '' ?>>
  <?= csrf_field() ?>
  <?php foreach ($groups as $cat => $rows): ?>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><?= e($cat) ?></h3>
        <div class="card-tools small text-muted"><?= count(array_filter($rows, fn($r) => $r['answer'] === 'aligned')) ?>/<?= count($rows) ?> aligned</div></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($rows as $r): $k = (int) $r['id']; $ind = $edit && $r['auto_check'] ? ($indicators[$r['auto_check']] ?? null) : null; $mx = $matches[$k] ?? null; $lastA = $lastAnswers[$k] ?? null; ?>
          <li class="list-group-item al-q<?= $r['answer'] === null ? ' is-open' : '' ?>" data-row data-w="<?= (int) (Alignment::PRIORITIES[$r['priority']][2] ?? 2) ?>"
            <?= $mx && $mx['suggest'] ? 'data-suggest="' . e($mx['suggest']) . '" data-from="' . e((string) $mx['from']) . '"' : '' ?>>
            <div class="d-flex gap-3 flex-wrap align-items-start">
              <div class="flex-grow-1 al-minw">
                <div class="fw-semibold"><?= $pill($r['priority']) ?> <?= e($r['title']) ?></div>
                <?php if ($r['how'] && $draft): ?><details class="small text-muted mt-1"><summary>How to check</summary><?= nl2br(e($r['how'])) ?></details><?php endif; ?>
                <div class="al-hints mt-1">
                  <?php if ($lastA && $draft): ?><span class="al-hint"><i class="fas fa-clock-rotate-left me-1"></i><?= e(fmt_date($last['finished_at'])) ?>: <?= e(Alignment::ANSWERS[$lastA][0]) ?></span><?php endif; ?>
                  <?php if ($ind && !$ind['unknown']): ?>
                    <span class="al-hint al-hint-auto"><i class="fas fa-robot me-1"></i>Align's data: <?= e($ind['text']) ?>
                      <?php if ($ind['suggest'] && $ind['suggest'] !== $r['answer']): ?><button type="button" class="btn btn-link btn-sm p-0 ms-1 al-hint-btn" data-al-set="<?= $k ?>" data-value="<?= e($ind['suggest']) ?>">Use: <?= e(Alignment::ANSWERS[$ind['suggest']][0]) ?></button><?php endif; ?></span>
                  <?php endif; ?>
                  <?php if ($mx && $mx['matches'][0]['answered']): $best = $mx['matches'][0]; // only answered compliance controls are worth showing ?>
                    <span class="al-hint al-hint-comp" title="<?= e(implode('; ', array_map(fn($m) => Alignment::shortName($m['fw_name']) . ' ' . $m['ref'] . ': ' . (\Align\Compliance\Compliance::STATUSES[$m['status']][0] ?? $m['status']), array_slice($mx['matches'], 0, 8)))) ?>">
                      <i class="fas fa-clipboard-check me-1"></i><?= e(Alignment::shortName($best['fw_name']) . ' ' . $best['ref']) ?>: <?= e(\Align\Compliance\Compliance::STATUSES[$best['status']][0] ?? '') ?><?= $best['answered'] && $best['updated_at'] ? ' (' . e(fmt_date($best['updated_at'])) . ')' : '' ?>
                      <?= count($mx['matches']) > 1 ? '<span class="text-muted">+' . (count($mx['matches']) - 1) . '</span>' : '' ?>
                      <?php if ($edit && $mx['suggest'] && $mx['suggest'] !== $r['answer']): ?><button type="button" class="btn btn-link btn-sm p-0 ms-1 al-hint-btn" data-al-set="<?= $k ?>" data-value="<?= e($mx['suggest']) ?>">Fill: <?= e(Alignment::ANSWERS[$mx['suggest']][0]) ?></button><?php endif; ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="btn-group btn-group-sm al-ans" data-radio-buttons id="al-<?= $k ?>" role="group" aria-label="Answer for <?= e($r['title']) ?>">
                <?php foreach (Alignment::ANSWERS as $ak => [$al, $at, $ai]): $on = $r['answer'] === $ak; ?>
                  <label class="btn btn-outline-<?= $at ?><?= $on ? ' active' : '' ?><?= $edit ? '' : ' disabled' ?>">
                    <input type="radio" name="c[<?= $k ?>][answer]" value="<?= $ak ?>" <?= $on ? 'checked' : '' ?> <?= $edit ? '' : 'disabled' ?>><i class="fas <?= $ai ?> me-1"></i><?= e($al) ?></label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php if ($edit): ?>
              <input class="form-control form-control-sm mt-2" name="c[<?= $k ?>][note]" value="<?= e((string) $r['note']) ?>" maxlength="5000" placeholder="Note: what you found and how you checked (optional)" aria-label="Note for <?= e($r['title']) ?>">
            <?php elseif ($r['note']): ?>
              <div class="small text-muted mt-1"><i class="fas fa-note-sticky me-1"></i><?= e($r['note']) ?></div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
</form>
<?php if ($edit): ?>
  <form method="post" action="/clients/<?= $cid ?>/alignment/review/discard" class="text-end mt-2">
    <?= csrf_field() ?><button class="btn btn-xs btn-outline-danger" data-confirm="Discard this review? Its answers are thrown away; finished reviews stay." data-confirm-ok="Discard">Discard this review</button>
  </form>
<?php endif; ?>

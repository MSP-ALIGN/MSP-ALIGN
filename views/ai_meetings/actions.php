<?php
/**
 * 2.31.0 Review the actions AI found in a meeting's notes: new roadmap items, decisions on the client's open items
 * and a follow-up meeting, each with the words from the notes and a tick box. Apply changes only what is ticked.
 * @var array $client, $m (the meeting), $a (Actions::load), $rows (Actions::rows; empty once applied or discarded)
 * Security: everything from AI or the notes (titles, values, comments, quotes) is escaped; locked rows' boxes are
 * disabled (ApplyActions works the rows out again and skips locked ones anyway).
 */
require __DIR__ . '/../partials/client_header.php';
$mid = (int) $m['id'];
$groups = [
    'New roadmap items' => array_values(array_filter($rows, fn($x) => $x['kind'] === 'new_item')),
    'Decisions' => array_values(array_filter($rows, fn($x) => $x['kind'] === 'decision')),
    'Follow-up meeting' => array_values(array_filter($rows, fn($x) => $x['kind'] === 'follow_up')),
];
?>
<div class="small mb-1"><a href="/clients/<?= (int) $client['id'] ?>/meetings">Meetings</a> / <a href="/meetings/<?= $mid ?>"><?= e($m['title']) ?></a> /</div>
<h1 class="h4 mb-1"><i class="fas fa-wand-magic-sparkles text-secondary me-2"></i>Actions from the meeting notes</h1>
<p class="small text-muted mb-3"><?= e(fmt_date($m['starts_at'])) ?> · read by <?= e($a['created_name'] ?: '—') ?> <?= e(rel_time($a['created_at'])) ?></p>

<?php if ($a['status'] !== 'review'): ?>
  <div class="card card-body">
    <p class="mb-2"><b><?= $a['status'] === 'applied' ? 'Applied' : 'Discarded' ?></b> <?= e(rel_time($a['done_at'])) ?>.</p>
    <?php if ($a['summary']): ?><ul class="small mb-0"><?php foreach (explode("\n", (string) $a['summary']) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
<?php else: ?>
<form method="post" action="/meetings/<?= $mid ?>/ai-actions/<?= (int) $a['id'] ?>" data-unsaved>
  <?= csrf_field() ?>
  <p class="small text-muted">Tick what to apply. Check each one against the words from the notes under it: AI can be wrong. New items are added as <b>proposed</b> without a description: a project's description is shared with the client in the portal, so add one yourself on the roadmap. Decisions are recorded as decided in the meeting, under your name; the words from the notes stay here and in the audit log.</p>
  <?php foreach ($groups as $title => $list): if (!$list) continue; ?>
    <div class="card mb-3">
      <div class="card-header py-2"><h3 class="card-title mt-1"><?= e($title) ?></h3></div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr>
            <th class="ps-3" style="width:2rem"><input type="checkbox" class="form-check-input" data-ai-all aria-label="Tick all <?= e(mb_strtolower($title)) ?>"></th>
            <th><?= $title === 'Decisions' ? 'Project' : 'What' ?></th><?php if ($title === 'Decisions'): ?><th>Now</th><?php endif; ?><th>Suggested</th>
          </tr></thead>
          <tbody>
          <?php foreach ($list as $row): $rid = 'ai-row-' . e($row['id']); ?>
            <tr class="<?= $row['locked'] !== null ? 'text-muted' : '' ?>">
              <td class="ps-3"><input type="checkbox" class="form-check-input" id="<?= $rid ?>" name="rows[]" value="<?= e($row['id']) ?>"<?= $row['checked'] ? ' checked' : '' ?><?= $row['locked'] !== null ? ' disabled' : '' ?>></td>
              <td><label class="fw-semibold mb-0" for="<?= $rid ?>"><?= e($row['what']) ?></label>
                <?php if ($row['flag'] !== null): ?> <span class="badge text-bg-warning ms-1"><?= e($row['flag']) ?></span><?php endif; ?>
                <?php if ($row['quote'] !== null): ?><div class="small text-muted fst-italic">“<?= e($row['quote']) ?>”</div><?php endif; ?>
                <?php if ($row['locked'] !== null): ?><div class="small"><i class="fas fa-lock me-1"></i><?= e($row['locked']) ?></div><?php endif; ?></td>
              <?php if ($title === 'Decisions'): ?><td class="small"><?= e($row['current'] ?? '—') ?></td><?php endif; ?>
              <td class="small pre-line"><?= e($row['value']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($title === 'Follow-up meeting'): ?><div class="card-footer small text-muted py-2">It's scheduled at the same time of day and length as this meeting. <?= e(\Align\Ai\Meetings\ApplyActions::NOTHING_SENT) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <button class="btn btn-primary" name="action" value="apply"><i class="fas fa-check me-1"></i>Apply ticked actions</button>
  <button class="btn btn-default ms-1" name="action" value="discard" data-confirm="Discard these actions? Nothing is changed."><i class="fas fa-trash me-1"></i>Discard</button>
</form>
<?php endif; ?>

<?php
/**
 * 2.4.0 a client's Since last QBR tab: pick the starting point (a completed business review or a date; the newest
 * review by default), then what changed since (views/changes/_body.php), with a link to the QBR pack that starts
 * from the same point.
 * @var array $client, $baselines; ?array $base, $ch; bool $badSince
 * Labels and names are escaped; the picker's values are checked again by Changes::resolve().
 */
use Align\View;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$isDate = $base && !$base['meeting_id']; // compared with a date rather than a review: the date box shows
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h2 class="h4 mb-0 me-auto"><i class="fas fa-clock-rotate-left text-secondary me-2"></i>Since last QBR</h2>
  <?php // The picker: completed reviews ("from dates" when no figures were saved) or A date…; app.js shows the date box and submits on change ?>
  <form method="get" action="/clients/<?= $cid ?>/changes" class="d-flex flex-wrap align-items-center gap-2" id="ch-pick">
    <label class="small text-muted" for="ch-since">Compare with</label>
    <select class="form-select form-select-sm w-auto" name="since" id="ch-since" data-ch-since>
      <?php foreach ($baselines as $bl): ?>
        <option value="<?= e($bl['key']) ?>"<?= $base && $base['key'] === $bl['key'] ? ' selected' : '' ?>><?= e($bl['label']) ?><?= $bl['exact'] ? '' : ' (from dates)' ?></option>
      <?php endforeach; ?>
      <option value="date"<?= $isDate ? ' selected' : '' ?>>A date…</option>
    </select>
    <input type="date" class="form-control form-control-sm w-auto" name="date" id="ch-date" aria-label="Date to compare with" max="<?= e(date('Y-m-d', strtotime('-1 day'))) ?>"
      value="<?= $isDate ? e($base['date']) : '' ?>"<?= $isDate || !$baselines ? '' : ' hidden' ?>>
    <button class="btn btn-sm btn-default">Show</button>
  </form>
  <?php if ($base): ?>
    <a class="btn btn-sm btn-primary" href="/clients/<?= $cid ?>/report/qbr?since=<?= e(rawurlencode($base['key'])) ?>" target="_blank" rel="noopener"><i class="fas fa-book-open me-1"></i>QBR pack</a>
  <?php endif; ?>
  <a class="btn btn-sm btn-default" href="/help#guide-changes" title="Help"><i class="fas fa-circle-question"></i><span class="visually-hidden">Help</span></a>
</div>

<?php if ($badSince): ?><div class="alert alert-warning py-2">That starting point isn't one of this client's completed reviews or a past date, so the newest review is shown.</div><?php endif; ?>

<?php if (!$base): ?>
  <div class="card card-body text-center py-5">
    <i class="fas fa-clock-rotate-left fa-2x text-secondary mb-2"></i>
    <h3 class="h5">No completed business review yet</h3>
    <p class="text-muted mx-auto ch-measure">When a business review meeting (quarterly, technology or annual) is marked completed, this page shows what changed since: projects finished,
      devices replaced, warranties and support that ran out, alignment and compliance. Pick <b>A date…</b> above to compare with any day instead.</p>
    <a class="btn btn-sm btn-default mx-auto" href="/clients/<?= $cid ?>/meetings"><i class="fas fa-handshake me-1"></i>Meetings</a>
  </div>
<?php else: ?>
  <p class="text-muted small mb-3">Since <b><?= e($base['label']) ?></b>, <?= (int) $base['days'] ?> days ago.
    <?= $base['exact'] ? 'Figures saved when that review was completed are compared with today.' : 'Worked out from dates: devices, projects, licenses and tickets carry their own dates.' ?></p>
  <?= View::fetch('changes/_body', ['ch' => $ch, 'costs' => true, 'staff' => true, 'cid' => $cid]) ?>
<?php endif; ?>

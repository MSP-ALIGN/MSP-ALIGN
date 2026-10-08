<?php
/**
 * 2.7.0 The security awareness training (SAT) card on a client's overview: the two checks, the latest results, the
 * upload form for Huntress SAT's exports (techs and admins) and the upload history (each can be deleted).
 * @var array $client; array $history Sat::history(); array $checks Sat::checks()
 * Security: only totals and dates are stored and shown; file names are escaped. Forms post with CSRF and
 * SatController checks the role and the client again.
 */
use Align\Auth;
use Align\Sat\Sat;

$cid = (int) $client['id'];
$tech = Auth::can('tech');
$icon = ['pass' => 'fa-circle-check text-success', 'fail' => 'fa-circle-xmark text-danger', 'unknown' => 'fa-circle-question text-secondary'];
$pct = fn($a, $b) => (int) $b ? round((int) $a / (int) $b * 100) . '%' : '–';
?>
<div class="card card-dark" id="sat">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-graduation-cap me-2"></i>Security awareness training</h3></div>
  <div class="card-body">
    <?php // The checks ?>
    <ul class="list-unstyled small mb-2">
      <?php foreach (Sat::CHECKS as $k => $label): $c = $checks[$k]; ?>
        <li class="mb-1"><i class="fas fa-fw <?= $icon[$c['status']] ?> me-1"></i><b><?= e($label) ?>:</b> <span class="text-muted"><?= e($c['detail']) ?></span></li>
      <?php endforeach; ?>
    </ul>

    <?php // Upload history: totals only ?>
    <?php if ($history): ?>
      <div class="table-responsive"><table class="table table-sm small mb-2 align-middle">
        <thead><tr><th>Report</th><th>Covers</th><th class="text-end">Result</th><th>Uploaded</th><?php if ($tech): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach (array_slice($history, 0, 8) as $s): ?>
            <tr><td><?= e($s['report']) ?><?php if ($s['file_name']): ?><div class="text-muted"><?= e($s['file_name']) ?></div><?php endif; ?></td>
              <td class="text-nowrap"><?= $s['covers_from'] && $s['covers_from'] !== $s['covers_to'] ? e(fmt_date($s['covers_from'])) . ' – ' : '' ?><?= e(fmt_date($s['covers_to'])) ?></td>
              <td class="text-end text-nowrap"><?php if ($s['kind'] === 'training'): ?><?= (int) $s['completed'] ?>/<?= (int) $s['learners'] ?> done (<?= $pct($s['completed'], $s['learners']) ?>)
                <?php else: ?><?= $pct($s['clicked'], $s['sent']) ?> clicked<?= $s['reported'] !== null ? ', ' . $pct($s['reported'], $s['sent']) . ' reported' : '' ?> <span class="text-muted">(<?= (int) $s['sent'] ?> emails)</span><?php endif; ?></td>
              <td class="text-nowrap"><?= e(fmt_date($s['uploaded_at'])) ?><?= $s['uploaded_by_name'] ? '<div class="text-muted">' . e($s['uploaded_by_name']) . '</div>' : '' ?></td>
              <?php if ($tech): ?><td class="text-end"><form method="post" action="/clients/<?= $cid ?>/sat/<?= (int) $s['id'] ?>/delete" class="d-inline"><?= csrf_field() ?><button class="btn btn-link btn-sm text-danger p-0" data-confirm="Delete this upload's results?" title="Delete"><i class="fas fa-trash"></i></button></form></td><?php endif; ?></tr>
          <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>

    <?php // Upload (techs and admins) ?>
    <?php if ($tech): ?>
      <form method="post" action="/clients/<?= $cid ?>/sat" enctype="multipart/form-data" class="row g-2 align-items-end small">
        <?= csrf_field() ?>
        <div class="col-md-6"><label>Huntress SAT export (CSV)</label><input type="file" name="file" accept=".csv,text/csv" class="form-control form-control-sm" required></div>
        <div class="col-md-3"><label>Campaign date <span class="text-muted">(phishing, optional)</span></label><input type="date" name="campaign_date" class="form-control form-control-sm"></div>
        <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-upload me-1"></i>Upload</button></div>
        <div class="col-12 text-muted">In Huntress SAT → Reports, export <b>Assignment: Learner Progress</b> (training) or <b>Phishing: Attempts</b> / <b>Phishing: Annual Overview</b> as CSV. Align keeps only the totals, never names.</div>
      </form>
    <?php elseif (!$history): ?>
      <p class="small text-muted mb-0">No training results uploaded yet.</p>
    <?php endif; ?>
  </div>
</div>

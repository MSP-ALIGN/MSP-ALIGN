<?php
/**
 * 2.7.0 The security awareness training (SAT) card on a client's overview: the two checks, the latest results, the
 * upload form for Huntress SAT's exports (techs and admins) and the upload history (each can be deleted).
 * 2.7.2: when the client's Curricula account is linked, the results come from the Curricula API instead: the card
 * says so, shows when they were read (or the last error), the account summary reports and a Refresh button; the
 * upload form stays, folded away, as the fallback (uploads count again if the link goes).
 * @var array $client; array $history Sat::history(); array $checks Sat::checks(); string $source Sat::source();
 *      ?array $curricula Curricula::account(); array $reports Curricula::reports()
 * Security: only totals and dates are stored and shown; file names, report titles and errors are escaped; report
 * links go through SatController::report() (techs, https only) and open in a new tab without a referrer. Forms post with CSRF and
 * SatController checks the role and the client again.
 */
use Align\Auth;
use Align\Sat\Sat;

$cid = (int) $client['id'];
$tech = Auth::can('tech');
$api = $source === 'api';
$icon = ['pass' => 'fa-circle-check text-success', 'fail' => 'fa-circle-xmark text-danger', 'unknown' => 'fa-circle-question text-secondary'];
$pct = fn($a, $b) => (int) $b ? round((int) $a / (int) $b * 100) . '%' : '–';
?>
<div class="card card-dark" id="sat">
  <div class="card-header py-2 d-flex align-items-center">
    <h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-graduation-cap me-2"></i>Security awareness training</h3>
    <?php // Refresh from Curricula (techs and admins, linked clients only) ?>
    <?php if ($curricula && $tech): ?>
      <form method="post" action="/clients/<?= $cid ?>/sat/refresh" class="ms-2"><?= csrf_field() ?><button class="btn btn-sm btn-default" title="Read this client's results from Curricula now"><i class="fas fa-rotate me-1"></i>Refresh</button></form>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php // Where the results come from: the linked Curricula account (when read, or its last error) ?>
    <?php if ($curricula): ?>
      <p class="small mb-2"><i class="fas fa-fw fa-link me-1 text-muted"></i>From Curricula account <b><?= e($curricula['name']) ?></b>
        <span class="text-muted">· <?= $curricula['synced_at'] ? 'read ' . e(fmt_datetime($curricula['synced_at'])) : 'not read yet (the next sync reads it)' ?></span></p>
      <?php if ($curricula['error']): ?><div class="alert alert-warning small py-1 px-2 mb-2"><i class="fas fa-triangle-exclamation me-1"></i>Last read failed: <?= e($curricula['error']) ?></div><?php endif; ?>
      <?php if (!$api && $curricula['synced_at']): ?><p class="small text-muted mb-2">Curricula had no training or phishing results for this client, so uploads are used.</p><?php endif; ?>
    <?php endif; ?>

    <?php // The checks ?>
    <ul class="list-unstyled small mb-2">
      <?php foreach (Sat::CHECKS as $k => $label): $c = $checks[$k]; ?>
        <li class="mb-1"><i class="fas fa-fw <?= $icon[$c['status']] ?> me-1"></i><b><?= e($label) ?>:</b> <span class="text-muted"><?= e($c['detail']) ?></span></li>
      <?php endforeach; ?>
    </ul>

    <?php // Results (from the source the checks use): totals only. Uploads can be deleted; API rows are replaced on every read ?>
    <?php if ($history): ?>
      <div class="table-responsive"><table class="table table-sm small mb-2 align-middle">
        <thead><tr><th><?= $api ? 'Assignment or campaign' : 'Report' ?></th><th>Covers</th><th class="text-end">Result</th><?php if (!$api): // API rows were all read together (the time is above) ?><th>Uploaded</th><?php if ($tech): ?><th></th><?php endif; ?><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach (array_slice($history, 0, 8) as $s): ?>
            <tr><td><?= e($s['report']) ?><?php if ($s['file_name']): ?><div class="text-muted"><?= e($s['file_name']) ?></div><?php endif; ?>
                <?php if ($s['kind'] === 'training' && $s['assignments']): ?><div class="text-muted"><?= (int) $s['assignments'] ?> assignment<?= (int) $s['assignments'] === 1 ? '' : 's' ?></div><?php endif; ?></td>
              <td class="text-nowrap"><?= $s['covers_from'] && $s['covers_from'] !== $s['covers_to'] ? e(fmt_date($s['covers_from'])) . ' – ' : '' ?><?= e(fmt_date($s['covers_to'])) ?></td>
              <td class="text-end text-nowrap"><?php if ($s['kind'] === 'training'): ?><?= (int) $s['completed'] ?>/<?= (int) $s['learners'] ?> done (<?= $pct($s['completed'], $s['learners']) ?>)
                <?php else: ?><?= $pct($s['clicked'], $s['sent']) ?> clicked<?= $s['reported'] !== null ? ', ' . $pct($s['reported'], $s['sent']) . ' reported' : '' ?> <span class="text-muted">(<?= (int) $s['sent'] ?> emails)</span><?php endif; ?></td>
              <?php if (!$api): ?><td class="text-nowrap"><?= e(fmt_date($s['uploaded_at'])) ?><?= $s['uploaded_by_name'] ? '<div class="text-muted">' . e($s['uploaded_by_name']) . '</div>' : '' ?></td><?php endif; ?>
              <?php if ($tech && !$api): ?><td class="text-end"><form method="post" action="/clients/<?= $cid ?>/sat/<?= (int) $s['id'] ?>/delete" class="d-inline"><?= csrf_field() ?><button class="btn btn-link btn-sm text-danger p-0" data-confirm="Delete this upload's results?" title="Delete"><i class="fas fa-trash"></i></button></form></td><?php endif; ?></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      <?php if (count($history) > 8): ?><p class="small text-muted mb-2"><?= count($history) - 8 ?> more not shown.</p><?php endif; ?>
    <?php endif; ?>

    <?php // Curricula's account summary reports: techs open the PDF through Align, which asks Curricula for a fresh link ?>
    <?php if ($reports): ?>
      <div class="small mb-2"><b>Summary reports:</b>
        <?php foreach ($reports as $i => $r): ?><?= $i ? ' · ' : ' ' ?><?php $label = ($r['start_date'] ? fmt_date($r['start_date']) . ' – ' : '') . fmt_date($r['end_date'] ?? $r['generated_at']); ?>
          <?php if ($r['has_pdf'] && $tech): ?><a href="/clients/<?= $cid ?>/sat/report/<?= e(rawurlencode($r['report_id'])) ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-file-pdf me-1"></i><?= e($label) ?></a><?php else: ?><span class="text-muted"><?= e($label) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php // Upload (techs and admins): the source without Curricula, folded away as the fallback with it ?>
    <?php if ($tech): ?>
      <?php if ($api): ?><details class="small"><summary class="text-muted">Upload a Huntress SAT export instead (used only if the Curricula link is removed)</summary><div class="mt-2"><?php endif; ?>
      <form method="post" action="/clients/<?= $cid ?>/sat" enctype="multipart/form-data" class="row g-2 align-items-end small">
        <?= csrf_field() ?>
        <div class="col-md-6"><label>Huntress SAT export (CSV)</label><input type="file" name="file" accept=".csv,text/csv" class="form-control form-control-sm" required></div>
        <div class="col-md-3"><label>Campaign date <span class="text-muted">(phishing, optional)</span></label><input type="date" name="campaign_date" class="form-control form-control-sm"></div>
        <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-upload me-1"></i>Upload</button></div>
        <div class="col-12 text-muted">In Huntress SAT → Reports, export <b>Assignment: Learner Progress</b> (training) or <b>Phishing: Attempts</b> / <b>Phishing: Annual Overview</b> as CSV. Align keeps only the totals, never names.<?php if (!$curricula): ?> Or set up <a href="/integrations/curricula">Huntress SAT (Curricula)</a> to read them automatically.<?php endif; ?></div>
      </form>
      <?php if ($api): ?></div></details><?php endif; ?>
    <?php elseif (!$history): ?>
      <p class="small text-muted mb-0">No training results yet.</p>
    <?php endif; ?>
  </div>
</div>

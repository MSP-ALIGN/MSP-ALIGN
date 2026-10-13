<?php
/**
 * 2.7.0 The security awareness training (SAT) card on a client's overview: the two checks, the latest results and
 * where they came from. 2.16.0: results only; the upload form, the uploads' Delete, Refresh and the KnowBe4 token are
 * on the client's Connectors page (sat/_setup), linked from Manage.
 * 2.7.2 / 2.14.0: when the client's results come from Curricula or its own KnowBe4 console, the card says which
 * account, when it was read (or the last error) and, for Curricula, the summary reports.
 * @var array $client; array $history Sat::history(); array $checks Sat::checks(); string $source Sat::source();
 *      ?array $curricula Curricula::account(); array $reports Curricula::reports(); ?array $knowbe4 KnowBe4::forClient()
 * Security: only totals and dates are stored and shown; file names, report titles and errors are escaped; report
 * links go through SatController::report() (techs, https only) and open in a new tab without a referrer. No forms.
 */
use Align\Auth;
use Align\Sat\KnowBe4;
use Align\Sat\Sat;

$knowbe4 ??= null;

$cid = (int) $client['id'];
$tech = Auth::can('tech');
$api = $source === 'api';
$icon = ['pass' => 'fa-circle-check text-success', 'fail' => 'fa-circle-xmark text-danger', 'unknown' => 'fa-circle-question text-secondary'];
$pct = fn($a, $b) => (int) $b ? round((int) $a / (int) $b * 100) . '%' : '–';
?>
<div class="card card-dark" id="sat">
  <div class="card-header py-2 d-flex align-items-center">
    <h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-graduation-cap me-2"></i>Security awareness training</h3>
    <?php if ($tech): // 2.16.0: set up and refreshed on the client's Connectors page ?><a class="btn btn-sm btn-default ms-2" href="/clients/<?= $cid ?>/connectors#sat"><i class="fas fa-sliders me-1"></i>Manage</a><?php endif; ?>
  </div>
  <div class="card-body">
    <?php // 2.14.0 The client's KnowBe4 console: account, seats, risk score, when read or its last error ?>
    <?php if ($knowbe4): ?>
      <div class="d-flex align-items-start small mb-2">
        <p class="mb-0 me-auto"><i class="fas fa-fw fa-link me-1 text-muted"></i>From KnowBe4 account <b><?= e((string) $knowbe4['account_name']) ?></b>
          <span class="text-muted">· <?= e(KnowBe4::REGIONS[$knowbe4['region']] ?? $knowbe4['region']) ?><?= $knowbe4['subscription'] ? ' · ' . e($knowbe4['subscription']) : '' ?><?= $knowbe4['seats'] !== null ? ' · ' . (int) $knowbe4['seats'] . ' seats' : '' ?><?= $knowbe4['risk_score'] !== null ? ' · risk score ' . e(rtrim(rtrim(number_format((float) $knowbe4['risk_score'], 1), '0'), '.')) : '' ?>
          · <?= $knowbe4['synced_at'] ? 'read ' . e(fmt_datetime($knowbe4['synced_at'])) : 'not read yet (the next sync reads it)' ?></span></p>
      </div>
      <?php if ($knowbe4['error']): ?><div class="alert alert-warning small py-1 px-2 mb-2"><i class="fas fa-triangle-exclamation me-1"></i>Last read failed: <?= e($knowbe4['error']) ?></div><?php endif; ?>
      <?php if (!$api && $knowbe4['synced_at']): ?><p class="small text-muted mb-2">KnowBe4 had no training or phishing results for this client, so uploads are used.</p><?php endif; ?>
    <?php endif; ?>

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

    <?php // Results (from the source the checks use): totals only (uploads are deleted on Connectors) ?>
    <?php if ($history): ?>
      <div class="table-responsive"><table class="table table-sm small mb-2 align-middle">
        <thead><tr><th><?= $api ? 'Assignment or campaign' : 'Report' ?></th><th>Covers</th><th class="text-end">Result</th><?php if (!$api): // API rows were all read together (the time is above) ?><th>Uploaded</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach (array_slice($history, 0, 8) as $s): ?>
            <tr><td><?= e($s['report']) ?><?php if ($s['file_name']): ?><div class="text-muted"><?= e($s['file_name']) ?></div><?php endif; ?>
                <?php if ($s['kind'] === 'training' && $s['assignments']): $noun = ($s['provider'] ?? '') === KnowBe4::PROVIDER ? 'campaign' : 'assignment'; ?><div class="text-muted"><?= (int) $s['assignments'] ?> <?= $noun ?><?= (int) $s['assignments'] === 1 ? '' : 's' ?></div><?php endif; ?></td>
              <td class="text-nowrap"><?= $s['covers_from'] && $s['covers_from'] !== $s['covers_to'] ? e(fmt_date($s['covers_from'])) . ' – ' : '' ?><?= e(fmt_date($s['covers_to'])) ?></td>
              <td class="text-end text-nowrap"><?php if ($s['kind'] === 'training'): ?><?= (int) $s['completed'] ?>/<?= (int) $s['learners'] ?> done (<?= $pct($s['completed'], $s['learners']) ?>)
                <?php else: ?><?= $pct($s['clicked'], $s['sent']) ?> clicked<?= $s['reported'] !== null ? ', ' . $pct($s['reported'], $s['sent']) . ' reported' : '' ?> <span class="text-muted">(<?= (int) $s['sent'] ?> emails)</span><?php endif; ?></td>
              <?php if (!$api): ?><td class="text-nowrap"><?= e(fmt_date($s['uploaded_at'])) ?><?= $s['uploaded_by_name'] ? '<div class="text-muted">' . e($s['uploaded_by_name']) . '</div>' : '' ?></td><?php endif; ?></tr>
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

    <?php if (!$history): ?>
      <p class="small text-muted mb-0">No training results yet.<?= $tech ? ' Upload Huntress SAT exports or connect KnowBe4 or Curricula on <a href="/clients/' . $cid . '/connectors#sat">Connectors</a>.' : '' ?></p>
    <?php endif; ?>
  </div>
</div>

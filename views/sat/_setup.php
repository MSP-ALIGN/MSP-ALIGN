<?php
/**
 * 2.16.0 Security awareness training setup on a client's Connectors page (techs and admins): where the results come
 * from (the client's KnowBe4 console, its linked Curricula account, else Huntress SAT CSV uploads), Refresh, the
 * KnowBe4 token (connect, replace, remove: admins), the upload form and the uploads with Delete. The results
 * themselves are on the overview's training card (sat/_card).
 * @var array $client; array $history Sat::history(); string $source Sat::source(); ?array $curricula
 *      Curricula::account(); ?array $knowbe4 KnowBe4::forClient() (SatController::data() also passes checks and reports, unused here)
 * Security: the KnowBe4 token is never shown (a password field, posted to admins-only SatController::knowbe4Save);
 * names, file names and errors are escaped. Forms post with CSRF and SatController checks the role and the client again.
 */
use Align\Auth;
use Align\Sat\KnowBe4;

$knowbe4 ??= null;
$admin = Auth::can('admin');
$cid = (int) $client['id'];
$tech = Auth::can('tech');
$api = $source === 'api';
$pct = fn($a, $b) => (int) $b ? round((int) $a / (int) $b * 100) . '%' : '–';
?>
<div class="card card-dark" id="sat">
  <div class="card-header py-2 d-flex align-items-center">
    <h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-graduation-cap me-2"></i>Security awareness training</h3>
    <?php // Refresh from Curricula (techs and admins, linked clients only) ?>
    <?php if (($curricula || $knowbe4) && $tech): ?>
      <form method="post" action="/clients/<?= $cid ?>/sat/refresh" class="ms-2"><?= csrf_field() ?><button class="btn btn-sm btn-default" title="Read this client's results from <?= $knowbe4 ? 'KnowBe4' : 'Curricula' ?> now"><i class="fas fa-rotate me-1"></i>Refresh</button></form>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php // 2.14.0 The client's KnowBe4 console: account, seats, risk score, when read or its last error; admins can remove it ?>
    <?php if ($knowbe4): ?>
      <div class="d-flex align-items-start small mb-2">
        <p class="mb-0 me-auto"><i class="fas fa-fw fa-link me-1 text-muted"></i>From KnowBe4 account <b><?= e((string) $knowbe4['account_name']) ?></b>
          <span class="text-muted">· <?= e(KnowBe4::REGIONS[$knowbe4['region']] ?? $knowbe4['region']) ?><?= $knowbe4['subscription'] ? ' · ' . e($knowbe4['subscription']) : '' ?><?= $knowbe4['seats'] !== null ? ' · ' . (int) $knowbe4['seats'] . ' seats' : '' ?><?= $knowbe4['risk_score'] !== null ? ' · risk score ' . e(rtrim(rtrim(number_format((float) $knowbe4['risk_score'], 1), '0'), '.')) : '' ?>
          · <?= $knowbe4['synced_at'] ? 'read ' . e(fmt_datetime($knowbe4['synced_at'])) : 'not read yet (the next sync reads it)' ?></span></p>
        <?php if ($admin): ?><form method="post" action="/clients/<?= $cid ?>/sat/knowbe4/remove" class="ms-2"><?= csrf_field() ?><button class="btn btn-link btn-sm text-danger p-0" data-confirm="Remove this client's KnowBe4 token? Its KnowBe4 results go with it." title="Remove KnowBe4">Remove</button></form><?php endif; ?>
      </div>
      <?php if ($knowbe4['error']): ?><div class="alert alert-warning small py-1 px-2 mb-2"><i class="fas fa-triangle-exclamation me-1"></i>Last read failed: <?= e($knowbe4['error']) ?></div><?php endif; ?>
      <?php if ($admin): // replace the token (a new one, or after the encryption key changed) without losing the results first ?>
        <details class="small mb-2"><summary class="text-muted">Replace the KnowBe4 token</summary>
          <form method="post" action="/clients/<?= $cid ?>/sat/knowbe4" class="row g-2 align-items-end mt-1" autocomplete="off">
            <?= csrf_field() ?>
            <div class="col-md-4"><label for="kb4-region-<?= $cid ?>">Region</label><select id="kb4-region-<?= $cid ?>" name="region" class="form-select form-select-sm">
              <?php foreach (KnowBe4::REGIONS as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $knowbe4['region'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label for="kb4-token-<?= $cid ?>">New Reporting API token</label><input type="password" id="kb4-token-<?= $cid ?>" name="token" class="form-control form-control-sm" required autocomplete="new-password"></div>
            <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-key me-1"></i>Replace</button></div>
          </form>
        </details>
      <?php endif; ?>
      <?php if (!$api && $knowbe4['synced_at']): ?><p class="small text-muted mb-2">KnowBe4 had no training or phishing results for this client, so uploads are used.</p><?php endif; ?>
    <?php endif; ?>

    <?php // Where the results come from: the linked Curricula account (when read, or its last error) ?>
    <?php if ($curricula): ?>
      <p class="small mb-2"><i class="fas fa-fw fa-link me-1 text-muted"></i>From Curricula account <b><?= e($curricula['name']) ?></b>
        <span class="text-muted">· <?= $curricula['synced_at'] ? 'read ' . e(fmt_datetime($curricula['synced_at'])) : 'not read yet (the next sync reads it)' ?></span></p>
      <?php if ($curricula['error']): ?><div class="alert alert-warning small py-1 px-2 mb-2"><i class="fas fa-triangle-exclamation me-1"></i>Last read failed: <?= e($curricula['error']) ?></div><?php endif; ?>
      <?php if (!$api && $curricula['synced_at']): ?><p class="small text-muted mb-2">Curricula had no training or phishing results for this client, so uploads are used.</p><?php endif; ?>
    <?php endif; ?>

    <?php // 2.14.0 Connect this client's KnowBe4 console (admins, when KnowBe4 is switched on): region and Reporting API token ?>
    <?php if ($admin && !$knowbe4 && KnowBe4::enabled()): ?>
      <details class="small mb-2"><summary class="text-muted">Read this client's results from its KnowBe4 console</summary>
        <form method="post" action="/clients/<?= $cid ?>/sat/knowbe4" class="row g-2 align-items-end mt-1" autocomplete="off">
          <?= csrf_field() ?>
          <div class="col-md-4"><label for="kb4-region-<?= $cid ?>">Region</label><select id="kb4-region-<?= $cid ?>" name="region" class="form-select form-select-sm">
            <?php foreach (KnowBe4::REGIONS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-5"><label for="kb4-token-<?= $cid ?>">Reporting API token</label><input type="password" id="kb4-token-<?= $cid ?>" name="token" class="form-control form-control-sm" required autocomplete="new-password"></div>
          <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-plug me-1"></i>Connect</button></div>
          <div class="col-12 text-muted">In the client's KnowBe4 console open <b>Account Settings → Account Integrations → API</b>, turn on <b>Enable Reporting API Access</b> and create a <b>Reporting API</b> token (Platinum, Diamond or SAT Foundations/Advanced). Align only reads, keeps only totals, never names, and checks the token before saving it.</div>
        </form>
      </details>
    <?php endif; ?>

    <?php // The uploads, with Delete (when uploads are what counts; results read from an API are replaced by each read) ?>
    <?php if (!$api && $history): ?>
      <div class="table-responsive"><table class="table table-sm small mb-2 align-middle" id="sat-uploads">
        <thead><tr><th>Upload</th><th>Covers</th><th class="text-end">Result</th><th>Uploaded</th><th></th></tr></thead>
        <tbody>
          <?php foreach (array_slice($history, 0, 12) as $s): ?>
            <tr><td><?= e($s['report']) ?><?php if ($s['file_name']): ?><div class="text-muted"><?= e($s['file_name']) ?></div><?php endif; ?></td>
              <td class="text-nowrap"><?= $s['covers_from'] && $s['covers_from'] !== $s['covers_to'] ? e(fmt_date($s['covers_from'])) . ' – ' : '' ?><?= e(fmt_date($s['covers_to'])) ?></td>
              <td class="text-end text-nowrap"><?php if ($s['kind'] === 'training'): ?><?= (int) $s['completed'] ?>/<?= (int) $s['learners'] ?> done<?php else: ?><?= $pct($s['clicked'], $s['sent']) ?> clicked<?php endif; ?></td>
              <td class="text-nowrap"><?= e(fmt_date($s['uploaded_at'])) ?><?= $s['uploaded_by_name'] ? '<div class="text-muted">' . e($s['uploaded_by_name']) . '</div>' : '' ?></td>
              <td class="text-end"><form method="post" action="/clients/<?= $cid ?>/sat/<?= (int) $s['id'] ?>/delete" class="d-inline"><?= csrf_field() ?><button class="btn btn-link btn-sm text-danger p-0" data-confirm="Delete this upload's results?" title="Delete" aria-label="Delete this upload"><i class="fas fa-trash"></i></button></form></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>

    <?php // Upload (techs and admins): the source without Curricula, folded away as the fallback with it ?>
    <?php if ($tech): ?>
      <?php if ($api): ?><details class="small"><summary class="text-muted">Upload a Huntress SAT export instead (used only if the <?= $knowbe4 ? 'KnowBe4 token' : 'Curricula link' ?> is removed)</summary><div class="mt-2"><?php endif; ?>
      <form method="post" action="/clients/<?= $cid ?>/sat" enctype="multipart/form-data" class="row g-2 align-items-end small">
        <?= csrf_field() ?>
        <div class="col-md-6"><label for="sat-file-<?= $cid ?>">Huntress SAT export (CSV)</label><input type="file" id="sat-file-<?= $cid ?>" name="file" accept=".csv,text/csv" class="form-control form-control-sm" required></div>
        <div class="col-md-3"><label for="sat-date-<?= $cid ?>">Campaign date <span class="text-muted">(phishing, optional)</span></label><input type="date" id="sat-date-<?= $cid ?>" name="campaign_date" class="form-control form-control-sm"></div>
        <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-upload me-1"></i>Upload</button></div>
        <div class="col-12 text-muted">In Huntress SAT → Reports, export <b>Assignment: Learner Progress</b> (training) or <b>Phishing: Attempts</b> / <b>Phishing: Annual Overview</b> as CSV. Align keeps only the totals, never names.<?php if (!$curricula && !$knowbe4): ?> Or set up <a href="/integrations/curricula">Huntress SAT (Curricula)</a> to read them automatically.<?php endif; ?></div>
      </form>
      <?php if ($api): ?></div></details><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

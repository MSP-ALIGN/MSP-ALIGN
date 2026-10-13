<?php
/**
 * 2.16.0 Settings → Health & checks (admins): the client health score (weights and bands, moved from Planning &
 * lifecycle) and the pass marks of the checks that feed it: security awareness training and phishing (moved from the
 * Huntress integration page; they apply to every training provider), backups overdue (from the backup integration)
 * and the SLA goal (from the PSA). The setting names are unchanged. @var array $v saved values.
 * Security: every value is escaped; the form posts with CSRF to SettingsController::save (admins), which clamps each
 * number to its range.
 */
$num = fn(string $name, string $label, string $prefix = '', string $suffix = '') => '<div class="mb-3"><label class="small" for="set-' . e($name) . '">' . e($label) . '</label><div class="input-group input-group-sm">'
    . ($prefix ? '<span class="input-group-text">' . e($prefix) . '</span>' : '')
    . '<input type="number" step="any" id="set-' . e($name) . '" name="' . e($name) . '" class="form-control" value="' . e($v[$name] ?? '') . '">'
    . ($suffix ? '<span class="input-group-text">' . e($suffix) . '</span>' : '') . '</div></div>';
?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'checks']) ?>
<form method="post" action="/settings" data-unsaved>
  <?= csrf_field() ?>
  <input type="hidden" name="_tab" value="checks">
  <?php // 2.5.0 client health score: how much each area counts, and where the bands start ?>
  <div class="card card-dark" id="settings-health">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-heart-pulse me-2"></i>Client health score</h3></div>
    <div class="card-body">
      <p class="small text-muted">One score from 0 to 100 for each client, on the client page, the dashboard, the QBR pack and (if you switch it on for a client) the client portal. Weights are relative: equal weights count each area the same, 0 leaves an area out. An area with no data for a client is skipped and the others fill its place.</p>
      <div class="row g-2">
        <?php foreach (\Align\Health\Health::PILLARS as $key => [$label]): ?>
          <div class="col-md col-6"><?= $num("health_weight_$key", $label . ' weight') ?></div>
        <?php endforeach; ?>
      </div>
      <div class="row g-2">
        <div class="col-md-3 col-6"><?= $num('health_good', 'Healthy from') ?></div>
        <div class="col-md-3 col-6"><?= $num('health_warn', 'Needs attention from') ?></div>
        <div class="col-md-6 small text-muted align-self-center">Below "Needs attention from" is At risk.</div>
      </div>
    </div>
  </div>
  <div class="row">
    <div class="col-lg-6">
      <div class="card card-dark" id="settings-training">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-graduation-cap me-2"></i>Security awareness training</h3></div>
        <div class="card-body">
          <p class="small text-muted">For every source: KnowBe4, Curricula and Huntress SAT uploads.</p>
          <?= $num('sat_training_pass', 'Training passes when at least this share of learners completed it', '', '%') ?>
          <?= $num('sat_click_max', 'Phishing passes when the click rate over the last 12 months is under', '', '%') ?>
          <?= $num('sat_campaign_months', '…and the last phishing campaign is within', '', 'months') ?>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card card-dark" id="settings-backups">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-database me-2"></i>Backups</h3></div>
        <div class="card-body"><?= $num('backup_stale_hours', 'Flag a machine as overdue when its newest restore point is older than', '', 'hours') ?></div>
      </div>
      <div class="card card-dark" id="settings-sla">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-stopwatch me-2"></i>Service levels</h3></div>
        <div class="card-body"><?= $num('sla_target', 'SLA goal (share of targets met)', '', '%') ?>
          <p class="small text-muted mb-0">At or above the goal shows green, up to 10 points below shows amber, lower shows red.</p></div>
      </div>
    </div>
  </div>
  <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
</form>

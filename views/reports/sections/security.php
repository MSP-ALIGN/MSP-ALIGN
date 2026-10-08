<?php
use Align\Reports\Ui;

/**
 * 2.7.0 Security (client-facing): Huntress coverage and incidents, security awareness training, then every
 * automatic security check that has a result (Microsoft 365, Google Workspace, email authentication, Huntress,
 * training) and Huntress's summary reports.
 * @var array $s ReportData::security(); ?string $num
 * Values are counts, fixed labels and text from Huntress or the checks (host names, subjects): escaped here. Report
 * links were checked to be https when synced.
 */
$h = $s['huntress'];
$pct = fn($a, $b) => (int) $b ? (int) round((int) $a / (int) $b * 100) : null;
$fails = count(array_filter($s['checks'], fn($c) => $c[0] === 'fail'));
?>
<section class="rsection">
  <?= Ui::head('Security', $num ?? null, $s['checks'] ? (count($s['checks']) - $fails) . ' of ' . count($s['checks']) . ' checks passing' : '') ?>
  <div class="kpi-row">
    <?php // Headline numbers: protection, incidents, training, phishing ?>
    <?php if ($h): $need = count($h['coverage']['needed']); $ok = $need - count($h['coverage']['missing']) - count($h['coverage']['offline']); ?>
      <?= Ui::kpi($need ? $ok . '/' . $need : (string) count($h['coverage']['agents']), 'Devices protected by Huntress', $need ? 'workstations and servers' : 'agents', $need && $ok < $need ? 'warn' : 'ok') ?>
      <?= Ui::kpi((string) array_sum($s['incidents90']), 'Incidents in 90 days', $s['incidents90'] ? implode(', ', array_map(fn($k) => (int) $s['incidents90'][$k] . ' ' . $k, array_keys($s['incidents90']))) : 'none reported',
          ($s['incidents90']['critical'] ?? 0) ? 'bad' : (array_sum($s['incidents90']) ? 'warn' : 'ok')) ?>
    <?php endif; ?>
    <?php if ($t = $s['training']): $p = $pct($t['completed'], $t['learners']); ?>
      <?= Ui::kpi($p . '%', 'Training completed', (int) $t['completed'] . ' of ' . (int) $t['learners'] . ' people', $p >= \Align\Sat\Sat::trainingPass() ? 'ok' : 'warn') ?>
    <?php endif; ?>
    <?php if ($ph = $s['phishing']): $p = round((int) $ph['clicked'] / (int) $ph['sent'] * 100, 1); ?>
      <?= Ui::kpi($p . '%', 'Phishing click rate', 'last 12 months · ' . (int) $ph['sent'] . ' test emails', $p < \Align\Sat\Sat::clickMax() ? 'ok' : 'warn') ?>
    <?php endif; ?>
  </div>

  <?php // Every check with a result ?>
  <?php if ($s['checks']): ?>
    <table class="rtable compact">
      <thead><tr><th>Check</th><th>Result</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($s['checks'] as $label => [$st, $detail]): ?>
        <tr><td class="name"><?= e($label) ?></td><td><?= Ui::pill($st === 'pass' ? 'Pass' : 'Needs work', $st === 'pass' ? 'ok' : 'bad') ?></td><td class="muted"><?= e($detail) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php // Huntress's own reports for the period ?>
  <?php if ($h && $h['reports']): ?>
    <h3>Huntress summary reports</h3>
    <ul>
      <?php foreach (array_slice($h['reports'], 0, 3) as $r): ?>
        <li><?php if ($r['url']): ?><a href="<?= e($r['url']) ?>"><?php endif; ?><?= e(ucfirst(str_replace('_summary', '', $r['type']))) ?> report, <?= e(fmt_date($r['period_start'])) ?> – <?= e(fmt_date($r['period_end'])) ?><?= $r['url'] ? '</a>' : '' ?>
          <span class="muted">· <?= (int) $r['incidents_reported'] ?> incidents reported<?= $r['signals_investigated'] !== null ? ', ' . (int) $r['signals_investigated'] . ' signals investigated' : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

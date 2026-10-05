<?php
/**
 * 2.2.2 Settings → Diagnostics (admins). @var array $d (System\Diagnostics::all); string $report (the text report)
 * Every value is escaped; nothing here takes input. The report is shown in a read-only box so admins see exactly
 * what they'd share before copying or downloading it.
 */
use Align\System\Diagnostics;

$tab = 'diagnostics';
require __DIR__ . '/_tabs.php';
// How each status looks: icon, color and words for the summary tiles
$look = [
    'ok' => ['fa-circle-check', 'success', 'Healthy'],
    'warn' => ['fa-triangle-exclamation', 'warning', 'Needs a look'],
    'bad' => ['fa-circle-xmark', 'danger', 'Problem'],
    'info' => ['fa-circle-info', 'secondary', ''],
];
$tiles = ['server' => ['Server', 'fa-server'], 'app' => ['App', 'fa-cube'], 'database' => ['Database', 'fa-database'], 'storage' => ['Storage', 'fa-hard-drive'], 'jobs' => ['Background jobs', 'fa-clock-rotate-left']];
// One card of checks: label, value with its status icon, and a note under it when there is one
$card = function (string $id, string $title, string $icon, array $rows) use ($look): string {
    $h = '<div class="card h-100" id="diag-' . $id . '"><div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw ' . $icon . ' me-2 text-secondary"></i>' . e($title) . '</h3></div>'
        . '<ul class="list-group list-group-flush diag-rows">';
    foreach ($rows as $r) {
        [$ic, $tone] = $look[$r['status']] ?? $look['info'];
        $h .= '<li class="list-group-item d-flex gap-3" data-status="' . e($r['status']) . '"><span class="diag-label text-muted">' . e($r['label']) . '</span>'
            . '<span class="ms-auto text-end diag-value"><span>' . e($r['value']) . '</span>'
            . ($r['status'] !== 'info' ? ' <i class="fas ' . $ic . ' text-' . $tone . ' ms-1" aria-label="' . e($look[$r['status']][2]) . '"></i>' : '')
            . ($r['note'] ? '<div class="small text-' . ($r['status'] === 'bad' ? 'danger' : ($r['status'] === 'warn' ? 'warning-emphasis' : 'muted')) . '">' . e($r['note']) . '</div>' : '')
            . '</span></li>';
    }
    return $h . '</ul></div>';
};
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <p class="text-muted mb-0 me-auto">How the server, the database and the background jobs are doing, read just now (<?= e(fmt_datetime($d['at'])) ?>).
    <a href="/settings/diagnostics">Check again</a></p>
  <div class="d-flex gap-2 flex-nowrap">
    <button type="button" class="btn btn-sm btn-default text-nowrap" data-copy="#diag-report"><i class="fas fa-copy me-1"></i>Copy report</button>
    <a class="btn btn-sm btn-default text-nowrap" href="/settings/diagnostics/report"><i class="fas fa-download me-1"></i>Download report</a>
  </div>
</div>

<div class="row g-3 mb-3 diag-tiles">
  <?php foreach ($tiles as $k => [$label, $icon]): [$ic, $tone, $word] = $look[$d['summary'][$k]]; ?>
    <div class="col-6 col-md-4 col-xl">
      <a class="card h-100 text-decoration-none diag-tile border-<?= $tone ?>-subtle" href="#diag-<?= $k ?>" data-tile="<?= $k ?>" data-status="<?= e($d['summary'][$k]) ?>">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <i class="fas <?= $icon ?> fa-lg text-secondary"></i>
          <div><div class="fw-semibold text-body"><?= e($label) ?></div><div class="small text-<?= $tone ?>-emphasis"><i class="fas <?= $ic ?> me-1"></i><?= e($word) ?></div></div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6"><?= $card('server', 'Server', 'fa-server', $d['server']) ?></div>
  <div class="col-lg-6"><?= $card('app', 'App', 'fa-cube', $d['app']) ?></div>
  <div class="col-lg-6"><?= $card('database', 'Database', 'fa-database', $d['database']) ?></div>
  <div class="col-lg-6"><?= $card('storage', 'Storage', 'fa-hard-drive', $d['storage']) ?></div>
  <div class="col-12"><?= $card('jobs', 'Background jobs', 'fa-clock-rotate-left', $d['jobs']) ?></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card h-100" id="diag-data">
      <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-chart-simple me-2 text-secondary"></i>Data</h3></div>
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th scope="col">Kind</th><th scope="col" class="text-end">Count</th><th scope="col" class="d-none d-sm-table-cell">Notes</th></tr></thead>
        <tbody>
          <?php foreach ($d['data'] as [$label, $count, $detail]): ?>
            <tr><td><?= e($label) ?></td><td class="text-end font-monospace"><?= e(number_format($count)) ?></td><td class="small text-muted d-none d-sm-table-cell"><?= e((string) $detail) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100" id="diag-tables">
      <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-table me-2 text-secondary"></i>Largest database tables</h3></div>
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th scope="col">Table</th><th scope="col" class="text-end">Rows (about)</th><th scope="col" class="text-end">Size</th></tr></thead>
        <tbody>
          <?php foreach ($d['tables'] as $t): ?>
            <tr><td class="font-monospace small"><?= e($t['name']) ?></td><td class="text-end font-monospace"><?= e(number_format((int) $t['rows_est'])) ?></td>
              <td class="text-end font-monospace"><?= e(Diagnostics::bytes((int) $t['data'] + (int) $t['idx'])) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="card-footer small text-muted">Row counts are the database's estimate; the Data card has exact counts.</div>
    </div>
  </div>
</div>

<div class="card mb-3" id="diag-problems">
  <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-bug me-2 text-secondary"></i>Problems in the last 7 days</h3></div>
  <?php if (!$d['problems']): ?>
    <div class="card-body text-muted"><i class="fas fa-circle-check text-success me-1"></i>None: no failed syncs, emails or update and backup jobs.</div>
  <?php else: ?>
    <ul class="list-group list-group-flush">
      <?php foreach ($d['problems'] as $p): ?>
        <li class="list-group-item d-flex flex-wrap gap-2">
          <span class="text-muted small text-nowrap"><?= e($p['at'] !== '' ? fmt_datetime($p['at']) : '') ?></span>
          <span class="fw-semibold"><?= e($p['what']) ?></span>
          <?php if ($p['detail'] !== ''): ?><span class="small text-muted w-100"><?= e($p['detail']) ?></span><?php endif; ?>
          <a class="small ms-auto" href="<?= e($p['link']) ?>">Open</a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<details class="card mb-3" id="diag-report-box">
  <summary class="card-header py-2"><span class="card-title h6 mb-0"><i class="fas fa-fw fa-file-lines me-2 text-secondary"></i>The report you can share</span>
    <span class="small text-muted ms-2">Same facts as above, without the site address or error text, for a support request or a GitHub issue.</span></summary>
  <div class="card-body"><textarea class="form-control font-monospace small" id="diag-report" rows="18" readonly aria-label="Diagnostics report"><?= e($report) ?></textarea></div>
</details>

<?php
/**
 * Reports hub: every client report, export and all-client report in one place.
 * @var array $clients, $meta, $years; int $currentYear; bool $backupEnabled; int $preselect
 */
$chk = function (string $name, string $label, bool $on = true) {
    $id = 'r-' . bin2hex(random_bytes(3));
    return '<input type="hidden" name="' . e($name) . '" value="0"><div class="custom-control custom-checkbox mr-3 mb-1"><input type="checkbox" class="custom-control-input" id="' . $id . '" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '>'
        . '<label class="custom-control-label font-weight-normal" for="' . $id . '">' . e($label) . '</label></div>';
};
$card = function (string $icon, string $title, string $desc, string $path, string $body = '', string $needs = '', string $button = 'Open report', string $target = '_blank') {
    return '<div class="col-xl-4 col-md-6 d-flex"><form class="card card-outline card-primary report-card flex-fill" method="get" target="' . $target . '" data-report="' . e($path) . '"' . ($needs ? ' data-needs="' . e($needs) . '"' : '') . '>'
        . '<div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw ' . e($icon) . ' mr-2 text-primary"></i>' . e($title) . '</h3></div>'
        . '<div class="card-body py-2 d-flex flex-column"><p class="small text-muted mb-2">' . $desc . '</p><div class="d-flex flex-wrap small mb-2">' . $body . '</div>'
        . '<div class="mt-auto"><div class="small text-warning report-unavailable d-none mb-2"></div><button class="btn btn-sm btn-primary"><i class="fas fa-' . ($target === '_self' ? 'download' : 'up-right-from-square') . ' mr-1"></i>' . e($button) . '</button></div></div></form></div>';
};
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h3 mb-0 mr-auto"><i class="fas fa-print text-secondary mr-2"></i>Reports</h1>
  <span class="small text-muted">Reports open in a print-ready tab: use <b>Print / Save as PDF</b> to hand one to a client. Options can also be changed at the top of each report.</span>
</div>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-users mr-2"></i>Client reports</h3></div>
  <div class="card-body pb-0">
    <div class="form-row align-items-end">
      <div class="form-group col-md-6 col-lg-5"><label for="report-client">Client</label>
        <select class="form-control" id="report-client" <?= $clients ? '' : 'disabled' ?>>
          <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $preselect === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          <?php if (!$clients): ?><option>No clients in planning yet</option><?php endif; ?>
        </select></div>
      <div class="form-group col small text-muted">Pick a client, then open any report below for them.</div>
    </div>
  </div>
</div>
<script type="application/json" id="report-meta"><?= json_encode($meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<div class="row">
  <?= $card('fa-book-open', 'Business review pack (QBR)',
      'Cover, executive summary with highlights and decisions needed, then each section and your team &amp; next steps. Choose the sections to include (with costs off, budget and licensing are left out).',
      '/report/qbr',
      $chk('s_roadmap', 'Roadmap') . $chk('s_budget', 'Budget') . $chk('s_assets', 'Assets') . ($backupEnabled ? $chk('s_backup', 'Backups') : '') . $chk('s_compliance', 'Compliance') . $chk('s_licensing', 'Licensing')
      . '<div class="w-100"></div>' . $chk('costs', 'Costs') . $chk('users', 'Last user') . $chk('notes', 'Notes') . $chk('inventory', 'Full inventory appendix', false)) ?>
  <?= $card('fa-desktop', 'Asset & lifecycle report',
      'Fleet at a glance, health by device type, operating systems, the replacement plan by quarter, devices needing attention and the full inventory.',
      '/report/assets',
      $chk('costs', 'Costs') . $chk('inventory', 'Full inventory') . $chk('users', 'Last user') . $chk('virtual', 'Virtual machines', false) . $chk('notes', 'Device notes')) ?>
  <?= $card('fa-road', '3-year technology roadmap',
      'Year tiles, the quarterly hardware and project chart, a quarter-by-quarter timeline and projects with status and decisions.',
      '/report/roadmap',
      $chk('costs', 'Costs') . $chk('notes', 'Project descriptions')) ?>
  <?= $card('fa-coins', 'Technology budget',
      'Summary tiles, the three-year quarterly chart, categories, line items, contracts &amp; renewals and the three-year outlook.',
      '/report/budget',
      '<div class="form-group mb-1 mr-3"><select name="year" class="custom-select custom-select-sm" aria-label="Budget year">'
      . implode('', array_map(fn($y, $i) => '<option value="' . $i . '"' . ($i === $currentYear ? ' selected' : '') . '>' . e($y['label']) . ' (' . e($y['range']) . ')</option>', $years, array_keys($years)))
      . '</select></div>' . $chk('details', 'Line items') . $chk('notes', 'Notes')) ?>
  <?php if ($backupEnabled): ?>
  <?= $card('fa-database', 'Backup & recovery',
      'Backup health from Veeam: job results, the 30-day history, protected machines, Microsoft 365, anything needing attention and items marked not required.',
      '/report/backup',
      $chk('details', 'Job details') . $chk('machines', 'Protected machines'), 'veeam') ?>
  <?php endif; ?>
  <?= $card('fa-clipboard-check', 'Compliance checklist (CSV)',
      'Every control in a framework with its status, owner, due date, notes, evidence and linked document, as a spreadsheet.',
      'compliance',
      '<div class="form-group mb-1 w-100"><select class="custom-select custom-select-sm report-framework" aria-label="Framework"></select></div>', 'frameworks', 'Download CSV', '_self') ?>
  <?= $card('fa-file-csv', 'Device list (CSV)',
      'All of the client\'s devices with type, make and model, serial, OS, last user, dates, lifecycle status and replacement cost.',
      '/export', '', '', 'Download CSV', '_self') ?>
  <?= $card('fa-file-lines', 'Policies & documents',
      'Print any of the client\'s documents (WISP, policies, procedures) with your branding.',
      'document',
      '<div class="form-group mb-1 w-100"><select class="custom-select custom-select-sm report-document" aria-label="Document"></select></div>', 'documents', 'Open document') ?>
</div>

<div class="card card-dark mt-2">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-layer-group mr-2"></i>All clients (internal)</h3></div>
  <div class="card-body pb-0">
    <div class="row">
      <div class="col-xl-4 col-md-6 d-flex">
        <form class="card card-outline card-secondary flex-fill" method="get" action="/reports/portfolio" target="_blank">
          <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-chart-column mr-2 text-secondary"></i>Portfolio summary</h3></div>
          <div class="card-body py-2 d-flex flex-column"><p class="small text-muted mb-2">Every client ranked by risk: health bars, past end of life, old OS, compliance<?= $backupEnabled ? ', backups' : '' ?>, last review and hardware spend by plan year.</p>
            <div class="d-flex flex-wrap small mb-2"><?= $chk('costs', 'Costs') ?></div>
            <div class="mt-auto"><button class="btn btn-sm btn-default"><i class="fas fa-up-right-from-square mr-1"></i>Open report</button></div></div>
        </form>
      </div>
      <?php if ($backupEnabled): ?>
      <div class="col-xl-4 col-md-6 d-flex">
        <form class="card card-outline card-secondary flex-fill" method="get" action="/reports/backups" target="_blank">
          <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-database mr-2 text-secondary"></i>Backup status</h3></div>
          <div class="card-body py-2 d-flex flex-column"><p class="small text-muted mb-2">Every client linked to Veeam: failed jobs, machines and Microsoft 365 items without a recent backup, servers with no backup, success rate and cloud storage.</p>
            <div class="d-flex flex-wrap small mb-2"><?= $chk('all', 'Include clients with no problems') ?></div>
            <div class="mt-auto"><button class="btn btn-sm btn-default"><i class="fas fa-up-right-from-square mr-1"></i>Open report</button></div></div>
        </form>
      </div>
      <?php endif; ?>
      <div class="col-xl-4 col-md-6 d-flex">
        <div class="card card-outline card-secondary flex-fill">
          <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-calendar-check mr-2 text-secondary"></i>Contracts &amp; renewals</h3></div>
          <div class="card-body py-2 d-flex flex-column"><p class="small text-muted mb-2">Upcoming contract end dates, notice deadlines and license renewals across all clients.</p>
            <div class="mt-auto"><a class="btn btn-sm btn-default" href="/renewals?days=90">Next 90 days</a> <a class="btn btn-sm btn-default" href="/renewals?days=365">Next 12 months</a></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

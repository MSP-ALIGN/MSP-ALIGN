<?php
use Align\Reports\Ui;

/** @var array $r ReportData::roadmap(); array $opt; ?array $comp; ?array $summary */
$costs = (bool) $opt['costs'];
echo \Align\View::fetch('reports/sections/roadmap_overview', ['r' => $r, 'costs' => $costs]);
echo \Align\View::fetch('reports/sections/roadmap_timeline', ['r' => $r, 'costs' => $costs]);
echo \Align\View::fetch('reports/sections/roadmap_projects', ['r' => $r, 'costs' => $costs, 'notes' => (bool) $opt['notes']]);
if (!empty($summary)): ?>
<section class="rsection avoid-break">
  <?= Ui::head('Where things stand today') ?>
  <div class="kpi-row">
    <?= Ui::kpi((string) $summary['total'], 'Devices', 'tracked') ?>
    <?= Ui::kpi((string) $summary['replace'], 'Past end of life', 'replace now', $summary['replace'] ? 'bad' : 'ok') ?>
    <?= Ui::kpi((string) $summary['os_eos'], 'Unsupported OS', 'no security updates', $summary['os_eos'] ? 'bad' : 'ok') ?>
    <?= Ui::kpi((string) $summary['warranty_expired'], 'Out of warranty', '', $summary['warranty_expired'] ? 'warn' : 'ok') ?>
  </div>
</section>
<?php endif;
if (!empty($comp['frameworks'])) {
    echo \Align\View::fetch('reports/sections/compliance', ['c' => ['open' => []] + $comp]);
}

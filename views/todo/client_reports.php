<?php
/** A client's reports (1.42). @var array $client */
require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$backup = \Align\Backup\Backup::has($client);
$sla = !empty($client['psa_id']) && \Align\Service\Sla::enabled();
$reports = [
    ['fa-book-open', 'Business review pack (QBR)', 'Everything for a review meeting in one document: roadmap, budget, lifecycle, security and service levels.', "/clients/$cid/report/qbr", true],
    ['fa-desktop', 'Asset & lifecycle report', 'Every device with its age, warranty, end of life and replacement cost.', "/clients/$cid/report/assets", true],
    ['fa-file-lines', 'Asset summary', 'The same report without the device-by-device inventory.', "/clients/$cid/report/assets?inventory=0", true],
    ['fa-road', '3-year roadmap', 'Planned projects and hardware replacements by quarter.', "/clients/$cid/report/roadmap", true],
    ['fa-coins', 'Technology budget', 'Recurring costs, licensing, projects and replacements by year.', "/clients/$cid/report/budget", true],
    ...($backup ? [['fa-database', 'Backup & recovery', 'What is backed up, how recently, and anything that needs attention.', "/clients/$cid/report/backup", true]] : []),
    ...($sla ? [['fa-stopwatch', 'Service levels', 'Tickets answered and resolved on time, month by month.', "/clients/$cid/report/sla", true]] : []),
    ['fa-file-csv', 'Device list (CSV)', 'The lifecycle table as a spreadsheet.', "/clients/$cid/export", false],
];
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-print', 'title' => 'Reports', 'count' => count($reports),
    'desc' => 'Each report opens in a new tab, ready to print or save as PDF. More options (sections, costs, frameworks) are on the Reports page.',
    'primary' => '<a class="btn btn-sm btn-primary" href="/clients/' . $cid . '/report/qbr" target="_blank"><i class="fas fa-book-open me-1"></i>Business review pack</a>',
    'secondary' => ['<a class="btn btn-sm btn-default" href="/reports?client=' . $cid . '"><i class="fas fa-sliders me-1"></i>All options</a>'],
]);
?>
<div class="card"><ul class="list-group list-group-flush">
  <?php foreach ($reports as [$icon, $title, $desc, $href, $tab]): ?>
    <li class="list-group-item d-flex align-items-center">
      <i class="fas fa-fw <?= e($icon) ?> text-secondary fa-lg me-3"></i>
      <div class="me-auto pe-3"><a class="fw-bold" href="<?= e($href) ?>"<?= $tab ? ' target="_blank"' : '' ?>><?= e($title) ?></a><div class="small text-muted"><?= e($desc) ?></div></div>
      <a class="btn btn-sm btn-default text-nowrap" href="<?= e($href) ?>"<?= $tab ? ' target="_blank"' : '' ?>><?= $tab ? 'Open' : 'Download' ?></a>
    </li>
  <?php endforeach; ?>
</ul></div>

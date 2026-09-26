<?php
/** @var array $bd ReportData::budget(); array $opt */
echo \Align\View::fetch('reports/sections/budget_overview', ['bd' => $bd]);
if ($opt['details']) {
    echo \Align\View::fetch('reports/sections/budget_lines', ['bd' => $bd]);
}
echo \Align\View::fetch('reports/sections/budget_contracts', ['bd' => $bd]);
echo \Align\View::fetch('reports/sections/budget_outlook', ['bd' => $bd, 'notes' => (bool) $opt['notes']]);

<?php
/** @var array $b ReportData::backup(); array $opt */
echo \Align\View::fetch('reports/sections/backup', ['b' => $b, 'details' => (bool) $opt['details'], 'machines' => (bool) $opt['machines']]);

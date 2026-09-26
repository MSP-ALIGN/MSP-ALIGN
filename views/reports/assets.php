<?php
/** @var array $a ReportData::assets(); array $opt */
$costs = (bool) $opt['costs'];
$users = (bool) ($opt['users'] ?? true);
$common = ['a' => $a, 'costs' => $costs, 'users' => $users];
echo \Align\View::fetch('reports/sections/assets_overview', $common);
echo \Align\View::fetch('reports/sections/assets_plan', $common);
echo \Align\View::fetch('reports/sections/assets_attention', $common + ['limit' => $opt['inventory'] ? 40 : null, 'moreNote' => 'Every device is listed in the inventory that follows.']);
if ($opt['inventory']) {
    echo \Align\View::fetch('reports/sections/assets_inventory', $common);
}

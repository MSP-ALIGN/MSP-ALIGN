<?php
/** @var array $s Sla::report(); array $opt */
echo \Align\View::fetch('reports/sections/sla', ['s' => $s, 'missed' => (bool) ($opt['missed'] ?? false)]);

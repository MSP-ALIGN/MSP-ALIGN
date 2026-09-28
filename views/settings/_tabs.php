<?php
/** Settings sub-navigation. @var string $tab */
$tabs = [
    'general' => ['/settings', 'General', 'fa-gear'],
    'planning' => ['/settings/planning', 'Planning & lifecycle', 'fa-recycle'],
    'os' => ['/settings/os', 'OS support dates', 'fa-windows fab'],
    'notifications' => ['/settings/notifications', 'Notifications', 'fa-bell'],
    'onboarding' => ['/settings/onboarding', 'Onboarding', 'fa-mountain-sun'],
    'branding' => ['/settings/branding', 'Branding', 'fa-palette'],
    'api' => ['/settings/api', 'API', 'fa-code'],
    'system' => ['/settings/system', 'Updates & backups', 'fa-arrows-rotate'],
];
$newer = \Align\System\Agent::updateAvailable();
?>
<h1 class="h3 mb-2"><i class="fas fa-gear text-secondary mr-2"></i>Settings</h1>
<ul class="nav nav-tabs settings-tabs mb-3" role="tablist">
  <?php foreach ($tabs as $k => [$href, $label, $icon]): ?>
    <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= $href ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><i class="<?= str_contains($icon, 'fab') ? $icon : 'fas ' . $icon ?> fa-fw mr-1"></i><?= e($label) ?><?= $k === 'system' && $newer ? ' <span class="badge badge-info">new</span>' : '' ?></a></li>
  <?php endforeach; ?>
  <li class="nav-item ml-auto"><a class="nav-link" href="/integrations" title="Integrations"><i class="fas fa-plug fa-fw"></i><span class="d-none d-xxl-inline ml-1">Integrations</span><span class="sr-only">Integrations</span></a></li>
  <li class="nav-item"><a class="nav-link" href="/help#guide-<?= ['system' => 'backup', 'notifications' => 'email', 'onboarding' => 'onboarding', 'api' => 'api'][$tab] ?? 'settings' ?>" title="Help"><i class="fas fa-circle-question"></i><span class="sr-only">Help</span></a></li>
</ul>

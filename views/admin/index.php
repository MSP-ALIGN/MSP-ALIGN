<?php
/**
 * 2.35.0 The Administration overview: a card per section the signed-in role may open (Todo::adminSections), and for
 * admins shortcuts to Diagnostics and Updates & backups. Every value is escaped; hrefs, icons and text are fixed in code.
 * @var array $sections [key, href, label, icon, role, badge, description]
 */
$extra = \Align\Auth::can('admin') ? [
    ['diagnostics', '/settings/diagnostics', 'Diagnostics', 'fa-stethoscope', 'admin', 0, 'How the server, database and background jobs are doing, and recent problems.'],
    ['updates', '/settings/system', 'Updates & backups', 'fa-circle-arrow-up', 'admin', 0, 'Install updates, download a backup or restore one.'],
] : [];
echo \Align\View::fetch('partials/page_header', ['icon' => 'fa-screwdriver-wrench', 'title' => 'Administration',
    'desc' => 'Setting up ' . \Align\Branding::name() . ': connections, shared libraries, who can sign in, and how it runs.']);
?>
<div class="row g-3" id="admin-sections">
  <?php foreach ([...$sections, ...$extra] as [$key, $href, $label, $icon, $role, $badge, $desc]): ?>
    <div class="col-md-6 col-xl-4">
      <a class="card h-100 mb-0 text-reset library-card" href="<?= e($href) ?>" id="admin-<?= e($key) ?>">
        <div class="card-body">
          <div class="d-flex align-items-center mb-2">
            <i class="fas fa-fw <?= e($icon) ?> fa-lg text-secondary me-2" aria-hidden="true"></i>
            <h2 class="h6 mb-0 me-auto"><?= e($label) ?></h2>
            <?php if ($role === 'admin'): ?><span class="badge text-bg-dark me-1">Admin</span><?php endif; ?>
            <?php if ($badge): ?><span class="badge text-bg-<?= is_int($badge) ? 'warning' : 'info' ?>"><?= is_int($badge) ? (int) $badge . ' to check' : 'Update' ?></span><?php endif; ?>
          </div>
          <p class="small text-muted mb-0"><?= e($desc) ?></p>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

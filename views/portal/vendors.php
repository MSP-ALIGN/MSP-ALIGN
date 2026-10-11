<?php
use Align\Vendors\Vendors;

/**
 * 2.10.0 Client portal: the client's vendors and how to reach them. @var array $vendors (only name, category,
 * services, support phone, email, website, link, hours, sla: PortalController::vendors leaves out account numbers,
 * contacts, notes and costs). Every value is escaped; websites are linked only when http(s) (link, Vendors::url).
 */
$groups = [];
foreach ($vendors as $v) {
    $groups[$v['category']][] = $v;
}
?>
<div class="d-flex flex-wrap align-items-center portal-page-head">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-store me-2 text-secondary"></i>Vendors</h1>
    <div class="small text-muted">The companies you buy technology from, and how to reach their support. For anything else, contact us first.</div></div>
</div>
<div class="card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0" id="portal-vendors">
      <thead><tr><th>Vendor</th><th>Support</th><th>Hours</th></tr></thead>
      <tbody>
      <?php if (!$vendors): ?><tr><td colspan="3" class="text-center text-muted py-4">No vendors have been added yet.</td></tr><?php endif; ?>
      <?php foreach ($groups as $cat => $rows): [$label, $icon] = Vendors::CATEGORIES[$cat] ?? Vendors::CATEGORIES['other']; ?>
        <tr class="proj-quarter"><th colspan="3"><i class="fas fa-fw <?= e($icon) ?> text-secondary me-1"></i><?= e($label) ?></th></tr>
        <?php foreach ($rows as $v): ?>
          <tr>
            <td><b><?= e($v['name']) ?></b><?php if ($v['services']): ?><div class="small text-muted"><?= e($v['services']) ?></div><?php endif; ?></td>
            <td class="small">
              <?php if ($v['support_phone']): ?><div class="text-nowrap"><i class="fas fa-phone fa-fw text-muted me-1"></i><?= e($v['support_phone']) ?></div><?php endif; ?>
              <?php if ($v['support_email']): ?><div><i class="fas fa-envelope fa-fw text-muted me-1"></i><?= e($v['support_email']) ?></div><?php endif; ?>
              <?php if ($v['link']): ?><div><i class="fas fa-arrow-up-right-from-square fa-fw text-muted me-1"></i><a href="<?= e($v['link']) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('#^https?://(www\.)?#i', '', rtrim($v['link'], '/'))) ?></a></div><?php endif; ?>
              <?php if (!$v['support_phone'] && !$v['support_email'] && !$v['link']): ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="small"><?= e(implode(' · ', array_filter([$v['hours'], $v['sla'] ? 'response ' . $v['sla'] : null]))) ?: '<span class="text-muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

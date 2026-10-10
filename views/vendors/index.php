<?php
/**
 * 2.8.0 Vendor templates: the shared details entered once (support line, website, hours) and which clients use each.
 * @var array $templates Vendors::templates(); array $users template id => [[id, name], ...]; int $clientVendors; string $back
 * Every value is escaped; websites are linked only when they are http(s) (Vendors::url).
 */
use Align\Auth;
use Align\Vendors\Vendors;

$canEdit = Auth::can('tech');
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-store', 'title' => 'Vendors', 'count' => count($templates),
    'desc' => 'Vendor templates hold what\'s the same for every client: name, category, support line, website and hours. Add a template to a client from its <b>Vendors</b> page; each client keeps its own account number, contact and services, and can override any shared field. '
        . num($clientVendors) . ' client vendor' . ($clientVendors === 1 ? '' : 's') . ' in all.',
    'help' => $canEdit ? 'guide-vendors' : null,
    'secondary' => $canEdit ? ['<a class="btn btn-sm btn-default ms-1" href="/clients/import?kind=vendors"><i class="fas fa-file-import me-1"></i>Import client vendors</a>'] : [], // 2.9.0
    'primary' => $canEdit ? '<button class="btn btn-sm btn-primary ms-1" data-bs-toggle="modal" data-bs-target="#modal-vtemplate"><i class="fas fa-plus me-1"></i>Add template</button>' : null,
]);
?>
<div class="card card-dark">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" id="vendor-templates">
        <thead><tr><th>Template</th><th>Category</th><th>Support</th><th>Clients</th></tr></thead>
        <tbody>
        <?php if (!$templates): ?><tr><td colspan="4" class="text-center text-muted py-4">No templates yet.<?= $canEdit ? ' Add one here, or use <b>Save as template</b> on a client\'s vendor.' : '' ?></td></tr><?php endif; ?>
        <?php foreach ($templates as $t): $cl = $users[(int) $t['id']] ?? []; $link = Vendors::url($t['website']); [$cat, $icon] = Vendors::CATEGORIES[$t['category']] ?? Vendors::CATEGORIES['other']; ?>
          <tr>
            <td><?php if ($canEdit): ?><a href="#" class="fw-bold" data-lazy-modal="/vendors/templates/<?= (int) $t['id'] ?>/form?back=<?= e(rawurlencode($back)) ?>" data-bs-target="#modal-vtemplate-<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a><?php else: ?><b><?= e($t['name']) ?></b><?php endif; ?>
              <?php if ($t['psa_template_id']): ?><span class="badge text-bg-light border" title="Linked to a vendor template in <?= e(psa_name()) ?>"><?= e(psa_name()) ?></span><?php endif; ?>
              <?php if ($t['notes']): ?><div class="small text-muted text-truncate" style="max-width: 24rem"><?= e($t['notes']) ?></div><?php endif; ?></td>
            <td class="small text-nowrap"><i class="fas fa-fw <?= e($icon) ?> text-secondary me-1"></i><?= e($cat) ?></td>
            <td class="small">
              <?php if ($t['support_phone']): ?><div class="text-nowrap"><i class="fas fa-phone fa-fw text-muted me-1"></i><?= e($t['support_phone']) ?></div><?php endif; ?>
              <?php if ($t['support_email']): ?><div><i class="fas fa-envelope fa-fw text-muted me-1"></i><?= e($t['support_email']) ?></div><?php endif; ?>
              <?php if ($link): ?><div><i class="fas fa-arrow-up-right-from-square fa-fw text-muted me-1"></i><a href="<?= e($link) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('#^https?://(www\.)?#i', '', rtrim($link, '/'))) ?></a></div><?php endif; ?>
              <?php if ($t['hours'] || $t['sla']): ?><div class="text-muted"><?= e(implode(' · ', array_filter([$t['hours'], $t['sla'] ? 'SLA ' . $t['sla'] : null]))) ?></div><?php endif; ?>
            </td>
            <td class="small"><?php if ($cl): ?><?= implode(', ', array_map(fn($c) => '<a href="/clients/' . (int) $c['id'] . '/vendors">' . e($c['name']) . '</a>', array_slice($cl, 0, 8))) ?><?= count($cl) > 8 ? ' <span class="text-muted">+' . (count($cl) - 8) . ' more</span>' : '' ?>
              <?php else: ?><span class="text-muted">None yet</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php if ($canEdit) echo \Align\View::fetch('vendors/_template_modal', ['t' => null, 'back' => $back]); ?>

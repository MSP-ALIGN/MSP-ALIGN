<?php
use Align\Auth;
use Align\Contacts\Contacts;

/** @var array $contacts; $showClient; $back */
$showClient = $showClient ?? false;
$canEdit = Auth::can('tech');
$tel = fn(string $n) => 'tel:' . preg_replace('/[^\d+]/', '', $n);
?>
<div class="table-responsive">
<table class="table table-sm table-hover mb-0" id="contacts-table">
  <thead><tr><th>Name</th><?php if ($showClient): ?><th>Client</th><?php endif; ?><th>Email</th><th>Phone</th><th>Mobile</th><th>Roles</th></tr></thead>
  <tbody>
  <?php if (!$contacts): ?><tr><td colspan="<?= $showClient ? 6 : 5 ?>" class="text-center text-muted py-4">No contacts yet.</td></tr><?php endif; ?>
  <?php foreach ($contacts as $k): ?>
    <tr class="<?= $k['archived_at'] ? 'text-muted' : '' ?>">
      <td>
        <?php if ($canEdit): ?><a href="#" class="font-weight-bold" data-toggle="modal" data-target="#modal-contact-<?= (int) $k['id'] ?>"><?= e($k['name']) ?></a><?php else: ?><b><?= e($k['name']) ?></b><?php endif; ?>
        <?php if ($k['source'] === 'itflow'): ?><span class="badge badge-light border">ITFlow</span><?php endif; ?>
        <?php if ($k['archived_at']): ?><span class="badge badge-secondary">archived<?= $k['archived_reason'] === 'itflow' ? ' in ITFlow' : '' ?></span><?php endif; ?>
        <?php if ($k['title'] || $k['department'] || $k['location']): ?><div class="small text-muted"><?= e(implode(' · ', array_filter([$k['title'], $k['department'], $k['location']]))) ?></div><?php endif; ?>
        <?php if ($k['align_notes']): ?><div class="small text-muted text-truncate" style="max-width:360px" title="<?= e($k['align_notes']) ?>"><i class="fas fa-note-sticky mr-1"></i><?= e($k['align_notes']) ?></div><?php endif; ?>
      </td>
      <?php if ($showClient): ?><td class="small"><a href="/clients/<?= (int) $k['client_id'] ?>/contacts"><?= e($k['client_name']) ?></a></td><?php endif; ?>
      <td class="small"><?= $k['email'] ? '<a href="mailto:' . e($k['email']) . '">' . e($k['email']) . '</a>' : '' ?></td>
      <td class="small text-nowrap"><?= $k['phone'] ? '<a href="' . e($tel($k['phone'])) . '">' . e(Contacts::phone($k)) . '</a>' : '' ?></td>
      <td class="small text-nowrap"><?= $k['mobile'] ? '<a href="' . e($tel($k['mobile'])) . '">' . e($k['mobile']) . '</a>' : '' ?></td>
      <td><?php foreach (Contacts::ROLES as $col => [$label, $tone]): if (!empty($k[$col])): ?><span class="badge badge-<?= $tone ?> mr-1"><?= e($label) ?></span><?php endif; endforeach; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($canEdit) foreach ($contacts as $k) echo \Align\View::fetch('contacts/_modal', ['k' => $k, 'back' => $back]); ?>

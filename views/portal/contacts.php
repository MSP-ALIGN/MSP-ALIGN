<?php
use Align\Contacts\Contacts;

/** @var array $pu, $contacts; bool $psaEditable */
$canEdit = (bool) $pu['can_contacts'];
$tel = fn(string $n) => 'tel:' . preg_replace('/[^\d+]/', '', $n);
$modal = function (?array $k) use ($psaEditable): string {
    $id = $k ? 'pc-' . (int) $k['id'] : 'pc-new';
    $locked = $k && $k['source'] === 'psa' && !$psaEditable;
    $ro = $locked ? 'readonly' : '';
    $chk = fn(string $n) => !empty($k[$n]) ? 'checked' : '';
    ob_start(); ?>
    <div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post" action="<?= $k ? '/portal/contacts/' . (int) $k['id'] : '/portal/contacts' ?>">
          <?= csrf_field() ?>
          <div class="modal-header"><h5 class="modal-title"><?= $k ? e($k['name']) : 'Add a contact' ?></h5><button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button></div>
          <div class="modal-body">
            <?php if ($locked): ?><div class="alert alert-light border small py-2">These details are managed by your IT provider. You can still set the roles below; ask your IT provider to change anything else.</div><?php endif; ?>
            <div class="form-row">
              <div class="form-group col-md-4"><label>Name</label><input name="name" class="form-control" value="<?= e($k['name'] ?? '') ?>" <?= $ro ?: 'required' ?> maxlength="190"></div>
              <div class="form-group col-md-4"><label>Title</label><input name="title" class="form-control" value="<?= e($k['title'] ?? '') ?>" <?= $ro ?> maxlength="190"></div>
              <div class="form-group col-md-4"><label>Department</label><input name="department" class="form-control" value="<?= e($k['department'] ?? '') ?>" <?= $ro ?> maxlength="190"></div>
            </div>
            <div class="form-row">
              <div class="form-group col-md-4"><label>Email</label><input type="email" name="email" class="form-control" value="<?= e($k['email'] ?? '') ?>" <?= $ro ?>></div>
              <div class="form-group col-md-3"><label>Phone</label><input name="phone" class="form-control" value="<?= e($k['phone'] ?? '') ?>" <?= $ro ?> maxlength="60"></div>
              <div class="form-group col-md-2"><label>Ext.</label><input name="extension" class="form-control" value="<?= e($k['extension'] ?? '') ?>" <?= $ro ?> maxlength="20"></div>
              <div class="form-group col-md-3"><label>Mobile</label><input name="mobile" class="form-control" value="<?= e($k['mobile'] ?? '') ?>" <?= $ro ?> maxlength="60"></div>
            </div>
            <div class="d-flex flex-wrap">
              <div class="custom-control custom-checkbox mr-4"><input type="checkbox" class="custom-control-input" id="<?= $id ?>-dm" name="decision_maker" value="1" <?= $chk('decision_maker') ?>><label class="custom-control-label font-weight-normal" for="<?= $id ?>-dm">Decision maker (signs off on budget and projects)</label></div>
              <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="<?= $id ?>-qbr" name="qbr" value="1" <?= $chk('qbr') ?>><label class="custom-control-label font-weight-normal" for="<?= $id ?>-qbr">Invite to business review meetings</label></div>
            </div>
          </div>
          <div class="modal-footer">
            <?php if ($k): ?><button class="btn btn-outline-secondary mr-auto" name="action" value="remove" formnovalidate data-confirm="Remove <?= e($k['name']) ?> from your contacts?"><i class="fas fa-user-minus mr-1"></i>Remove</button><?php endif; ?>
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check mr-1"></i>Save</button>
          </div>
        </form>
      </div></div>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <div class="mr-auto"><h1 class="h4 mb-0"><i class="fas fa-address-book mr-2 text-secondary"></i>Contacts</h1>
    <div class="small text-muted">The people at your organization your IT provider works with.<?= $canEdit ? ' Keep this list current so the right people are contacted and invited to reviews.' : '' ?></div></div>
  <?php if ($canEdit): ?><button class="btn btn-sm btn-primary mt-2 mt-md-0" data-toggle="modal" data-target="#pc-new"><i class="fas fa-user-plus mr-1"></i>Add contact</button><?php endif; ?>
</div>
<div class="card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Mobile</th><th>Roles</th></tr></thead>
      <tbody>
      <?php foreach ($contacts as $k): ?>
        <tr>
          <td><?php if ($canEdit): ?><a href="#" class="font-weight-bold" data-toggle="modal" data-target="#pc-<?= (int) $k['id'] ?>"><?= e($k['name']) ?></a><?php else: ?><b><?= e($k['name']) ?></b><?php endif; ?>
            <?php if ($k['title'] || $k['department']): ?><div class="small text-muted"><?= e(implode(' · ', array_filter([$k['title'], $k['department']]))) ?></div><?php endif; ?></td>
          <td class="small"><?= $k['email'] ? '<a href="mailto:' . e($k['email']) . '">' . e($k['email']) . '</a>' : '' ?></td>
          <td class="small text-nowrap"><?= $k['phone'] ? '<a href="' . e($tel($k['phone'])) . '">' . e(Contacts::phone($k)) . '</a>' : '' ?></td>
          <td class="small text-nowrap"><?= $k['mobile'] ? '<a href="' . e($tel($k['mobile'])) . '">' . e($k['mobile']) . '</a>' : '' ?></td>
          <td><?php foreach (Contacts::ROLES as $col => [$label, $tone]): if (!empty($k[$col])): ?><span class="badge badge-<?= $tone ?> mr-1"><?= e($label) ?></span><?php endif; endforeach; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$contacts): ?><tr><td colspan="5" class="text-center text-muted py-4">No contacts yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($canEdit) { echo $modal(null); foreach ($contacts as $k) echo $modal($k); } ?>

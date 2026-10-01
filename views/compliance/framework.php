<?php
use Align\Compliance\Compliance;

$auto = fn(string $name, ?string $cur) => '<select name="' . $name . '" class="form-select form-select-sm"><option value="">—</option>'
    . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . e($l) . '</option>', array_keys(Compliance::AUTO_CHECKS), Compliance::AUTO_CHECKS))
    . '</select>';
?>
<div class="small"><a href="/frameworks">Frameworks</a> /</div>
<form method="post" action="/frameworks/<?= (int) $fw['id'] ?>" data-post-changed data-unsaved
  data-confirm-rules="<?= e(json_encode([
      ['count' => '[name$="[delete]"]:checked', 'title' => 'Delete {n} controls?', 'danger' => true, 'ok' => 'Delete and save',
       'text' => $inUse ? 'Their answers, notes and evidence are deleted for the ' . (int) $inUse . ' client' . ($inUse == 1 ? '' : 's') . ' using this framework. This can\'t be undone.' : 'This can\'t be undone.'],
      ['changed' => 'is_active', 'is' => ['is_active' => '0'], 'title' => 'Make this framework inactive?', 'text' => 'It can\'t be assigned to more clients. Clients that have it keep it.'],
  ])) ?>">
  <?= csrf_field() ?>
  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check me-2"></i><?= e($fw['name']) ?></h3>
      <div class="card-tools small text-light">Used by <?= (int) $inUse ?> client<?= $inUse == 1 ? '' : 's' ?></div></div>
    <div class="card-body">
      <div class="row g-2">
        <div class="mb-3 col-md-6"><label>Name</label><input name="name" class="form-control" value="<?= e($fw['name']) ?>" required></div>
        <div class="mb-3 col-md-6 d-flex align-items-end">
          <div class="form-check form-switch mb-2"><input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" <?= $fw['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active (can be assigned to clients)</label></div>
        </div>
      </div>
      <div class="mb-3 mb-0"><label>Description</label><textarea name="description" class="form-control" rows="2"><?= e($fw['description']) ?></textarea></div>
    </div>
  </div>

  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1">Controls (<?= count($controls) ?>)</h3></div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-borderless mb-0">
        <thead class="text-dark"><tr><th class="w-sort">Order</th><th>Section</th><th>Ref</th><th>Control</th><th>Guidance</th><th>Device check</th><th title="Controls in different frameworks that share a tag are suggested to each other on client checklists">Crosswalk tags</th><th>Delete</th></tr></thead>
        <tbody>
        <?php foreach ($controls as $c): $k = (int) $c['id']; ?>
          <tr class="border-bottom" data-row>
            <td><input type="number" name="ctl[<?= $k ?>][sort]" value="<?= (int) $c['sort'] ?>" class="form-control form-control-sm"></td>
            <td><input name="ctl[<?= $k ?>][section]" value="<?= e($c['section']) ?>" class="form-control form-control-sm"></td>
            <td><input name="ctl[<?= $k ?>][ref]" value="<?= e($c['ref']) ?>" class="form-control form-control-sm"></td>
            <td><textarea name="ctl[<?= $k ?>][title]" class="form-control form-control-sm" rows="1"><?= e($c['title']) ?></textarea></td>
            <td><textarea name="ctl[<?= $k ?>][guidance]" class="form-control form-control-sm" rows="1"><?= e($c['guidance']) ?></textarea></td>
            <td><?= $auto("ctl[$k][auto_check]", $c['auto_check']) ?></td>
            <td><input name="ctl[<?= $k ?>][tags]" value="<?= e(str_replace(',', ', ', (string) $c['tags'])) ?>" class="form-control form-control-sm" list="xw-tags" placeholder="e.g. iam_mfa"></td>
            <td class="text-center"><input type="checkbox" name="ctl[<?= $k ?>][delete]" value="1" aria-label="Delete control"></td>
          </tr>
        <?php endforeach; ?>
          <tr class="bg-light">
            <td class="small text-muted">New</td>
            <td><input name="new[section]" class="form-control form-control-sm" placeholder="Section"></td>
            <td><input name="new[ref]" class="form-control form-control-sm" placeholder="Ref"></td>
            <td><textarea name="new[title]" class="form-control form-control-sm" rows="1" placeholder="Control to add"></textarea></td>
            <td><textarea name="new[guidance]" class="form-control form-control-sm" rows="1"></textarea></td>
            <td><?= $auto('new[auto_check]', null) ?></td>
            <td><input name="new[tags]" class="form-control form-control-sm" list="xw-tags" placeholder="Tags"></td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex">
      <?php if (!$inUse): ?><button class="btn btn-outline-danger btn-sm me-auto" name="action" value="delete" data-confirm="Delete this framework and all its controls?">Delete framework</button><?php else: ?><span class="me-auto"></span><?php endif; ?>
      <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Save</button>
    </div>
  </div>
  <datalist id="xw-tags"><?php foreach ($allTags as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
</form>

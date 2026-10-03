<?php
/** Settings → OS support dates (admins). @var array $rows the os_support table. Every value is escaped; ids are cast to int. */
?><?= \Align\View::fetch('settings/_tabs', ['tab' => 'os']) ?>
<form method="post" action="/settings/os" data-unsaved data-confirm-rules="<?= e(json_encode([
    ['count' => '[name$="[delete]"]:checked', 'title' => 'Delete {n} OS rows?', 'danger' => true, 'ok' => 'Delete and save',
     'text' => 'Devices that matched them get their support status from the remaining rows, or show as unknown.'],
    ['changes' => true, 'title' => 'Save the OS support dates?', 'ok' => 'Save',
     'text' => 'The support status of every device with a matching operating system is updated, on every client.'],
])) ?>">
  <?= csrf_field() ?>
  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-2"><i class="fab fa-fw fa-windows me-2"></i>OS support dates</h3>
      <div class="card-tools"><button class="btn btn-sm btn-primary"><i class="fas fa-check me-1"></i>Save</button></div></div>
    <div class="card-body py-2 small text-muted border-bottom">A device matches a row when its OS name contains the text and its build number is equal. The longest matching text wins, so edition rows ("Windows 11 Enterprise") beat general ones. Add a row when Microsoft ships a new release.</div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-borderless mb-0">
        <thead class="text-dark"><tr><th>Label</th><th>OS name contains</th><th>Build</th><th>Support ends</th><th>Delete</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
          <tr class="border-bottom <?= $r['eos_date'] < date('Y-m-d') ? 'text-muted' : '' ?>">
            <td><input name="rows[<?= $id ?>][label]" value="<?= e($r['label']) ?>" class="form-control form-control-sm"></td>
            <td><input name="rows[<?= $id ?>][name_contains]" value="<?= e($r['name_contains']) ?>" class="form-control form-control-sm"></td>
            <td><input name="rows[<?= $id ?>][build]" value="<?= e($r['build']) ?>" class="form-control form-control-sm"></td>
            <td><input type="date" name="rows[<?= $id ?>][eos_date]" value="<?= e($r['eos_date']) ?>" class="form-control form-control-sm"></td>
            <td class="text-center align-middle"><input type="checkbox" name="rows[<?= $id ?>][delete]" value="1" aria-label="Delete"></td>
          </tr>
        <?php endforeach; ?>
          <tr class="bg-light">
            <td><input name="new[label]" placeholder="New: e.g. Windows 11 26H2" class="form-control form-control-sm"></td>
            <td><input name="new[name_contains]" placeholder="Windows 11" class="form-control form-control-sm"></td>
            <td><input name="new[build]" placeholder="26300" class="form-control form-control-sm"></td>
            <td><input type="date" name="new[eos_date]" class="form-control form-control-sm"></td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</form>

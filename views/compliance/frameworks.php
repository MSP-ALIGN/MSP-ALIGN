<?= \Align\View::fetch('partials/section_tabs', ['tabs' => [['/compliance', 'Overview', 'fa-clipboard-check', false], ['/frameworks', 'Frameworks', 'fa-list-check', true, \Align\Auth::can('admin')]]]) ?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-list-check mr-2"></i>Compliance frameworks</h3>
    <div class="card-tools"><button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-framework"><i class="fas fa-plus mr-1"></i>New framework</button></div>
  </div>
  <div class="card-body p-0">
    <table class="table table-striped table-borderless table-hover mb-0">
      <thead class="text-dark"><tr><th>Framework</th><th class="text-right">Controls</th><th class="text-right d-none d-sm-table-cell">Clients</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($frameworks as $f): ?>
        <tr>
          <td class="text-break"><a href="/frameworks/<?= (int) $f['id'] ?>" class="font-weight-bold"><?= e($f['name']) ?></a>
            <?= $f['is_builtin'] ? '<span class="badge badge-light border ml-1">built-in</span>' : '' ?>
            <div class="small text-muted text-truncate-2"><?= e($f['description'] ?? '') ?></div></td>
          <td class="text-right"><?= (int) $f['controls'] ?></td>
          <td class="text-right d-none d-sm-table-cell"><?= (int) $f['clients'] ?></td>
          <td><?= $f['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modal-framework" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="/frameworks">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark"><h5 class="modal-title">New framework</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
        <div class="modal-body">
          <div class="form-group"><label>Name</label><input name="name" class="form-control" required placeholder="e.g. PCI DSS SAQ-A, Client internal policy"></div>
          <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
          <div class="form-group mb-0"><label>Start from</label>
            <select name="copy_from" class="form-control"><option value="0">Blank</option>
              <?php foreach ($frameworks as $f): ?><option value="<?= (int) $f['id'] ?>">Copy of <?= e($f['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button><button class="btn btn-primary">Create</button></div>
      </form>
    </div>
  </div>
</div>

<?php use Align\Docs\Documents; ?>
<div class="small"><a href="/documents">Documents</a> /</div>
<div class="row">
  <div class="col-lg-8">
    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-shapes mr-2"></i>Document templates</h3>
        <div class="card-tools"><button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-new-tpl"><i class="fas fa-plus mr-1"></i>New template</button></div>
      </div>
      <div class="card-body p-0">
        <table class="table table-striped table-borderless table-hover mb-0">
          <thead class="text-dark"><tr><th>Template</th><th>Category</th><th class="text-right">Used</th><th>Updated</th></tr></thead>
          <tbody>
          <?php foreach ($templates as $t): [$cl, $ci, $cc] = Documents::category($t['category']); ?>
            <tr>
              <td><i class="fas fa-fw <?= $ci ?> text-<?= $cc ?> mr-1"></i><a href="/documents/templates/<?= (int) $t['id'] ?>" class="font-weight-bold"><?= e($t['name']) ?></a>
                <?= $t['is_builtin'] ? '<span class="badge badge-light border ml-1">built-in</span>' : '' ?>
                <div class="small text-muted ml-4"><?= e($t['description'] ?? '') ?></div></td>
              <td class="small"><?= e($cl) ?></td>
              <td class="text-right"><?= (int) $t['used'] ?></td>
              <td class="small"><?= e(rel_time($t['updated_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-code mr-2"></i>Placeholders</h3></div>
      <div class="card-body small">
        <p>Filled in automatically when a document is created from a template:</p>
        <?php foreach ($placeholders as $p): ?><code class="d-inline-block mb-1 mr-1">{{<?= e($p) ?>}}</code><?php endforeach; ?>
        <p class="mt-2 mb-0 text-muted">Text in [brackets] is left for the person writing the document to fill in.</p>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="modal-new-tpl" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="/documents/templates">
      <?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title">New template</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <div class="form-group"><label>Name</label><input name="name" class="form-control" required placeholder="e.g. Remote Access Policy"></div>
        <div class="form-group"><label>Category</label><select name="category" class="form-control"><?php foreach (Documents::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-group mb-0"><label>Description</label><input name="description" class="form-control"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button><button class="btn btn-primary">Create &amp; edit</button></div>
    </form>
  </div></div>
</div>

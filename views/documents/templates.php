<?php use Align\Docs\Documents; ?>
<div class="small"><a href="/documents">Documents</a> /</div>
<div class="row">
  <div class="col-lg-8">
    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-shapes me-2"></i>Document templates</h3>
        <div class="card-tools"><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-tpl"><i class="fas fa-plus me-1"></i>New template</button></div>
      </div>
      <div class="card-body p-0">
        <table class="table table-striped table-borderless table-hover mb-0">
          <thead class="text-dark"><tr><th>Template</th><th>Category</th><th class="text-end d-none d-sm-table-cell">Used</th><th class="d-none d-md-table-cell">Updated</th></tr></thead>
          <tbody>
          <?php foreach ($templates as $t): [$cl, $ci, $cc] = Documents::category($t['category']); ?>
            <tr>
              <td class="text-break"><i class="fas fa-fw <?= $ci ?> text-<?= $cc ?> me-1"></i><a href="/documents/templates/<?= (int) $t['id'] ?>" class="fw-bold"><?= e($t['name']) ?></a>
                <?= $t['is_builtin'] ? '<span class="badge text-bg-light border ms-1">built-in</span>' : '' ?>
                <div class="small text-muted ms-4"><?= e($t['description'] ?? '') ?></div></td>
              <td class="small"><?= e($cl) ?></td>
              <td class="text-end d-none d-sm-table-cell"><?= (int) $t['used'] ?></td>
              <td class="small d-none d-md-table-cell"><?= e(rel_time($t['updated_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-code me-2"></i>Placeholders</h3></div>
      <div class="card-body small">
        <p>Filled in automatically when a document is created from a template:</p>
        <?php foreach ($placeholders as $p): ?><code class="d-inline-block mb-1 me-1">{{<?= e($p) ?>}}</code><?php endforeach; ?>
        <p class="mt-2 mb-0 text-muted">Text in [brackets] is left for the person writing the document to fill in.</p>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="modal-new-tpl" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="/documents/templates">
      <?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title">New template</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label>Name</label><input name="name" class="form-control" required placeholder="e.g. Remote Access Policy"></div>
        <div class="mb-3"><label>Category</label><select name="category" class="form-select"><?php foreach (Documents::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3 mb-0"><label>Description</label><input name="description" class="form-control"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create &amp; edit</button></div>
    </form>
  </div></div>
</div>

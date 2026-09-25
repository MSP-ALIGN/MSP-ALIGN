<?php
use Align\Auth;
use Align\Docs\Documents;
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-lines mr-2"></i>Documents</h3>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="doc-table" placeholder="Filter…">
      <?php if (Auth::can('admin')): ?><a class="btn btn-sm btn-outline-light mr-2" href="/documents/templates"><i class="fas fa-shapes mr-1"></i>Templates</a><?php endif; ?>
      <?php if (Auth::can('tech')): ?><button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-new-doc" data-autoopen="add"><i class="fas fa-plus mr-1"></i>New document</button><?php endif; ?>
    </div>
  </div>
  <div class="card-body py-2 border-bottom">
    <form class="form-inline" method="get">
      <select name="scope" class="custom-select custom-select-sm mr-2" data-autosubmit>
        <option value="">All clients + internal</option>
        <option value="internal" <?= $scope === 'internal' ? 'selected' : '' ?>>Internal only</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $scope === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="category" class="custom-select custom-select-sm mr-2" data-autosubmit>
        <option value="">All categories</option>
        <?php foreach (Documents::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $category === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <select name="status" class="custom-select custom-select-sm" data-autosubmit>
        <option value="">Hide archived</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Include archived</option>
      </select>
    </form>
  </div>
  <div class="card-body p-0 table-responsive">
    <?php $showClient = true; require __DIR__ . '/_table.php'; ?>
  </div>
</div>
<?php if (Auth::can('tech')) echo \Align\View::fetch('documents/_new_modal', ['templates' => $templates, 'clients' => $clients]); ?>

<?php
use Align\Auth;
use Align\Docs\Documents;
?>
<?= \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-file-lines', 'title' => 'Documents',
    'desc' => 'Policies, plans and procedures for each client and for your own company, with version history. Share a document to show it in the client portal.',
    'primary' => Auth::can('tech') ? '<button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-new-doc" data-autoopen="add"><i class="fas fa-plus mr-1"></i>New document</button>' : '',
    'secondary' => Auth::can('admin') ? ['<a class="btn btn-sm btn-default" href="/documents/templates"><i class="fas fa-shapes mr-1"></i>Templates</a>'] : [],
]) ?>
<div class="card">
  <div class="card-header list-toolbar">
    <form class="form-inline" method="get">
      <input type="search" class="form-control form-control-sm list-search mr-2 my-1" data-filter-table="doc-table" placeholder="Filter documents…" aria-label="Filter documents">
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

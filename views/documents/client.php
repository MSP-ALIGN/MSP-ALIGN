<?php
use Align\Auth;

require __DIR__ . '/../partials/client_header.php';
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-lines me-2"></i>Documents <span class="badge text-bg-light ms-1"><?= count($docs) ?></span></h3>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm me-2 filter-input" data-filter-table="doc-table" placeholder="Filter…">
      <a class="btn btn-sm btn-outline-light me-2" href="?status=<?= $status === 'all' ? '' : 'all' ?>"><?= $status === 'all' ? 'Hide archived' : 'Show archived' ?></a>
      <?php if (Auth::can('tech')): ?><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-doc" data-autoopen="add"><i class="fas fa-plus me-1"></i>New document</button><?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0 table-responsive">
    <?php $showClient = false; require __DIR__ . '/_table.php'; ?>
  </div>
</div>
<?php if (Auth::can('tech')) echo \Align\View::fetch('documents/_new_modal', ['templates' => $templates, 'presetClient' => (int) $client['id']]); ?>

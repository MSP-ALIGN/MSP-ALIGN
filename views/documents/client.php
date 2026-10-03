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
<?php if (Auth::can('tech')): ?>
<?= \Align\View::fetch('documents/_new_modal', ['templates' => $templates, 'presetClient' => (int) $client['id']]) ?>
<div class="card card-dark" id="contracts">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-signature me-2"></i>Contracts <span class="badge text-bg-light ms-1"><?= count($contracts) ?></span></h3>
    <div class="card-tools d-flex">
      <button class="btn btn-sm btn-default me-2" data-bs-toggle="modal" data-bs-target="#modal-upload-contract"><i class="fas fa-upload me-1"></i>Upload signed contract</button>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-contract"><i class="fas fa-plus me-1"></i>New contract</button>
    </div>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-striped table-borderless table-hover mb-0">
      <thead class="text-dark"><tr><th>Contract</th><th>Status</th><th>Signed</th><th>Ends</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($contracts as $k): [$kl, $kt] = \Align\Contracts\Contracts::status($k); ?>
        <tr><td><a href="/contracts/<?= (int) $k['id'] ?>" class="fw-bold"><?= e($k['title']) ?></a><div class="small text-muted"><?= e($k['number']) ?><?= $k['source'] === 'uploaded' ? ' · uploaded' : '' ?></div></td>
          <td><span class="badge text-bg-<?= $kt ?>"><?= e($kl) ?></span></td>
          <td class="small"><?= $k['signed_on'] ? e(fmt_date($k['signed_on'])) : '—' ?></td>
          <td class="small"><?= $k['ends_on'] ? e(fmt_date($k['ends_on'])) : '—' ?></td>
          <td class="text-end"><?php if (str_starts_with((string) $k['pdf_file'], 'contract-')): ?><a class="btn btn-xs btn-default" href="/contracts/<?= (int) $k['id'] ?>/pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf me-1"></i>PDF</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$contracts): ?><tr><td colspan="5" class="text-muted p-3">No contracts yet. Make one from a template, or upload one signed elsewhere.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?= \Align\View::fetch('contracts/_new_modal', ['templates' => $contractTemplates, 'clients' => [], 'presetClient' => (int) $client['id']]) ?>
<?= \Align\View::fetch('contracts/_upload_modal', ['clients' => [], 'presetClient' => (int) $client['id'], 'back' => '/clients/' . (int) $client['id'] . '/documents#contracts']) ?>
<?php endif; ?>

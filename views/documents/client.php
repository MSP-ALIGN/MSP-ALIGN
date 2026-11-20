<?php
use Align\Auth;

require __DIR__ . '/../partials/client_header.php';
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h1 class="card-title mt-2"><i class="fas fa-fw fa-file-lines me-2"></i>Documents <span class="badge text-bg-light ms-1"><?= count($docs) ?></span></h1>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm me-2 filter-input" data-filter-table="doc-table" placeholder="Filter…">
      <a class="btn btn-sm btn-default me-2" href="?status=<?= $status === 'all' ? '' : 'all' ?>"><?= $status === 'all' ? 'Hide archived' : 'Show archived' ?></a>
      <?php if (Auth::can('tech')): ?><button class="btn btn-sm btn-default me-2" data-bs-toggle="modal" data-bs-target="#modal-upload-doc"><i class="fas fa-upload me-1"></i>Upload a file</button><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-doc" data-autoopen="add"><i class="fas fa-plus me-1"></i>New document</button><?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0 table-responsive">
    <?php $showClient = false; require __DIR__ . '/_table.php'; ?>
  </div>
</div>
<?php /* 2.39.0 Client details for documents (Docs\Facts): entered once, they fill the matching [name, title] blanks */
$canFacts = Auth::can('tech'); ?>
<div class="card card-dark" id="doc-facts">
  <div class="card-header py-2">
    <h2 class="card-title mt-2 h3"><i class="fas fa-fw fa-id-badge me-2"></i>Client details for documents <span class="badge text-bg-light ms-1"><?= count($facts) ?> of <?= count(\Align\Docs\Facts::FIELDS) ?></span></h2>
  </div>
  <div class="card-body">
    <?php if ($factsPending): /* a fact changed and Draft documents still name the old value */ ?>
      <div class="alert alert-info" data-facts-pending>
        <p class="mb-2"><b><?= count($factsPending['drafts']) ?> draft <?= count($factsPending['drafts']) === 1 ? 'document still names' : 'documents still name' ?> the old details</b>
          (<?= e(implode(', ', array_map(fn($c, $k) => \Align\Docs\Facts::FIELDS[$k][0] . ': ' . $c[0] . ' → ' . $c[1], $factsPending['changes'], array_keys($factsPending['changes'])))) ?>):
          <?= e(implode(', ', array_map(fn($d) => $d['title'], $factsPending['drafts']))) ?>.</p>
        <p class="small mb-2">Active and archived documents are never changed: they may have been approved or signed as they are. Make a new version of those when you're ready.</p>
        <form method="post" action="/clients/<?= (int) $client['id'] ?>/document-facts/drafts" class="d-inline"><?= csrf_field() ?><input type="hidden" name="update" value="1"><button class="btn btn-sm btn-primary">Update the drafts</button></form>
        <form method="post" action="/clients/<?= (int) $client['id'] ?>/document-facts/drafts" class="d-inline ms-1"><?= csrf_field() ?><button class="btn btn-sm btn-light">Leave them</button></form>
      </div>
    <?php endif; ?>
    <p class="small text-muted">Who fills these roles at <?= e($client['name']) ?>. New documents made from a template fill the matching <code>[name, title]</code> blanks with them, and <b>Fill from client details</b> in a document does the same for one already made. Approval and signature lines are left for the signers.</p>
    <form method="post" action="/clients/<?= (int) $client['id'] ?>/document-facts">
      <?= csrf_field() ?>
      <div class="row g-3">
        <?php foreach (\Align\Docs\Facts::FIELDS as $k => [$label, $help]): ?>
          <div class="col-md-6 col-xl-4">
            <label class="form-label mb-1" for="fact-<?= e($k) ?>"><?= e($label) ?></label>
            <input class="form-control form-control-sm" id="fact-<?= e($k) ?>" name="facts[<?= e($k) ?>]" value="<?= e($facts[$k] ?? '') ?>" maxlength="<?= \Align\Docs\Facts::MAX ?>"
              placeholder="Name, title" <?= $canFacts ? '' : 'readonly' ?>>
            <div class="form-text"><?= e($help) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($canFacts): ?><button class="btn btn-sm btn-primary mt-3"><i class="fas fa-check me-1"></i>Save details</button><?php endif; ?>
    </form>
  </div>
</div>
<?php if (Auth::can('tech')): ?>
<?= \Align\View::fetch('documents/_new_modal', ['templates' => $templates, 'presetClient' => (int) $client['id']]) ?>
<?= \Align\View::fetch('documents/_upload_modal', ['presetClient' => (int) $client['id']]) // 2.20.0 ?>
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
      <?php if (!$contracts): ?><tr><td colspan="5" class="empty-state">No contracts yet. Make one from a template, or upload one signed elsewhere.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?= \Align\View::fetch('contracts/_new_modal', ['templates' => $contractTemplates, 'clients' => [], 'presetClient' => (int) $client['id']]) ?>
<?= \Align\View::fetch('contracts/_upload_modal', ['clients' => [], 'presetClient' => (int) $client['id'], 'back' => '/clients/' . (int) $client['id'] . '/documents#contracts']) ?>
<?php endif; ?>

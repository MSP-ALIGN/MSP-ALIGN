<?php
/** Onboarding → Contract templates. @var array $templates; string $companyAddress; int $remindDays, $maxReminders */
?>
<?= \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-shapes', 'title' => 'Contract templates',
    'desc' => 'Your own contracts. Upload each one as a PDF and put boxes on it for what you fill in, what the client fills in, and the signatures; Align keeps your layout exactly. Each contract keeps a copy of the template it was made from.',
    'primary' => '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-template" data-autoopen="add"><i class="fas fa-plus me-1"></i>New template</button>',
    'help' => 'guide-contracts',
]) ?>
<div class="row">
  <div class="col-xl-8">
    <div class="card">
      <ul class="list-group list-group-flush">
        <?php foreach ($templates as $t): ?>
          <li class="list-group-item d-flex align-items-center">
            <div class="me-auto"><a href="/contracts/templates/<?= (int) $t['id'] ?>" class="fw-bold"><?= e($t['name']) ?></a>
              <?= $t['is_active'] ? '' : '<span class="badge text-bg-secondary ms-1">hidden</span>' ?>
              <div class="small text-muted"><?= $t['pdf_name'] !== null ? '<i class="fas fa-file-pdf text-danger me-1"></i>' . e($t['pdf_name']) . ' · ' . $t['pdf_pages'] . ' page' . ($t['pdf_pages'] === 1 ? '' : 's') . ' · ' . $t['boxes'] . ' box' . ($t['boxes'] === 1 ? '' : 'es') . ' · ' : '' ?><?= $t['description'] ? e($t['description']) . ' · ' : '' ?>Version <?= (int) $t['version'] ?> · <?= (int) $t['uses'] ?> contract<?= (int) $t['uses'] === 1 ? '' : 's' ?> · updated <?= e(rel_time($t['updated_at'])) ?><?= $t['updated_by_name'] ? ' by ' . e($t['updated_by_name']) : '' ?></div></div>
            <a class="btn btn-sm btn-default me-1" href="/contracts/templates/<?= (int) $t['id'] ?>/pdf" target="_blank" rel="noopener" title="Sample PDF"><i class="fas fa-file-pdf"></i><span class="visually-hidden">Sample PDF</span></a>
            <a class="btn btn-sm btn-default" href="/contracts/templates/<?= (int) $t['id'] ?>">Edit</a>
          </li>
        <?php endforeach; ?>
        <?php if (!$templates): ?>
          <li class="list-group-item text-center py-5">
            <i class="fas fa-shapes fa-2x text-secondary mb-2"></i>
            <div class="fw-bold">No templates yet</div>
            <div class="text-muted small mb-3">Upload your contract as a PDF (from Word: File → Save as → PDF). You'll put the boxes on it next.</div>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-new-template"><i class="fas fa-upload me-1"></i>Upload your contract</button>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="col-xl-4">
    <form method="post" action="/contracts/templates/settings" class="card" data-unsaved>
      <?= csrf_field() ?>
      <div class="card-header py-2"><h3 class="card-title mt-1">Settings</h3></div>
      <div class="card-body">
        <div class="mb-3"><label for="cs-addr">Your company address</label>
          <textarea id="cs-addr" name="company_address" class="form-control" rows="2" maxlength="500"><?= e($companyAddress) ?></textarea>
          <div class="form-text">For the <code>{{company_address}}</code> field. Your name, phone and email come from Settings.</div></div>
        <div class="row g-2">
          <div class="col-6"><label for="cs-rem">Remind every</label><div class="input-group input-group-sm"><input type="number" min="0" max="30" id="cs-rem" name="contract_remind_days" class="form-control" value="<?= (int) $remindDays ?>"><span class="input-group-text">days</span></div></div>
          <div class="col-6"><label for="cs-max">Up to</label><div class="input-group input-group-sm"><input type="number" min="0" max="10" id="cs-max" name="contract_max_reminders" class="form-control" value="<?= (int) $maxReminders ?>"><span class="input-group-text">reminders</span></div></div>
        </div>
        <div class="form-text">Automatic reminder emails while a contract waits for the client's signature. 0 turns them off.</div>
      </div>
      <div class="card-footer text-end"><button class="btn btn-primary btn-sm">Save</button></div>
    </form>
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1">Import</h3></div>
      <div class="card-body small">
        <p>Load a template exported from another MSP Align (Export is on each template's page). It's added as a new template.</p>
        <form method="post" action="/contracts/templates/import" enctype="multipart/form-data"><?= csrf_field() ?>
          <div class="input-group input-group-sm"><input type="file" class="form-control" name="file" accept=".json,application/json" required aria-label="Template file"><button class="btn btn-primary">Import</button></div></form>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-new-template" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="/contracts/templates" enctype="multipart/form-data"><?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-fw fa-shapes me-2"></i>New contract template</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label for="nt-name">Name</label><input id="nt-name" name="name" class="form-control" maxlength="190" placeholder="e.g. MSP Service Agreement (the file name if empty)"></div>
        <div class="form-check"><input class="form-check-input" type="radio" name="start" value="pdf" id="nt-pdf" checked><label class="form-check-label" for="nt-pdf"><b>Upload your contract (PDF)</b> <span class="text-muted small">Your layout, logo and wording stay exactly as they are</span></label></div>
        <div class="ms-4 mt-2 mb-3" data-show-when="start=pdf"><input type="file" name="file" class="form-control form-control-sm" accept="application/pdf,.pdf" aria-label="Contract PDF">
          <div class="form-text">Up to 25 MB. Upload the blank version, not a signed copy. From Word: File → Save as → PDF.</div></div>
        <div class="form-check"><input class="form-check-input" type="radio" name="start" value="blank" id="nt-blank"><label class="form-check-label" for="nt-blank"><b>Write it in Align</b> <span class="text-muted small">An empty page to paste your wording into</span></label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create</button></div>
    </form>
  </div></div>
</div>

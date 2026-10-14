<?php
/**
 * 2.20.0 Upload a file as a document (techs): the file, a title (defaults to the file's name), category, and
 * whether a client's portal shows it. Posts with CSRF to DocumentController::upload.
 * @var ?array $clients (choose a client, or internal) ; ?int $presetClient (one client's page)
 */
use Align\Docs\Documents;
use Align\Docs\Files;

$clients = $clients ?? null;
$presetClient = $presetClient ?? null;
?>
<div class="modal fade" id="modal-upload-doc" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/documents/upload" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-upload me-2"></i>Upload a file</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($clients !== null): ?>
            <div class="mb-3"><label for="up-client">Client</label>
              <select id="up-client" name="client_id" class="form-select">
                <option value="">— Internal (our own documents) —</option>
                <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
              </select></div>
          <?php else: ?>
            <input type="hidden" name="client_id" value="<?= (int) $presetClient ?>">
          <?php endif; ?>
          <div class="mb-3"><label for="up-file">File</label><input id="up-file" type="file" name="file" class="form-control" accept="<?= e(Files::ACCEPT) ?>" required>
            <div class="form-text">PDF, Word (.docx, .doc), Excel (.xlsx, .xls), CSV, PNG or JPEG, up to 25 MB. PDFs and pictures show in the page; the others download. A Word file can be imported into an editable document afterwards.</div></div>
          <div class="row g-2">
            <div class="mb-3 col-md-8"><label for="up-title">Title <small class="text-muted">(defaults to the file's name)</small></label><input id="up-title" name="title" class="form-control" maxlength="255"></div>
            <div class="mb-3 col-md-4"><label for="up-cat">Category</label>
              <select id="up-cat" name="category" class="form-select">
                <?php foreach (Documents::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $k === 'other' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <?php if ($clients !== null || $presetClient): ?>
            <div class="form-check"><input type="checkbox" class="form-check-input" id="up-portal" name="portal" value="1"><label class="form-check-label fw-normal" for="up-portal">Show it in the client's portal</label></div>
          <?php endif; ?>
          <p class="small text-muted mt-2 mb-0">It's saved as Active, with a review date a year from now. Change those on its page.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-upload me-1"></i>Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>

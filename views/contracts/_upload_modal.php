<?php
/** Upload a contract signed elsewhere. @var array $clients; ?int $presetClient; ?string $back */
$presetClient = $presetClient ?? null;
$back = $back ?? '/contracts?show=signed';
?>
<div class="modal fade" id="modal-upload-contract" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="/contracts/upload" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-upload me-2"></i>Upload a signed contract</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">For contracts signed somewhere else, like DocuSeal or on paper. The PDF is kept with the client, with the dates you enter.</p>
          <div class="mb-3"><label for="uc-file">Signed PDF</label>
            <input type="file" id="uc-file" name="file" class="form-control" accept="application/pdf,.pdf" required>
            <div class="form-text">Up to 25 MB.</div></div>
          <?php if ($presetClient): ?>
            <input type="hidden" name="client_id" value="<?= (int) $presetClient ?>">
          <?php else: ?>
            <div class="mb-3"><label for="uc-client">Client</label>
              <select id="uc-client" name="client_id" class="form-select"><option value="">Not in Align (type the name below)</option>
                <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
              </select></div>
            <div class="mb-3"><label for="uc-lead">Or company name <span class="text-muted small">(if they're not a client in Align)</span></label><input id="uc-lead" name="lead_company" class="form-control" maxlength="190"></div>
          <?php endif; ?>
          <div class="mb-3"><label for="uc-title">Title</label><input id="uc-title" name="title" class="form-control" maxlength="190" placeholder="e.g. Managed IT Services Agreement (from the file name if empty)"></div>
          <div class="row g-2">
            <div class="col-sm-4 mb-3"><label for="uc-signed">Signed on</label><input type="date" id="uc-signed" name="signed_on" class="form-control" required></div>
            <div class="col-sm-4 mb-3"><label for="uc-start">Starts</label><input type="date" id="uc-start" name="starts_on" class="form-control"></div>
            <div class="col-sm-4 mb-3"><label for="uc-end">Ends <span class="text-muted small">(optional)</span></label><input type="date" id="uc-end" name="ends_on" class="form-control"></div>
          </div>
          <div><label for="uc-notes">Notes <span class="text-muted small">(optional)</span></label><textarea id="uc-notes" name="notes" class="form-control" rows="2" maxlength="4000"></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>

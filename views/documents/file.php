<?php
/**
 * 2.20.0 An uploaded document (PDF, Word, Excel, CSV, image): the file in the page (_file_body), its details
 * (title, category, status, review date), client portal sharing, compliance evidence and history (each version
 * keeps its file). Techs replace the file and import a Word file into an editable document; admins delete.
 * @var array $doc, $versions, $evidence; ?array $client; bool $canEdit
 * Every value is escaped; forms post with CSRF to DocumentController.
 */
use Align\Compliance\Compliance;
use Align\Docs\Documents;
use Align\Docs\Files;

if ($client) {
    require __DIR__ . '/../partials/client_header.php';
}
$id = (int) $doc['id'];
$kindLabel = ['auto' => 'Autosave', 'manual' => 'Saved version', 'restore' => 'Restored', 'created' => 'Created', 'upload' => 'File uploaded'];
$sel = fn($a, $b) => $a === $b ? 'selected' : '';
?>
<div class="page-head d-flex flex-wrap align-items-start mb-3">
  <div class="me-auto pe-3">
    <div class="small"><?php if ($client): ?><a href="/clients/<?= (int) $client['id'] ?>/documents">Documents</a><?php else: ?><a href="/documents">Documents</a> · <span class="text-muted">Internal</span><?php endif; ?> /</div>
    <h1 class="h4 mb-0"><?= e($doc['title']) ?> <span class="badge text-bg-<?= Documents::STATUSES[$doc['status']][1] ?> align-middle"><?= e(Documents::STATUSES[$doc['status']][0]) ?></span></h1>
  </div>
  <div class="page-actions d-flex flex-wrap gap-1 mt-1">
    <?php if ($canEdit): ?>
      <?php if ($doc['file_type'] === 'docx'): ?>
        <form method="post" action="/documents/<?= $id ?>/import"><?= csrf_field() ?><button class="btn btn-sm btn-default" data-confirm="Make a new editable document from this Word file's text? Text, headings, lists and links come across; tables become one line per row, and pictures, headers and footers are left out. The Word file stays as it is." data-confirm-danger="0" data-confirm-ok="Import"><i class="fas fa-file-import me-1"></i>Import into an editable document</button></form>
      <?php endif; ?>
      <button class="btn btn-sm btn-default" data-bs-toggle="modal" data-bs-target="#modal-replace-file"><i class="fas fa-upload me-1"></i>Replace file</button>
      <?php if (\Align\Auth::can('admin')): ?><button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modal-delete-doc" aria-label="Delete"><i class="fas fa-trash"></i></button><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col-xl-9">
    <div class="card"><div class="card-body">
      <?= \Align\View::fetch('documents/_file_body', ['src' => "/documents/$id/file", 'f' => $doc]) ?>
    </div></div>
  </div>

  <div class="col-xl-3">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-info me-2"></i>Details</h3></div>
      <div class="card-body small">
        <?php if ($canEdit): ?>
          <form method="post" action="/documents/<?= $id ?>/details" id="doc-details"><?= csrf_field() ?>
            <div class="mb-2"><label for="df-title">Title</label><input id="df-title" name="title" class="form-control form-control-sm" maxlength="255" value="<?= e($doc['title']) ?>" required></div>
            <div class="mb-2"><label for="df-cat">Category</label><select id="df-cat" name="category" class="form-select form-select-sm">
              <?php foreach (Documents::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($doc['category'], $k) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="mb-2"><label for="df-status">Status</label><select id="df-status" name="status" class="form-select form-select-sm">
              <?php foreach (Documents::STATUSES as $k => [$label]): ?><option value="<?= $k ?>" <?= $sel($doc['status'], $k) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="mb-2"><label for="df-review">Review by</label><input id="df-review" type="date" name="review_due" class="form-control form-control-sm" value="<?= e((string) $doc['review_due']) ?>"></div>
            <button class="btn btn-sm btn-primary"><i class="fas fa-check me-1"></i>Save</button>
          </form>
          <hr>
        <?php else: ?>
          <div class="mb-1"><span class="text-muted">Category</span> <?= e(Documents::category($doc['category'])[0]) ?></div>
          <div class="mb-1"><span class="text-muted">Review by</span> <?= $doc['review_due'] ? e(fmt_date($doc['review_due'])) : '—' ?></div>
        <?php endif; ?>
        <div class="mb-1"><span class="text-muted">Version</span> <b><?= (int) $doc['version'] ?></b> · <?= e(rel_time($doc['updated_at'])) ?><?= $doc['updated_by_name'] ? ' by ' . e($doc['updated_by_name']) : '' ?></div>
        <div class="mb-1"><span class="text-muted">Uploaded</span> <?= e(fmt_date($doc['created_at'])) ?><?= $doc['created_by_name'] ? ' by ' . e($doc['created_by_name']) : '' ?></div>
        <div class="mb-1 text-break"><span class="text-muted">SHA-256</span> <code class="small"><?= e(substr((string) $doc['file_hash'], 0, 16)) ?>…</code></div>
        <?php if ($doc['client_id']): ?>
          <div class="mt-2 pt-2 border-top d-flex align-items-center">
            <span class="me-auto"><i class="fas fa-door-open me-1 text-muted"></i>Client portal:
              <b class="<?= $doc['portal_shared'] ? 'text-success' : 'text-muted' ?>"><?= $doc['portal_shared'] ? ($doc['status'] === 'active' ? 'Shared' : 'Shared once Active') : 'Not shared' ?></b></span>
            <?php if ($canEdit): ?><form method="post" action="/documents/<?= $id ?>/portal"><?= csrf_field() ?><input type="hidden" name="shared" value="<?= $doc['portal_shared'] ? '0' : '1' ?>">
              <button class="btn btn-xs btn-default"<?= $doc['portal_shared'] ? '' : ' data-confirm="' . e('Share this file with ' . $doc['client_name'] . '\'s client portal? Their portal users who can see documents can view and download it' . ($doc['status'] === 'active' ? ' now.' : ' once it\'s Active.')) . '" data-confirm-danger="0" data-confirm-ok="Share"' ?>><?= $doc['portal_shared'] ? 'Hide' : 'Share' ?></button></form><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-check me-2"></i>Compliance evidence</h3></div>
      <div class="card-body small">
        <?php foreach ($evidence as $ev): $st = Compliance::STATUSES[$ev['status']]; ?>
          <div class="mb-2"><i class="fas fa-fw <?= $st[2] ?> text-<?= $st[1] ?> me-1"></i><a href="/clients/<?= (int) $doc['client_id'] ?>/compliance/<?= (int) $ev['framework_id'] ?>"><?= e($ev['framework']) ?></a>
            <div class="text-muted ms-4"><?= e($ev['ref']) ?> — <?= e($ev['title']) ?></div></div>
        <?php endforeach; ?>
        <?php if (!$evidence): ?><p class="text-muted mb-0"><?= $doc['client_id'] ? 'Not linked yet. On a compliance checklist, pick this document as the evidence for an item.' : 'Internal documents can’t be linked to client compliance items.' ?></p><?php endif; ?>
      </div>
    </div>

    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left me-2"></i>History</h3></div>
      <ul class="list-group list-group-flush small doc-history">
        <?php foreach ($versions as $v): ?>
          <li class="list-group-item py-2">
            <a href="/documents/<?= $id ?>/versions/<?= (int) $v['id'] ?>" class="d-flex">
              <span class="me-auto"><b>v<?= (int) $v['version'] ?></b> · <?= e($kindLabel[$v['kind']] ?? $v['kind']) ?></span>
              <span class="text-muted"><?= e(rel_time($v['saved_at'])) ?></span>
            </a>
            <div class="text-muted text-break"><?= e($v['saved_by_name'] ?? '') ?><?= $v['note'] ? ' — ' . e($v['note']) : '' ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="card-footer small text-muted">Replacing the file keeps the earlier one here; open a version to download or restore it.</div>
    </div>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="modal-replace-file" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="/documents/<?= $id ?>/file" enctype="multipart/form-data"><?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-upload me-2"></i>Replace file</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label for="rf-file">New file</label><input id="rf-file" type="file" name="file" class="form-control" accept="<?= e(Files::ACCEPT) ?>" required>
          <div class="form-text">PDF, Word, Excel, CSV, PNG or JPEG, up to 25 MB. The current file stays in the history.</div></div>
        <div><label for="rf-note">Note <small class="text-muted">(optional)</small></label><input id="rf-note" name="note" class="form-control" maxlength="200" placeholder="e.g. Signed copy from the client"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="fas fa-upload me-1"></i>Replace</button></div>
    </form>
  </div></div>
</div>
<?php if (\Align\Auth::can('admin')): ?>
<div class="modal fade" id="modal-delete-doc" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="/documents/<?= $id ?>/delete"><?= csrf_field() ?>
      <div class="modal-header bg-danger"><h5 class="modal-title"><i class="fas fa-trash me-2"></i>Delete document</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p>This permanently deletes <b><?= e($doc['title']) ?></b>, its file and every earlier file in its history. To keep it but hide it, set the status to <b>Archived</b> instead.</p>
        <label for="dd-confirm">Type <b>DELETE</b> to confirm</label><input id="dd-confirm" name="confirm" class="form-control" autocomplete="off" required>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Delete permanently</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>
<?php endif; ?>

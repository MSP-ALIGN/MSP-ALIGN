<?php
use Align\Compliance\Compliance;
use Align\Docs\Documents;

if ($client) {
    require __DIR__ . '/../partials/client_header.php';
}
$id = (int) $doc['id'];
$kindLabel = ['auto' => 'Autosave', 'manual' => 'Saved version', 'restore' => 'Restored', 'created' => 'Created'];
?>
<div id="doc-app" data-id="<?= $id ?>" data-version="<?= (int) $doc['version'] ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>" data-csrf="<?= e(csrf_token()) ?>">
  <div class="d-flex flex-wrap align-items-center mb-2">
    <div class="small mr-auto">
      <?php if ($client): ?><a href="/clients/<?= (int) $client['id'] ?>/documents">Documents</a><?php else: ?><a href="/documents">Documents</a> · <span class="text-muted">Internal</span><?php endif; ?> /
    </div>
    <div class="doc-presence mr-3" id="doc-presence" aria-live="polite"></div>
    <span class="doc-save-state small mr-3" id="doc-state"><?= $canEdit ? 'All changes saved' : 'Read-only' ?></span>
    <div class="btn-group btn-group-sm">
      <?php if ($canEdit): ?><button type="button" class="btn btn-default" data-toggle="modal" data-target="#modal-checkpoint"><i class="fas fa-bookmark mr-1"></i>Save version</button><?php endif; ?>
      <a class="btn btn-default" href="/documents/<?= $id ?>/print" target="_blank"><i class="fas fa-print mr-1"></i>Print / PDF</a>
      <?php if ($canEdit): ?>
        <div class="btn-group">
          <button class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-label="More"><i class="fas fa-ellipsis-vertical"></i></button>
          <div class="dropdown-menu dropdown-menu-right">
            <?php if (\Align\Auth::can('admin')): ?>
              <form method="post" action="/documents/templates"><?= csrf_field() ?><input type="hidden" name="from_document" value="<?= $id ?>"><button class="dropdown-item"><i class="fas fa-fw fa-shapes mr-2"></i>Save as a new template</button></form>
            <?php endif; ?>
            <a class="dropdown-item text-danger" href="#" data-toggle="modal" data-target="#modal-delete-doc"><i class="fas fa-fw fa-trash mr-2"></i>Delete…</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="alert alert-warning d-none align-items-center" id="doc-conflict" role="alert">
    <i class="fas fa-code-merge mr-2"></i><span class="mr-auto" id="doc-conflict-text"></span>
    <button type="button" class="btn btn-sm btn-light ml-2" id="doc-load-theirs">Load their version</button>
    <button type="button" class="btn btn-sm btn-outline-dark ml-2" id="doc-keep-mine">Keep mine (overwrite)</button>
  </div>
  <div class="alert alert-danger d-none" id="doc-error" role="alert"></div>

  <div class="row">
    <div class="col-xl-9">
      <div class="card doc-card">
        <div class="card-body pb-0">
          <input class="form-control doc-title" id="doc-title" value="<?= e($doc['title']) ?>" maxlength="255" aria-label="Title" <?= $canEdit ? '' : 'readonly' ?>>
          <div class="form-row doc-meta mt-2">
            <div class="col-sm-4 mb-2">
              <select class="custom-select custom-select-sm" id="doc-category" aria-label="Category" <?= $canEdit ? '' : 'disabled' ?>>
                <?php foreach (Documents::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $doc['category'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4 mb-2">
              <select class="custom-select custom-select-sm" id="doc-status" aria-label="Status" <?= $canEdit ? '' : 'disabled' ?>>
                <?php foreach (Documents::STATUSES as $k => [$label]): ?><option value="<?= $k ?>" <?= $doc['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4 mb-2">
              <div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text">Review by</span></div>
                <input type="date" class="form-control" id="doc-review" value="<?= e($doc['review_due']) ?>" <?= $canEdit ? '' : 'readonly' ?>></div>
            </div>
          </div>
        </div>
        <div id="doc-editor" class="doc-editor"></div>
        <template id="doc-initial"><?= $doc['body_html'] ?></template>
      </div>
    </div>

    <div class="col-xl-3">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-info mr-2"></i>Details</h3></div>
        <div class="card-body small">
          <div class="mb-1"><span class="text-muted">Version</span> <b id="doc-version-label"><?= (int) $doc['version'] ?></b></div>
          <div class="mb-1"><span class="text-muted">Last saved</span> <span id="doc-updated"><?= e(rel_time($doc['updated_at'])) ?><?= $doc['updated_by_name'] ? ' by ' . e($doc['updated_by_name']) : '' ?></span></div>
          <div class="mb-1"><span class="text-muted">Created</span> <?= e(fmt_date($doc['created_at'])) ?><?= $doc['created_by_name'] ? ' by ' . e($doc['created_by_name']) : '' ?></div>
          <?php if ($doc['client_id']): ?><div><span class="text-muted">Client</span> <a href="/clients/<?= (int) $doc['client_id'] ?>"><?= e($doc['client_name']) ?></a></div>
            <div class="mt-2 pt-2 border-top d-flex align-items-center">
              <span class="mr-auto"><i class="fas fa-door-open mr-1 text-muted"></i>Client portal:
                <b class="<?= $doc['portal_shared'] ? 'text-success' : 'text-muted' ?>"><?= $doc['portal_shared'] ? ($doc['status'] === 'active' ? 'Shared' : 'Shared once Active') : 'Not shared' ?></b></span>
              <?php if ($canEdit): ?><form method="post" action="/documents/<?= $id ?>/portal"><?= csrf_field() ?><input type="hidden" name="shared" value="<?= $doc['portal_shared'] ? '0' : '1' ?>">
                <button class="btn btn-xs btn-default"><?= $doc['portal_shared'] ? 'Hide' : 'Share' ?></button></form><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-check mr-2"></i>Compliance evidence</h3></div>
        <div class="card-body small">
          <?php foreach ($evidence as $ev): $st = Compliance::STATUSES[$ev['status']]; ?>
            <div class="mb-2"><i class="fas fa-fw <?= $st[2] ?> text-<?= $st[1] ?> mr-1"></i><a href="/clients/<?= (int) $doc['client_id'] ?>/compliance/<?= (int) $ev['framework_id'] ?>"><?= e($ev['framework']) ?></a>
              <div class="text-muted ml-4"><?= e($ev['ref']) ?> — <?= e($ev['title']) ?></div></div>
          <?php endforeach; ?>
          <?php if (!$evidence): ?><p class="text-muted mb-0"><?= $doc['client_id'] ? 'Not linked yet. On a compliance checklist, pick this document as the evidence for an item (e.g. the WISP for "written program on file").' : 'Internal documents can’t be linked to client compliance items.' ?></p><?php endif; ?>
        </div>
      </div>

      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left mr-2"></i>History</h3></div>
        <ul class="list-group list-group-flush small doc-history" id="doc-history">
          <?php foreach ($versions as $v): ?>
            <li class="list-group-item py-2">
              <a href="/documents/<?= $id ?>/versions/<?= (int) $v['id'] ?>" class="d-flex">
                <span class="mr-auto"><b>v<?= (int) $v['version'] ?></b> · <?= e($kindLabel[$v['kind']] ?? $v['kind']) ?></span>
                <span class="text-muted"><?= e(rel_time($v['saved_at'])) ?></span>
              </a>
              <div class="text-muted"><?= e($v['saved_by_name'] ?? '') ?><?= $v['note'] && $v['kind'] !== 'auto' ? ' — ' . e($v['note']) : '' ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="card-footer small text-muted">Autosaves keep a snapshot every 10 minutes per editor. Use <b>Save version</b> to mark a milestone.</div>
      </div>
    </div>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="modal-checkpoint" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-bookmark mr-2"></i>Save a version</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <label>Note <small class="text-muted">(optional)</small></label>
      <input class="form-control" id="checkpoint-note" maxlength="255" placeholder="e.g. Approved by client 2026-09-30">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="doc-checkpoint">Save version</button></div>
  </div></div>
</div>
<div class="modal fade" id="modal-delete-doc" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="/documents/<?= $id ?>/delete">
      <?= csrf_field() ?>
      <div class="modal-header bg-danger"><h5 class="modal-title"><i class="fas fa-trash mr-2"></i>Delete document</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <p>This permanently deletes <b><?= e($doc['title']) ?></b> and its history. To keep it but hide it, set the status to <b>Archived</b> instead.</p>
        <label>Type <b>DELETE</b> to confirm</label><input name="confirm" class="form-control" autocomplete="off" required>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button><button class="btn btn-danger">Delete permanently</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

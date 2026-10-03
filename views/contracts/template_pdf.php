<?php
use Align\Contracts\Template;

/**
 * The builder for a template printed on the MSP's own PDF: the pages with boxes on them (pdfview.js) and, on the
 * right, the selected box (and, for a blank, what it asks for), the services and the signing settings. A blank exists
 * only as boxes: there's no separate list of fields here. @var array $t, $unknown; int $uses
 */
$id = (int) $t['id'];
$def = $t['def'];
$sg = $def['signing'];
$json = fn($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$meta = ['builtIn' => array_map(fn($b) => ['label' => $b[0], 'help' => $b[1]], Template::BUILT_IN), 'types' => Template::FIELD_TYPES, 'auto' => Template::AUTO,
    'periods' => Template::PERIODS, 'currency' => \Align\Fmt::symbol(),
    'special' => array_map(fn($s) => ['label' => $s[0], 'side' => $s[1]], Template::PLACE_SPECIAL), 'sizes' => Template::PLACE_SIZE,
    'company' => (string) (\Align\Settings::get('company_name') ?: '')];
?>
<div class="small"><a href="/contracts/templates">Contract templates</a> /</div>
<form method="post" action="/contracts/templates/<?= $id ?>" id="tpl-form" data-unsaved data-ct-builder data-pdf-builder>
  <?= csrf_field() ?>
  <input type="hidden" name="def" id="tpl-def" value="">
  <script type="application/json" id="ct-def-json"><?= $json($def) ?></script>
  <script type="application/json" id="ct-meta-json"><?= $json($meta) ?></script>
  <div class="d-flex flex-wrap align-items-center mb-3 gap-2">
    <input name="name" class="form-control form-control-lg fw-semibold ct-name me-auto" maxlength="190" value="<?= e($t['name']) ?>" aria-label="Template name" required>
    <span class="small text-muted" data-ct-state></span>
    <a class="btn btn-sm btn-default" href="/contracts/templates/<?= $id ?>/pdf" target="_blank" rel="noopener" title="Your PDF with sample values in the boxes, as last saved"><i class="fas fa-file-pdf me-1"></i>Sample PDF</a>
    <button class="btn btn-sm btn-primary"><i class="fas fa-floppy-disk me-1"></i>Save</button>
    <div class="btn-group">
      <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown" aria-label="More actions"><i class="fas fa-ellipsis"></i></button>
      <div class="dropdown-menu dropdown-menu-end">
        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#modal-new-pdf"><i class="fas fa-fw fa-file-arrow-up me-1"></i>Upload a new version of the PDF</button>
        <a class="dropdown-item" href="/contracts/templates/<?= $id ?>/source" download><i class="fas fa-fw fa-file-pdf me-1"></i>The PDF as uploaded</a>
        <button class="dropdown-item" form="tpl-dup"><i class="fas fa-fw fa-copy me-1"></i>Duplicate</button>
        <div class="dropdown-divider"></div>
        <button class="dropdown-item text-danger" form="tpl-del" data-confirm="<?= $uses ? 'Hide this template? Contracts made from it keep their own copy.' : 'Delete this template and its PDF?' ?>" data-confirm-ok="<?= $uses ? 'Hide' : 'Delete' ?>"><i class="fas fa-fw fa-trash me-1"></i><?= $uses ? 'Hide' : 'Delete' ?></button>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-xl-8">
      <div class="card ct-pdf-card">
        <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
          <h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-file-pdf text-danger me-1"></i><?= e($def['pdf']['name']) ?> <span class="text-muted small fw-normal"><?= count($def['pdf']['pages']) ?> pages</span></h3>
          <label for="pv-add" class="small mb-0">Add a box:</label>
          <select id="pv-add" class="form-select form-select-sm w-auto" data-pv-add aria-label="What the new box shows"></select>
          <button type="button" class="btn btn-sm btn-primary" data-pv-place><i class="fas fa-plus me-1"></i>Place it</button>
          <button type="button" class="btn btn-sm btn-default" data-pv-suggest title="Boxes for the blanks Align found: [ ], ____ and $ spots"><i class="fas fa-wand-magic-sparkles me-1"></i><span data-pv-suggest-label>Find the blanks</span></button>
          <button type="button" class="btn btn-sm btn-warning d-none" data-pv-accept-all title="A box for every blank Align could name (the others stay for you to choose)"><i class="fas fa-check-double me-1"></i><span>Add them</span></button>
        </div>
        <div class="card-body ct-pdf-body">
          <div class="pv-hint small text-muted mb-2" data-pv-hint>Choose what a box shows (or <b>New blank…</b> for something Align doesn't know, like an onsite rate), press <b>Place it</b>, then click on the page. Drag a box to move it, drag its corner to resize, and press Delete to remove it. Arrow keys nudge it.</div>
          <div class="pv" data-pv data-mode="build" data-src="/contracts/templates/<?= $id ?>/source" data-v="<?= e(APP_VERSION) ?>">
            <script type="application/json" class="pv-data"><?= $json(['items' => [], 'pages' => $def['pdf']['pages']]) ?></script>
            <div class="pv-pages"><div class="pv-loading text-muted small p-3"><i class="fas fa-spinner fa-spin me-1"></i>Loading the pages…</div></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-xl-4">
      <div class="card ct-side card-tabs">
        <div class="card-header p-0 border-bottom-0">
          <ul class="nav nav-tabs ct-side-tabs" role="tablist">
            <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#ct-tab-box" role="tab"><i class="fas fa-vector-square me-1"></i>Box</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#ct-tab-services" role="tab"><i class="fas fa-list-ol me-1"></i>Services</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#ct-tab-style" role="tab"><i class="fas fa-signature me-1"></i>Signing</button></li>
          </ul>
        </div>
        <div class="card-body tab-content">
          <div class="tab-pane fade show active" id="ct-tab-box" role="tabpanel">
            <div data-pv-inspector>
              <p class="small text-muted mb-2" data-pv-none>Click a box on the page to change it.</p>
              <div class="d-none" data-pv-edit>
                <div class="mb-2"><label for="pv-key" class="small">Shows</label><select id="pv-key" class="form-select form-select-sm" data-pv-key></select></div>
                <div class="ct-blank d-none" data-pv-blank>
                  <div class="mb-2"><label for="pv-b-label" class="small">Name of this blank</label><input id="pv-b-label" class="form-control form-control-sm" maxlength="120" data-b="label" placeholder="e.g. Start date, Onsite rate"></div>
                  <div class="row g-2 mb-2">
                    <div class="col-6"><label for="pv-b-type" class="small">Kind</label><select id="pv-b-type" class="form-select form-select-sm" data-b="type"><?php foreach (Template::FIELD_TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label for="pv-b-by" class="small" title="You, before sending it; or the client, when signing">Filled in by</label><select id="pv-b-by" class="form-select form-select-sm" data-b="by"><option value="provider">You</option><option value="client">The client</option></select></div>
                  </div>
                  <div class="mb-2 d-none" data-b-row="options"><label for="pv-b-options" class="small">Choices <span class="text-muted">(one per line)</span></label><textarea id="pv-b-options" class="form-control form-control-sm" rows="3" data-b="options"></textarea></div>
                  <div class="mb-2" data-b-row="default"><label for="pv-b-default" class="small">Usually <span class="text-muted">(filled in for you on each new contract)</span></label><input id="pv-b-default" class="form-control form-control-sm" maxlength="500" data-b="default"></div>
                  <div class="mb-2"><label for="pv-b-help" class="small">Hint <span class="text-muted">(optional)</span></label><input id="pv-b-help" class="form-control form-control-sm" maxlength="200" data-b="help"></div>
                  <div class="form-check small mb-2"><input class="form-check-input" type="checkbox" id="pv-b-required" data-b="required"><label class="form-check-label" for="pv-b-required">Must be filled in</label></div>
                  <div class="small text-muted mb-2" data-b-also></div>
                </div>
                <div class="row g-2 mb-2">
                  <div class="col-6"><label for="pv-size" class="small">Text size</label><select id="pv-size" class="form-select form-select-sm" data-pv-size><?php foreach ([6, 7, 8, 9, 10, 11, 12, 14, 16, 18, 20, 24] as $z): ?><option value="<?= $z ?>"><?= $z ?> pt</option><?php endforeach; ?></select></div>
                  <div class="col-6"><label for="pv-align" class="small">Align</label><select id="pv-align" class="form-select form-select-sm" data-pv-align><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></div>
                </div>
                <div class="form-check small mb-2" data-pv-plain-row><input class="form-check-input" type="checkbox" id="pv-plain" data-pv-plain><label class="form-check-label" for="pv-plain">No currency symbol (the PDF already has one)</label></div>
                <div class="small text-muted mb-2" data-pv-pos></div>
                <button type="button" class="btn btn-sm btn-outline-danger" data-pv-delete><i class="fas fa-trash me-1"></i>Remove box</button>
              </div>
            </div>
            <hr>
            <h6 class="small fw-bold text-uppercase text-muted">Boxes on this template <span class="badge text-bg-secondary" data-pv-count>0</span></h6>
            <div class="small" data-pv-list></div>
            <div class="alert alert-light border small mt-3 mb-0" data-pv-checks></div>
          </div>
          <div class="tab-pane fade" id="ct-tab-services" role="tabpanel">
            <p class="small text-muted">What you charge for, with a price and how it's billed. Each row's quantity, price and total can go in a box on your PDF, and the totals too. For existing clients Align can count the quantity (users, workstations, servers…).</p>
            <input type="hidden" data-ct-svc-title>
            <div id="ct-services"></div>
            <button type="button" class="btn btn-sm btn-primary mt-2" data-ct-add-row><i class="fas fa-plus me-1"></i>Add a service</button>
          </div>
          <div class="tab-pane fade" id="ct-tab-style" role="tabpanel">
            <div class="mb-2 small">
              <div class="form-check"><input class="form-check-input" type="radio" name="ct_counter" id="cs-cs-after" value="after" data-ct-signing="countersign" <?= $sg['countersign'] === 'after' ? 'checked' : '' ?>><label class="form-check-label" for="cs-cs-after">The client signs, then you countersign</label></div>
              <div class="form-check"><input class="form-check-input" type="radio" name="ct_counter" id="cs-cs-before" value="before" data-ct-signing="countersign" <?= $sg['countersign'] === 'before' ? 'checked' : '' ?>><label class="form-check-label" for="cs-cs-before">You sign first, when you send it</label></div>
              <div class="form-check"><input class="form-check-input" type="radio" name="ct_counter" id="cs-cs-none" value="none" data-ct-signing="countersign" <?= $sg['countersign'] === 'none' ? 'checked' : '' ?>><label class="form-check-label" for="cs-cs-none">Only the client signs</label></div>
            </div>
            <div class="row g-2">
              <div class="col-6 mb-2"><label for="cs-days" class="small">Signing link works for</label><div class="input-group input-group-sm"><input type="number" min="1" max="365" id="cs-days" class="form-control" data-ct-signing="link_days" value="<?= (int) $sg['link_days'] ?>"><span class="input-group-text">days</span></div></div>
              <div class="col-6 mb-2 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="cs-code" data-ct-signing="verify_code" <?= $sg['verify_code'] ? 'checked' : '' ?>><label class="form-check-label small" for="cs-code">Emailed code before signing</label></div></div>
            </div>
            <div class="mb-2"><label for="cs-subj" class="small">Email subject</label><input id="cs-subj" class="form-control form-control-sm" maxlength="200" data-ct-signing="subject" value="<?= e($sg['subject']) ?>" placeholder="Please review and sign: {{client_name}} and {{company_name}}"></div>
            <div class="mb-2"><label for="cs-msg" class="small">Email message</label><textarea id="cs-msg" class="form-control form-control-sm" rows="5" maxlength="4000" data-ct-signing="message"><?= e($sg['message']) ?></textarea>
              <div class="form-text">Fields like <code>{{signer_name}}</code> work here. You can change it for each contract when you send it.</div></div>
            <h6 class="mt-3">About</h6>
            <div class="mb-2"><label for="cs-desc" class="small">Description <span class="text-muted">(for your team)</span></label><input id="cs-desc" name="description" class="form-control form-control-sm" maxlength="500" value="<?= e((string) $t['description']) ?>"></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="cs-active" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="cs-active">Available for new contracts</label></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>
<form method="post" action="/contracts/templates/<?= $id ?>/duplicate" id="tpl-dup"><?= csrf_field() ?></form>
<form method="post" action="/contracts/templates/<?= $id ?>/delete" id="tpl-del"><?= csrf_field() ?></form>
<div class="modal fade" id="modal-new-pdf" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="/contracts/templates/<?= $id ?>/pdf" enctype="multipart/form-data"><?= csrf_field() ?>
      <div class="modal-header bg-dark"><h5 class="modal-title">Upload a new version</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="small">The boxes stay where they are on each page, so check them afterwards if the layout moved. Contracts already made keep the version they were made with. Save your changes here first.</p>
        <input type="file" name="file" class="form-control" accept="application/pdf,.pdf" required aria-label="New PDF">
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Upload</button></div>
    </form>
  </div></div>
</div>

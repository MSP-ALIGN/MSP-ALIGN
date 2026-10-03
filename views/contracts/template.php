<?php
use Align\Contracts\Template;

/** The contract template builder (written in Align). contracts.js builds the blocks and lists from the JSON below. @var array $t, $unknown; int $uses */
$id = (int) $t['id'];
$def = $t['def'];
$st = $def['style'];
$sg = $def['signing'];
$json = fn($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$meta = ['builtIn' => array_map(fn($b) => ['label' => $b[0], 'help' => $b[1]], Template::BUILT_IN), 'types' => Template::FIELD_TYPES, 'auto' => Template::AUTO,
    'periods' => Template::PERIODS, 'currency' => \Align\Fmt::symbol()];
?>
<div class="small"><a href="/contracts/templates">Contract templates</a> /</div>
<form method="post" action="/contracts/templates/<?= $id ?>" id="tpl-form" data-unsaved data-ct-builder data-preview="/contracts/templates/<?= $id ?>/preview">
  <?= csrf_field() ?>
  <input type="hidden" name="def" id="tpl-def" value="">
  <script type="application/json" id="ct-def-json"><?= $json($def) ?></script>
  <script type="application/json" id="ct-meta-json"><?= $json($meta) ?></script>
  <div class="d-flex flex-wrap align-items-center mb-3 gap-2">
    <input name="name" class="form-control form-control-lg fw-semibold ct-name me-auto" maxlength="190" value="<?= e($t['name']) ?>" aria-label="Template name" required>
    <span class="small text-muted" data-ct-state></span>
    <a class="btn btn-sm btn-default" href="/contracts/templates/<?= $id ?>/pdf" target="_blank" rel="noopener" title="A PDF with sample values, as last saved"><i class="fas fa-file-pdf me-1"></i>Sample PDF</a>
    <button class="btn btn-sm btn-primary"><i class="fas fa-floppy-disk me-1"></i>Save</button>
    <div class="btn-group">
      <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown" aria-label="More actions"><i class="fas fa-ellipsis"></i></button>
      <div class="dropdown-menu dropdown-menu-end">
        <button class="dropdown-item" form="tpl-dup"><i class="fas fa-fw fa-copy me-1"></i>Duplicate</button>
        <a class="dropdown-item" href="/contracts/templates/<?= $id ?>/export"><i class="fas fa-fw fa-download me-1"></i>Export</a>
        <div class="dropdown-divider"></div>
        <button class="dropdown-item text-danger" form="tpl-del" data-confirm="<?= $uses ? 'Hide this template? Contracts made from it keep their own copy.' : 'Delete this template?' ?>" data-confirm-ok="<?= $uses ? 'Hide' : 'Delete' ?>"><i class="fas fa-fw fa-trash me-1"></i><?= $uses ? 'Hide' : 'Delete' ?></button>
      </div>
    </div>
  </div>
  <?php if ($unknown): ?>
    <div class="alert alert-warning py-2 small">These placeholders aren't fields, so they print empty: <code>{{<?= e(implode('}}</code>, <code>{{', $unknown)) ?>}}</code>. Add them under <b>Fields</b> or fix the spelling.</div>
  <?php endif; ?>

  <div class="row">
    <div class="col-xl-7">
      <div class="card ct-doc-card">
        <div class="card-header py-2 d-flex align-items-center"><h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-file-lines me-1"></i>Contract</h3>
          <span class="small text-muted d-none d-md-inline">Type <code>{{</code> field names, or use <b>Insert field</b>. Paste from Word to keep headings and lists.</span></div>
        <div class="card-body ct-blocks" id="ct-blocks"></div>
        <div class="card-footer d-flex flex-wrap align-items-center gap-1">
          <span class="small text-muted me-1">Add:</span>
          <?php foreach (['text' => ['fa-paragraph', 'Text'], 'services' => ['fa-list-ol', 'Services table'], 'fields' => ['fa-table-list', 'Field list'], 'signatures' => ['fa-signature', 'Signatures'], 'page_break' => ['fa-scissors', 'Page break']] as $k => [$ic, $l]): ?>
            <button type="button" class="btn btn-sm btn-default" data-ct-add="<?= $k ?>"><i class="fas <?= $ic ?> me-1"></i><?= $l ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="col-xl-5">
      <div class="card ct-side card-tabs">
        <div class="card-header p-0 border-bottom-0">
          <ul class="nav nav-tabs ct-side-tabs" role="tablist">
            <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#ct-tab-preview" role="tab"><i class="fas fa-eye me-1"></i>Preview</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#ct-tab-fields" role="tab"><i class="fas fa-i-cursor me-1"></i>Fields</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#ct-tab-services" role="tab"><i class="fas fa-list-ol me-1"></i>Services</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#ct-tab-sections" role="tab"><i class="fas fa-layer-group me-1"></i>Sections</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#ct-tab-style" role="tab"><i class="fas fa-palette me-1"></i>Look &amp; signing</button></li>
          </ul>
        </div>
        <div class="card-body tab-content">
          <div class="tab-pane fade show active" id="ct-tab-preview" role="tabpanel">
            <div class="small text-muted mb-2">With sample values. Grey boxes are filled in per contract; green ones by the client when they sign.</div>
            <div class="ct-paper ct-paper-sm" data-ct-preview></div>
          </div>
          <div class="tab-pane fade" id="ct-tab-fields" role="tabpanel">
            <p class="small text-muted">Fields are the blanks in your contract. <b>You</b> fill yours in before sending; the <b>client</b> fills theirs in when signing. Click <i class="fas fa-arrow-left"></i> to put one where your cursor is in the text.</p>
            <div id="ct-fields"></div>
            <button type="button" class="btn btn-sm btn-primary mt-2" data-ct-add-field><i class="fas fa-plus me-1"></i>Add a field</button>
            <h6 class="mt-4 mb-2">Filled in by Align</h6>
            <div id="ct-builtins" class="ct-builtins"></div>
          </div>
          <div class="tab-pane fade" id="ct-tab-services" role="tabpanel">
            <div class="mb-2"><label for="ct-svc-title" class="small">Table title</label><input id="ct-svc-title" class="form-control form-control-sm" maxlength="120" data-ct-svc-title></div>
            <p class="small text-muted">Each row has a price and how it's billed. For existing clients, Align can fill in the quantity from what it counts (devices, users, licenses). An optional row starts unticked unless Align counts some.</p>
            <div id="ct-services"></div>
            <button type="button" class="btn btn-sm btn-primary mt-2" data-ct-add-row><i class="fas fa-plus me-1"></i>Add a service</button>
          </div>
          <div class="tab-pane fade" id="ct-tab-sections" role="tabpanel">
            <p class="small text-muted">Optional parts of the contract, like an onsite support clause or a backup add-on. Turn each one on or off per contract; choose which blocks belong to it with the block's <b>Shows</b> menu.</p>
            <div id="ct-sections"></div>
            <button type="button" class="btn btn-sm btn-primary mt-2" data-ct-add-section><i class="fas fa-plus me-1"></i>Add a section</button>
          </div>
          <div class="tab-pane fade" id="ct-tab-style" role="tabpanel">
            <h6>Look</h6>
            <div class="mb-2"><label for="cs-title" class="small">Title at the top</label><input id="cs-title" class="form-control form-control-sm" maxlength="160" data-ct-style="title" value="<?= e($st['title']) ?>"></div>
            <div class="row g-2">
              <div class="col-6 mb-2"><label for="cs-font" class="small">Font</label><select id="cs-font" class="form-select form-select-sm" data-ct-style="font"><option value="sans" <?= $st['font'] === 'sans' ? 'selected' : '' ?>>Sans serif (Helvetica)</option><option value="serif" <?= $st['font'] === 'serif' ? 'selected' : '' ?>>Serif (Times)</option></select></div>
              <div class="col-3 mb-2"><label for="cs-size" class="small">Size</label><select id="cs-size" class="form-select form-select-sm" data-ct-style="size"><?php foreach ([9, 10, 11, 12] as $z): ?><option value="<?= $z ?>" <?= (int) $st['size'] === $z ? 'selected' : '' ?>><?= $z ?> pt</option><?php endforeach; ?></select></div>
              <div class="col-3 mb-2"><label for="cs-paper" class="small">Paper</label><select id="cs-paper" class="form-select form-select-sm" data-ct-style="paper"><option value="letter" <?= $st['paper'] === 'letter' ? 'selected' : '' ?>>Letter</option><option value="a4" <?= $st['paper'] === 'a4' ? 'selected' : '' ?>>A4</option></select></div>
            </div>
            <div class="mb-2"><label for="cs-color" class="small">Accent color</label>
              <div class="d-flex align-items-center"><input type="color" id="cs-color" class="form-control form-control-color form-control-sm me-2" value="<?= e($st['color'] ?: \Align\Branding::color()) ?>" data-ct-color>
                <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="cs-brand" data-ct-brand <?= $st['color'] === '' ? 'checked' : '' ?>><label class="form-check-label small" for="cs-brand">Use the brand color (Settings → Branding)</label></div></div></div>
            <div class="mb-2"><label for="cs-header" class="small">Header text <span class="text-muted">(top right of each page)</span></label><input id="cs-header" class="form-control form-control-sm" maxlength="200" data-ct-style="header" value="<?= e($st['header']) ?>" placeholder="e.g. {{company_phone}} · {{company_website}}"></div>
            <div class="mb-2"><label for="cs-footer" class="small">Footer text</label><input id="cs-footer" class="form-control form-control-sm" maxlength="200" data-ct-style="footer" value="<?= e($st['footer']) ?>" placeholder="e.g. {{company_name}} · Confidential"></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="cs-logo" data-ct-style="logo" <?= $st['logo'] ? 'checked' : '' ?>><label class="form-check-label small" for="cs-logo">Logo at the top of each page<?= \Align\Branding::hasLogo() ? '' : ' (upload one in Settings → Branding)' ?></label></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="cs-pages" data-ct-style="page_numbers" <?= $st['page_numbers'] ? 'checked' : '' ?>><label class="form-check-label small" for="cs-pages">Page numbers</label></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="cs-init" data-ct-style="initials_footer" <?= $st['initials_footer'] ? 'checked' : '' ?>><label class="form-check-label small" for="cs-init">Client's initials at the bottom of every page</label></div>
            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="cs-photo" data-ct-style="photo" <?= $st['photo'] ? 'checked' : '' ?>><label class="form-check-label small" for="cs-photo">Your profile picture next to your signature</label></div>
            <h6>Signing</h6>
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
              <div class="form-text">Fields like <code>{{signer_name}}</code> work here. You can change the message for each contract when you send it.</div></div>
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

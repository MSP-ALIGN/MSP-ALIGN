<?php use Align\Docs\Documents; ?>
<div class="small"><a href="/documents/templates">Templates</a> /</div>
<form method="post" action="/documents/templates/<?= (int) $t['id'] ?>" id="template-form">
  <?= csrf_field() ?>
  <input type="hidden" name="body" id="template-body">
  <div class="row">
    <div class="col-xl-9">
      <div class="card doc-card">
        <div class="card-body pb-0">
          <input class="form-control doc-title" name="name" value="<?= e($t['name']) ?>" maxlength="190" required aria-label="Template name">
          <div class="row g-2 doc-meta mt-2">
            <div class="col-sm-4 mb-2"><select name="category" class="form-select form-select-sm"><?php foreach (Documents::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $t['category'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-8 mb-2"><input name="description" class="form-control form-control-sm" value="<?= e($t['description']) ?>" placeholder="Short description shown when picking a template"></div>
          </div>
        </div>
        <div id="doc-editor" class="doc-editor" data-mode="template"></div>
        <template id="doc-initial"><?= $t['body_html'] ?></template>
      </div>
    </div>
    <div class="col-xl-3">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-code me-2"></i>Placeholders</h3></div>
        <div class="card-body small">
          <p>Click to insert at the cursor:</p>
          <?php foreach ($placeholders as $p): ?><button type="button" class="btn btn-xs btn-outline-secondary mb-1 me-1" data-insert="{{<?= e($p) ?>}}">{{<?= e($p) ?>}}</button><?php endforeach; ?>
        </div>
      </div>
      <button class="btn btn-primary w-100" name="action" value="save"><i class="fas fa-check me-1"></i>Save template</button>
      <button class="btn btn-outline-danger w-100 btn-sm" name="action" value="delete" formnovalidate data-confirm="Delete this template? Documents made from it are not affected.">Delete template</button>
      <p class="small text-muted mt-2">Changes apply to new documents only. Existing documents keep their own text.</p>
    </div>
  </div>
</form>

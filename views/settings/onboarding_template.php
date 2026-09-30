<?php
/** Edit one onboarding template. @var array $t; bool $hasFile; array $placeholders */
$isEmail = $t['kind'] === 'email';
?>
<div class="small"><a href="/settings/onboarding">Settings → Onboarding</a> /</div>
<form method="post" action="/settings/onboarding/templates/<?= (int) $t['id'] ?>" id="template-form" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="body" id="template-body">
  <div class="row">
    <div class="col-xl-9">
      <div class="card doc-card">
        <div class="card-body pb-0">
          <input class="form-control doc-title" name="title" value="<?= e($t['title']) ?>" maxlength="190" required aria-label="<?= $isEmail ? 'Template name' : 'Page heading' ?>">
          <?php if ($isEmail): ?><input class="form-control mt-2" name="subject" value="<?= e($t['subject']) ?>" maxlength="255" required aria-label="Subject" placeholder="Subject">
          <?php elseif ($t['slug'] === 'intro'): ?><p class="small text-muted mt-2 mb-2">The heading and message at the top of the client's onboarding page.</p><?php endif; ?>
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
          <?php foreach ($placeholders as $p): if (!$isEmail && in_array($p, ['onboarding_link', 'sender_name', 'contact_first_name', 'contact_name', 'onsite_week'], true)) continue; ?>
            <button type="button" class="btn btn-xs btn-outline-secondary mb-1 me-1" data-insert="{{<?= e($p) ?>}}">{{<?= e($p) ?>}}</button>
          <?php endforeach; ?>
          <?php if ($isEmail): ?><p class="mt-2 mb-0"><code>{{onboarding_link}}</code> becomes the <b>Start onboarding</b> button.</p><?php endif; ?>
        </div>
      </div>
      <?php if (!$isEmail): ?>
      <div class="card">
        <div class="card-body small">
          <div class="form-check form-switch mb-2"><input type="checkbox" class="form-check-input" id="act" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="act">Show on the onboarding page</label></div>
          <div class="mb-3 mb-2"><label for="srt">Order</label><input type="number" class="form-control form-control-sm" id="srt" name="sort" value="<?= (int) $t['sort'] ?>" style="max-width:120px"></div>
          <?php if ($t['slug'] !== 'intro'): ?>
          <label>Full guide (PDF)</label>
          <?php if ($hasFile): ?><div class="mb-1"><i class="fas fa-file-pdf text-danger me-1"></i><?= e($t['file_name']) ?></div>
            <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="rmf" name="remove_file" value="1"><label class="form-check-label" for="rmf">Remove the PDF</label></div><?php endif; ?>
          <input type="file" class="form-control" id="gf" name="file" accept="application/pdf,.pdf">
          <small class="form-text text-muted">Offered as "Open the full guide", e.g. a version with screenshots. Up to 15 MB.</small>
          <?php endif; ?>
        </div>
      </div>
      <?php else: ?><input type="hidden" name="sort" value="<?= (int) $t['sort'] ?>"><?php endif; ?>
      <button class="btn btn-primary w-100" name="action" value="save"><i class="fas fa-check me-1"></i>Save</button>
      <?php if (!$isEmail): ?><button class="btn btn-outline-danger w-100 btn-sm" name="action" value="delete" formnovalidate data-confirm="Delete this page?">Delete page</button><?php endif; ?>
    </div>
  </div>
</form>

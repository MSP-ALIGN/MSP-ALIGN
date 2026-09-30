<?php
use Align\Docs\Documents;

/** @var array $templates  @var ?array $clients  @var ?int $presetClient */
$clients = $clients ?? null;
$presetClient = $presetClient ?? null;
?>
<div class="modal fade" id="modal-new-doc" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/documents">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-file-circle-plus me-2"></i>New document</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($clients !== null): ?>
            <div class="mb-3">
              <label>Client</label>
              <select name="client_id" class="form-select">
                <option value="">— Internal (our own documents) —</option>
                <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <input type="hidden" name="client_id" value="<?= (int) $presetClient ?>">
          <?php endif; ?>
          <label>Start from</label>
          <div class="template-picker mb-3">
            <label class="tpl-option">
              <input type="radio" name="template_id" value="0" checked>
              <span><i class="fas fa-file fa-fw text-muted me-1"></i><b>Blank document</b><small class="d-block text-muted">Start with an empty page.</small></span>
            </label>
            <?php foreach ($templates as $t): [$cl, $ci, $cc] = Documents::category($t['category']); ?>
              <label class="tpl-option">
                <input type="radio" name="template_id" value="<?= (int) $t['id'] ?>">
                <span><i class="fas <?= $ci ?> fa-fw text-<?= $cc ?> me-1"></i><b><?= e($t['name']) ?></b><small class="d-block text-muted"><?= e($t['description'] ?? '') ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-8 mb-0"><label>Title <small class="text-muted">(defaults to the template name)</small></label><input name="title" class="form-control" maxlength="255"></div>
            <div class="mb-3 col-md-4 mb-0"><label>Category</label>
              <select name="category" class="form-select"><option value="">From template</option>
                <?php foreach (Documents::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <p class="small text-muted mt-3 mb-0">Templates fill in the client's name, contact and your company details automatically. Anything in [brackets] is left for you to complete.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Create &amp; open</button>
        </div>
      </form>
    </div>
  </div>
</div>

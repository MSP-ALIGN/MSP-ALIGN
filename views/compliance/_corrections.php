<?php
/**
 * 2.38.3 Updates to built-in content that Align didn't apply because an admin had edited it (Compliance\Corrections),
 * shown on the framework editor and the template editor. @var array $corrections (content_corrections rows);
 * string $action (the form action prefix: /frameworks/{id}/corrections or /documents/templates/{id}/corrections).
 * Every value is escaped; a template's new text is shown as plain text (it is Align's own HTML, but escaping it here
 * keeps the page simple and safe), and each answer is its own small form outside the editor's form.
 */
use Align\Compliance\Corrections;

if (!$corrections) {
    return;
}
?>
<div class="card card-outline card-warning" data-corrections>
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-arrows-rotate me-2"></i>Updates from MSP Align to review (<?= count($corrections) ?>)</h3></div>
  <div class="card-body small pb-1">
    <p class="mb-2">MSP Align corrected this built-in content, but you had edited these parts, so your version was kept. Use the update or keep yours.</p>
    <?php foreach ($corrections as $c): $label = Corrections::LABELS[$c['field']] ?? $c['field']; ?>
      <div class="border-top py-2" data-correction="<?= (int) $c['id'] ?>">
        <div class="fw-semibold"><?= $c['ref'] !== null ? e($c['ref']) . ' · ' : '' ?><?= e($label) ?></div>
        <?php if ($c['why']): ?><div class="text-body-secondary mb-1"><?= e($c['why']) ?></div><?php endif; ?>
        <?php if ($c['kind'] === 'template'): ?>
          <details class="mb-1"><summary>Show the updated text</summary><pre class="small text-wrap border rounded p-2 mt-1 mb-0" style="max-height:20rem"><?= e(trim(strip_tags(str_replace(['</p>', '</li>', '<br>', '</h2>', '</h3>'], "\n", (string) $c['new_value'])))) ?></pre></details>
        <?php else: ?>
          <div class="row g-2 mb-1">
            <div class="col-md-6"><div class="text-body-secondary">Yours</div><div class="border rounded p-2 text-break"><?= $c['current_value'] !== null ? e($c['current_value']) : '<em>empty</em>' ?></div></div>
            <div class="col-md-6"><div class="text-body-secondary">Update</div><div class="border rounded p-2 text-break"><?= $c['new_value'] !== null ? e($c['new_value']) : '<em>empty</em>' ?></div></div>
          </div>
        <?php endif; ?>
        <form method="post" action="<?= e($action) ?>/<?= (int) $c['id'] ?>" class="d-flex gap-1">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-primary" name="action" value="apply"<?= $c['kind'] === 'template' ? ' data-confirm="Replace this template\'s text with the updated version? Your edits to it are lost. Documents already made from it don\'t change."' : '' ?>>Use the update</button>
          <button class="btn btn-sm btn-outline-secondary" name="action" value="keep">Keep mine</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>

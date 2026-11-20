<?php
/**
 * 2.40.0 The client answers compliance questions sent from the guided setup (ClientAnswerController::show).
 * @var array $r the request with its questions, $company, $topics (Setup::topics), string $token. All text escaped.
 */
?>
<div class="card card-body">
  <h1 class="h4 mb-1">A few security questions</h1>
  <p class="text-muted"><?= e($company['name']) ?> is updating <?= e($r['client_name']) ?>'s security and compliance records and would like your answers. Pick the answer closest to how things work today; "Not sure" is fine. Add a note if it helps.</p>
  <?php if ($r['message']): ?><div class="alert alert-light border small"><?= nl2br(e((string) $r['message'])) ?></div><?php endif; ?>
  <form method="post" action="/portal/answers/<?= e($token) ?>">
    <?= csrf_field() ?>
    <?php foreach ($r['questions'] as $i => $q): $t = $topics[$q['topic']] ?? null; ?>
      <fieldset class="border rounded p-3 mb-3" data-question>
        <legend class="float-none w-auto px-1 fs-6 fw-bold mb-1"><?= $i + 1 ?>. <?= e($q['question']) ?></legend>
        <?php if ($t && $t[2]): ?><p class="small text-muted mb-2"><i class="fas fa-circle-info me-1"></i><?= e($t[2]) ?></p><?php endif; ?>
        <div class="d-flex flex-wrap gap-3 mb-2">
          <?php foreach (\Align\Compliance\ClientQuestions::ANSWERS as $k => $label): ?>
            <div class="form-check"><input class="form-check-input" type="radio" name="a[<?= (int) $q['id'] ?>]" value="<?= e($k) ?>" id="q<?= (int) $q['id'] ?>-<?= e($k) ?>">
              <label class="form-check-label" for="q<?= (int) $q['id'] ?>-<?= e($k) ?>"><?= e($label) ?></label></div>
          <?php endforeach; ?>
        </div>
        <label class="form-label small mb-1" for="n<?= (int) $q['id'] ?>">Note <span class="text-muted">(optional)</span></label>
        <textarea class="form-control form-control-sm" id="n<?= (int) $q['id'] ?>" name="n[<?= (int) $q['id'] ?>]" rows="2" maxlength="2000"></textarea>
      </fieldset>
    <?php endforeach; ?>
    <button class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Send my answers</button>
    <p class="small text-muted mt-2 mb-0">Questions you leave blank stay open on this link until it expires. <?= e($company['name']) ?> reviews each answer before it goes into your records.</p>
  </form>
</div>

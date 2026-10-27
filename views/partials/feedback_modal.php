<?php
/**
 * 2.27.0 The Send feedback form (any staff role), opened from the top bar's Feedback menu with the kind preset
 * (data-feedback-kind, app.js). The page it's sent from is the current URI, which FeedbackController keeps only when
 * it's a path on this site. Nothing here comes from data apart from the escaped URI. A screenshot can be chosen or
 * pasted (app.js puts a pasted image into the file input).
 */
use Align\Feedback\Feedback;

?>
<div class="modal fade" id="modal-feedback" tabindex="-1" aria-labelledby="modal-feedback-title" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/feedback" enctype="multipart/form-data" data-unsaved>
        <?= csrf_field() ?>
        <input type="hidden" name="page" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="modal-feedback-title"><i class="fas fa-fw fa-comment-dots me-2"></i>Send feedback</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="btn-group mb-3" role="group" aria-label="Kind">
            <?php foreach (Feedback::KINDS as $k => [$label, $icon]): ?>
              <input type="radio" class="btn-check" name="kind" id="fb-kind-<?= e($k) ?>" value="<?= e($k) ?>"<?= $k === 'bug' ? ' checked' : '' ?>>
              <label class="btn btn-outline-secondary" for="fb-kind-<?= e($k) ?>"><i class="fas <?= e($icon) ?> me-1" aria-hidden="true"></i><?= e($label) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="fb-title">Title</label>
            <input class="form-control" id="fb-title" name="title" maxlength="<?= Feedback::TITLE_MAX ?>" required placeholder="A short summary">
          </div>
          <div class="mb-3">
            <label class="form-label" for="fb-body">Details <span class="text-muted small">(optional)</span></label>
            <textarea class="form-control" id="fb-body" name="body" rows="5" maxlength="<?= Feedback::BODY_MAX ?>" placeholder="What happened, what you expected, or what you'd like it to do"></textarea>
          </div>
          <div class="row g-3">
            <div class="col-sm-4">
              <label class="form-label" for="fb-priority">Priority</label>
              <select class="form-select" id="fb-priority" name="priority">
                <?php foreach (Feedback::PRIORITIES as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === 'normal' ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-8">
              <label class="form-label" for="fb-screenshot">Screenshot <span class="text-muted small">(optional, PNG or JPEG, up to 5 MB)</span></label>
              <input class="form-control" type="file" id="fb-screenshot" name="screenshot" accept=".png,.jpg,.jpeg,image/png,image/jpeg" data-unsaved-file data-max-bytes="<?= Feedback::MAX_FILE ?>" aria-describedby="fb-paste-hint">
              <div class="form-text" id="fb-paste-hint" data-default="Or paste one here (Ctrl+V) while this form is open.">Or paste one here (Ctrl+V) while this form is open.</div>
            </div>
          </div>
          <p class="small text-muted mt-3 mb-0"><i class="fas fa-link me-1"></i>The page you're on is sent with it. Everyone on your team can see feedback under <a href="/feedback">Feedback</a>.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Send</button>
        </div>
      </form>
    </div>
  </div>
</div>

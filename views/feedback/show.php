<?php
/**
 * 2.27.0 One bug report or feature request: what was sent, the page it came from, its screenshot, and its status.
 * Admins also get the form to change the status, priority and note, and Delete.
 * @var array $f (Feedback::find); bool $isAdmin
 * Every value from the report is escaped. The page it was sent from is a path on this site (Feedback::page) and is
 * shown as text with an Open link built from that path only. The screenshot is loaded from our own route as an <img>.
 */
use Align\Feedback\Feedback;

$id = (int) $f['id'];
[$kl, $ki, $kt] = Feedback::KINDS[$f['kind']];
[$sl, $st, $isOpen] = Feedback::STATUSES[$f['status']];
echo \Align\View::fetch('partials/page_header', [
    'icon' => $ki, 'title' => $kl . ' #' . $id,
    'desc' => 'Sent by ' . e($f['sender'] ?? ($f['created_name'] ?: 'someone')) . ' · ' . e(fmt_datetime($f['created_at'])),
    'secondary' => ['<a class="btn btn-sm btn-default me-1" href="/feedback"><i class="fas fa-arrow-left me-1"></i>All feedback</a>'],
    'help' => 'guide-feedback',
]);
?>
<div class="row g-3">
  <div class="<?= $isAdmin ? 'col-lg-8' : 'col-12' ?>">
    <div class="card">
      <div class="card-body">
        <h2 class="h5 mb-2"><?= e($f['title']) ?></h2>
        <div class="mb-3">
          <span class="badge text-bg-<?= e(status_tone($st)) ?>"><?= e($sl) ?></span>
          <span class="badge text-bg-light border ms-1"><?= e(Feedback::PRIORITIES[$f['priority']]) ?> priority</span>
          <?php if (!$isOpen && $f['closed_at']): ?><span class="small text-muted ms-2"><?= e($sl) ?> <?= e(fmt_date($f['closed_at'])) ?><?= $f['closer'] ? ' by ' . e($f['closer']) : '' ?></span><?php endif; ?>
        </div>
        <?php if ($f['body']): ?><div class="mb-3" style="white-space: pre-wrap"><?= e($f['body']) ?></div><?php else: ?><p class="text-muted">No details were given.</p><?php endif; ?>
        <?php if ($f['page']): ?>
          <p class="small mb-3"><i class="fas fa-link text-muted me-1"></i>Sent from <code><?= e($f['page']) ?></code> <a href="<?= e($f['page']) ?>" class="ms-1">Open this page</a></p>
        <?php endif; ?>
        <?php if ($f['admin_note']): ?>
          <div class="alert alert-light border mb-3"><div class="small text-muted mb-1"><i class="fas fa-reply fa-flip-horizontal me-1"></i>Note from an admin</div><div style="white-space: pre-wrap"><?= e($f['admin_note']) ?></div></div>
        <?php endif; ?>
        <?php if ($f['file_name']): ?>
          <a href="/feedback/<?= $id ?>/screenshot" target="_blank" rel="noopener"><img src="/feedback/<?= $id ?>/screenshot" alt="Screenshot sent with this report" class="img-fluid border rounded feedback-shot"></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($isAdmin): ?>
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h3 class="card-title h6 mb-0">Update</h3></div>
        <form method="post" action="/feedback/<?= $id ?>" class="card-body">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="fb-status">Status</label>
            <select class="form-select" id="fb-status" name="status">
              <?php foreach (Feedback::STATUSES as $k => [$label]): ?><option value="<?= e($k) ?>"<?= $k === $f['status'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="fb-prio">Priority</label>
            <select class="form-select" id="fb-prio" name="priority">
              <?php foreach (Feedback::PRIORITIES as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $f['priority'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="fb-note">Note <span class="text-muted small">(shown with the report, e.g. "Fixed in 2.27.1")</span></label>
            <textarea class="form-control" id="fb-note" name="admin_note" rows="4" maxlength="<?= Feedback::BODY_MAX ?>"><?= e($f['admin_note'] ?? '') ?></textarea>
          </div>
          <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
        </form>
        <div class="card-footer text-end">
          <form method="post" action="/feedback/<?= $id ?>/delete">
            <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this report<?= $f['file_name'] ? ' and its screenshot' : '' ?>? This can't be undone." data-confirm-ok="Delete"><i class="fas fa-trash me-1"></i>Delete</button>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

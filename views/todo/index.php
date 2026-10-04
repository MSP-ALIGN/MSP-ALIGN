<?php
/**
 * The To do list (techs and admins). @var array $items; int $all; array $by (count per category); string $show
 * Projects ready to start (2.2.2) have Not yet (1 month, or 2, 3 or 6 from its menu) and Ready to start, which opens
 * the confirm window (loaded when opened); with two or more, Ready to start: all opens one window for all of them.
 * Every value is escaped; the forms post to fixed paths (CSRF-checked by the router).
 */
use Align\Roadmap\ProjectTickets;
use Align\Workflow\Todo;

echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-list-check', 'title' => 'To do', 'count' => $all,
    'desc' => 'Everything waiting on your team, from every client. Each item leaves the list by itself once the work is done.',
]);
$tabs = [['All', '/todo', $show === '', $all]];
foreach (Todo::CATEGORIES as $k => $label) {
    if (!empty($by[$k])) {
        $tabs[] = [$label, '/todo?show=' . $k, $show === $k, $by[$k]];
    }
}
$back = $show !== '' ? '/todo?show=' . $show : '/todo';
// Projects ready to start get their own group first (with Not yet and Ready to start); every other item links to its fix
$projects = array_values(array_filter($items, fn($i) => isset($i['project'])));
$others = array_values(array_filter($items, fn($i) => !isset($i['project'])));
?>
<div class="card">
  <?= \Align\View::fetch('partials/toolbar', ['tabs' => $tabs]) ?>
  <ul class="list-group list-group-flush todo-list">
    <?php if ($projects): ?>
      <li class="list-group-item d-flex flex-wrap align-items-center gap-2 bg-body-tertiary" data-todo-head="projects">
        <div class="me-auto"><b>Ready to start</b>
          <div class="small text-muted">Approved and scheduled projects whose quarter is here (or has passed) and that have no ticket yet. Later quarters stay off this list, and no ticket is made until you press Ready to start.</div></div>
        <?php if (count($projects) > 1): ?><button type="button" class="btn btn-sm btn-outline-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#modal-start-all">Ready to start: all <?= count($projects) ?></button><?php endif; ?>
      </li>
      <?php foreach ($projects as $i): $p = $i['project']; ?>
        <li class="list-group-item d-flex flex-wrap align-items-center gap-2" data-todo="<?= e($i['key']) ?>">
          <i class="fas fa-fw <?= e($i['icon']) ?> text-<?= e($i['tone']) ?> fa-lg me-2"></i>
          <div class="me-auto pe-2" style="min-width: 0"><b><?= e($i['title']) ?></b> <span class="text-muted">· <?= e($i['client']) ?></span>
            <div class="small <?= !empty($i['overdue']) ? 'text-warning-emphasis' : 'text-muted' ?>"><?= e($i['detail']) ?></div>
            <?php if (!empty($i['error'])): ?><div class="small text-danger" data-ticket-error><i class="fas fa-triangle-exclamation me-1"></i><?= e($i['error']) ?></div><?php endif; ?></div>
          <span class="badge text-bg-light border me-2 d-none d-md-inline"><?= e(Todo::CATEGORIES[$i['category']]) ?></span>
          <?php // Not yet: the button is a month; the menu offers the other lengths with the date each comes back ?>
          <form method="post" action="/projects/<?= (int) $p['id'] ?>/snooze" class="btn-group">
            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
            <button class="btn btn-sm btn-default" name="months" value="<?= ProjectTickets::SNOOZE_MONTHS[0] ?>" title="Hide it from To do for a month" aria-label="<?= e('Not yet: ' . $p['title'] . ' (' . $p['client'] . '), for a month') ?>">Not yet</button>
            <button type="button" class="btn btn-sm btn-default dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= e('Not yet: ' . $p['title'] . ', choose how long') ?>"></button>
            <div class="dropdown-menu dropdown-menu-end">
              <h6 class="dropdown-header">Back on To do in</h6>
              <?php foreach (ProjectTickets::SNOOZE_MONTHS as $m): ?>
                <button class="dropdown-item d-flex justify-content-between gap-4" name="months" value="<?= $m ?>"><?= $m ?> month<?= $m === 1 ? '' : 's' ?> <span class="text-muted small"><?= e(fmt_date(ProjectTickets::addMonths(date('Y-m-d'), $m), 'short')) ?></span></button>
              <?php endforeach; ?>
            </div>
          </form>
          <a href="#" class="btn btn-sm btn-primary text-nowrap" data-lazy-modal="/projects/<?= (int) $p['id'] ?>/start?back=<?= e(rawurlencode($back)) ?>" data-bs-target="#modal-start-<?= (int) $p['id'] ?>" aria-label="<?= e('Ready to start: ' . $p['title'] . ' (' . $p['client'] . ')') ?>">Ready to start</a>
        </li>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php foreach ($others as $i): ?>
      <li class="list-group-item d-flex align-items-center" data-todo="<?= e($i['key']) ?>">
        <i class="fas fa-fw <?= e($i['icon']) ?> text-<?= e($i['tone']) ?> fa-lg me-3"></i>
        <div class="me-auto pe-3"><b><?= e($i['title']) ?></b><div class="small text-muted"><?= e($i['detail']) ?></div></div>
        <span class="badge text-bg-light border me-3 d-none d-md-inline"><?= e(Todo::CATEGORIES[$i['category']]) ?></span>
        <a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= e($i['link']) ?>"><?= e($i['action']) ?> <i class="fas fa-arrow-right ms-1"></i></a>
      </li>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <li class="list-group-item text-center text-muted py-5"><i class="fas fa-circle-check text-success fa-2x mb-2 d-block"></i>Nothing waiting. New items show up here as syncs bring them in.</li>
    <?php endif; ?>
  </ul>
</div>

<?php if (count($projects) > 1): // Ready to start: all, every project ticked (each one is checked again when posted) ?>
<div class="modal fade" id="modal-start-all" tabindex="-1" aria-hidden="true" aria-labelledby="modal-start-all-title">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/projects/start-all">
        <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title" id="modal-start-all-title"><i class="fas fa-fw fa-play me-2"></i>Make <?= count($projects) ?> tickets in <?= e(psa_name()) ?>?</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p>Each project gets its own <b>QUOTE-</b> ticket, with its devices (or description), quarter and budget, and no client contact. Untick any that should wait.</p>
          <?php foreach ($projects as $i): $p = $i['project']; ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" id="sa-<?= (int) $p['id'] ?>" checked>
              <label class="form-check-label" for="sa-<?= (int) $p['id'] ?>"><b><?= e($p['title']) ?></b> <span class="text-muted">· <?= e($p['client']) ?> · <?= e($i['detail']) ?></span></label>
            </div>
          <?php endforeach; ?>
          <p class="small text-muted mt-3 mb-0">One that <?= e(psa_name()) ?> refuses stays on To do with the reason.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" data-default-submit><i class="fas fa-ticket me-1"></i>Create the tickets</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

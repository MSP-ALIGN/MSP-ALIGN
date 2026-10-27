<?php
/**
 * 2.27.0 Feedback: every bug report and feature request from the team (any staff role sees all). Tabs Open (open and
 * planned), Closed (done and won't do) and All, a kind filter, and for admins a Mark done button on each open one.
 * @var array $items (Feedback::list), $counts (Feedback::counts); string $show, $kind; bool $isAdmin
 * Every value from a report (title, details, sender, page) is escaped; the page is shown as text, never a link
 * (it's a path on this site, but nothing from a report becomes an href). Forms post to fixed paths with integer ids.
 */
use Align\Feedback\Feedback;

$open = $counts['open'] + $counts['planned'];
$closed = $counts['done'] + $counts['declined'];
$q = fn(string $show, string $kind) => '/feedback' . (($p = http_build_query(array_filter(['show' => $show === 'open' ? '' : $show, 'kind' => $kind]))) ? '?' . $p : '');
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-comment-dots', 'title' => 'Feedback', 'count' => $open,
    'desc' => 'Bug reports and feature requests from your team. Send one from the <i class="fas fa-comment-dots"></i> button at the top of any page.'
        . ($isAdmin ? ' Mark each one done (or won\'t do) once it\'s been dealt with; a note you add is shown with it.' : ''),
    'primary' => '<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-feedback"><i class="fas fa-plus me-1"></i>Send feedback</button>',
    'help' => 'guide-feedback',
]);
$tabs = [['Open', $q('open', $kind), $show === 'open', $open], ['Closed', $q('closed', $kind), $show === 'closed', $closed], ['All', $q('all', $kind), $show === 'all', $counts['all']]];
$kindMenu = toolbar_menu('Kind', array_map(fn($k) => $k[0] . 's', Feedback::KINDS), $kind, fn($k) => $q($show, $k), 'Bugs and features');
?>
<div class="card">
  <?= \Align\View::fetch('partials/toolbar', ['tabs' => $tabs, 'menus' => [$kindMenu]]) ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="feedback-list">
      <thead><tr><th style="width:4rem">#</th><th>Report</th><th>Priority</th><th>Status</th><th>Sent</th><?php if ($isAdmin): ?><th class="text-end"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($items as $f): [$kl, $ki, $kt] = Feedback::KINDS[$f['kind']]; [$sl, $st, $isOpen] = Feedback::STATUSES[$f['status']]; ?>
        <tr data-feedback="<?= (int) $f['id'] ?>">
          <td class="text-muted small">#<?= (int) $f['id'] ?></td>
          <td style="min-width:16rem">
            <i class="fas fa-fw <?= e($ki) ?> text-<?= e($kt) ?> me-1" title="<?= e($kl) ?>" aria-hidden="true"></i><span class="visually-hidden"><?= e($kl) ?>: </span><a href="/feedback/<?= (int) $f['id'] ?>" class="fw-semibold"><?= e($f['title']) ?></a>
            <?php if ($f['file_name']): ?><i class="fas fa-image text-muted ms-1" title="Has a screenshot" aria-hidden="true"></i><span class="visually-hidden"> (has a screenshot)</span><?php endif; ?>
            <?php if ($f['body']): ?><div class="small text-muted text-truncate" style="max-width:40rem"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $f['body']), 0, 160, '…')) ?></div><?php endif; ?>
            <?php if ($f['admin_note'] && !$isOpen): ?><div class="small"><i class="fas fa-reply fa-flip-horizontal text-muted me-1"></i><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $f['admin_note']), 0, 160, '…')) ?></div><?php endif; ?>
          </td>
          <td class="small text-nowrap"><?= $f['priority'] === 'high' ? '<span class="text-danger-emphasis fw-semibold">High</span>' : e(Feedback::PRIORITIES[$f['priority']]) ?></td>
          <td class="text-nowrap"><span class="badge text-bg-<?= e(status_tone($st)) ?>"><?= e($sl) ?></span><?php if (!$isOpen && $f['closed_at']): ?><div class="small text-muted"><?= e(fmt_date($f['closed_at'])) ?></div><?php endif; ?></td>
          <td class="small text-nowrap"><?= e($f['sender'] ?? ($f['created_name'] ?: 'Someone')) ?><div class="text-muted" title="<?= e(fmt_datetime($f['created_at'])) ?>"><?= e(rel_time($f['created_at'])) ?></div></td>
          <?php if ($isAdmin): ?>
            <td class="text-end text-nowrap">
              <?php if ($isOpen): ?>
                <form method="post" action="/feedback/<?= (int) $f['id'] ?>" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="back" value="list"><input type="hidden" name="status" value="done">
                  <button class="btn btn-sm btn-outline-success" aria-label="<?= e('Mark done: #' . $f['id'] . ' ' . $f['title']) ?>"><i class="fas fa-check me-1"></i>Mark done</button>
                </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="<?= $isAdmin ? 6 : 5 ?>" class="empty-state"><?= $show === 'open' ? 'Nothing open. Use the <i class="fas fa-comment-dots"></i> button at the top of any page to report a bug or ask for a feature.' : 'No feedback here yet.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($items) >= 500): ?><div class="card-footer small text-muted">Showing the first 500. Use the tabs or the Kind filter to see the rest.</div><?php endif; ?>
</div>

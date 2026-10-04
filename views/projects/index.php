<?php
/**
 * Projects across clients by quarter. @var array $quarters, $unscheduled, $beyond, $years, $clients; int $count, $limit,
 * $clientId; ?int $year; string $q, $status, $category, $ticket; bool $ticketsOn
 * Titles, descriptions and client names are escaped; the edit windows load lazily for techs and admins. 2.2.2: the
 * Ticket column (each row's ticket_state from Roadmap\ProjectTickets) when a PSA can create tickets; Ready to start
 * opens the confirm window (techs and admins).
 */
use Align\Auth;
use Align\Controllers\ProjectController;
use Align\Roadmap\Roadmap;

$canEdit = Auth::can('tech');
$qs = fn(array $over) => '/projects?' . http_build_query(array_filter(array_merge(
    ['status' => $status === 'open' ? '' : $status, 'client' => $clientId ?: '', 'category' => $category, 'year' => $year === null ? '' : (string) $year, 'ticket' => $ticket, 'q' => $q], $over
), fn($v) => $v !== '' && $v !== null));
$back = $_SERVER['REQUEST_URI'] ?? '/projects';

$shown = 0;
// Draws project rows until the page's limit is reached ($shown counts across every group)
$cols = $ticketsOn ? 8 : 7; // the Ticket column only when a PSA can make tickets (group headers span it)
$renderRows = function (array $items) use ($canEdit, $back, $limit, &$shown, $ticketsOn) {
    foreach ($items as $it) {
        if ($shown >= $limit) {
            return; // paged (1.42): the rest are one "Show more" away
        }
        $shown++;
        [$catLabel, $catIcon, $catTone] = Roadmap::category($it['category']);
        [$stLabel, $stTone] = Roadmap::STATUSES[$it['status']];
        [$prLabel, $prTone] = Roadmap::PRIORITIES[$it['priority']];
        ?>
        <tr class="proj-row<?= $it['status'] === 'declined' ? ' text-muted' : '' ?>">
          <td>
            <?php if ($canEdit): ?><a href="#" class="fw-bold" data-lazy-modal="/projects/<?= (int) $it['id'] ?>/form?back=<?= e(rawurlencode($back)) ?>" data-bs-target="#modal-roadmap-<?= (int) $it['id'] ?>"><?= e($it['title']) ?></a>
            <?php else: ?><span class="fw-bold"><?= e($it['title']) ?></span><?php endif; ?>
            <?php if (!empty($it['overdue'])): ?><span class="badge text-bg-danger ms-1" title="Target quarter has passed">overdue</span><?php endif; ?>
            <?php if ($it['description']): ?><div class="small text-muted proj-desc text-truncate" title="<?= e($it['description']) ?>"><?= e($it['description']) ?></div><?php endif; ?>
          </td>
          <td class="small"><a href="/clients/<?= (int) $it['client_id'] ?>/roadmap"><?= e($it['client_name']) ?></a></td>
          <td><span class="badge text-bg-<?= e($catTone) ?>"><i class="fas <?= e($catIcon) ?> me-1"></i><?= e($catLabel) ?></span></td>
          <td><span class="badge text-bg-<?= e($stTone) ?> <?= $stTone === 'light' ? 'border' : '' ?>"><?= e($stLabel) ?></span></td>
          <td><span class="badge text-bg-<?= e($prTone) ?>"><?= e($prLabel) ?></span></td>
          <td class="text-end text-nowrap"><?= $it['cost'] !== null ? money((float) $it['cost']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end text-nowrap small"><?= $it['recurring_monthly'] ? money((float) $it['recurring_monthly']) . '/mo' : '' ?></td>
          <?php if ($ticketsOn): $ts = $it['ticket_state']; // ticket: link and date; startable: button; otherwise why not ?>
            <td class="small text-nowrap" data-ticket-state="<?= e($ts['key']) ?>">
              <?php if ($ts['key'] === 'ticket'): $tu = \Align\Providers\Providers::psaLink('ticket', (string) $it['psa_ticket_id']); ?>
                <?= $tu ? '<a href="' . e($tu) . '" target="_blank" rel="noopener">' . e($ts['text']) . '</a>' : e($ts['text']) ?><?= !empty($it['ticket_at']) ? ' <span class="text-muted">· ' . e(fmt_date($it['ticket_at'], 'short')) . '</span>' : '' ?>
              <?php elseif ($ts['startable'] && $canEdit): // due: the solid button; earlier: what it waits for, and the button to start anyway ?>
                <?php if ($ts['key'] !== 'due'): ?><span class="text-muted d-block"><?= e($ts['text']) ?></span><?php endif; ?>
                <a href="#" class="btn btn-xs <?= $ts['key'] === 'due' ? 'btn-primary' : 'btn-outline-primary' ?>" data-lazy-modal="/projects/<?= (int) $it['id'] ?>/start?back=<?= e(rawurlencode($back)) ?>" data-bs-target="#modal-start-<?= (int) $it['id'] ?>" aria-label="<?= e('Ready to start: ' . $it['title'] . ' (' . $it['client_name'] . ')') ?>">Ready to start</a>
              <?php else: ?>
                <span class="text-muted"><?= e($ts['key'] === 'closed' ? '' : $ts['text']) ?></span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
        <?php
    }
};
?>
<?php
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-diagram-project', 'title' => 'Projects', 'count' => (int) $count,
    'desc' => 'Planned work across all clients, placed in the quarter it\'s targeted for. Budgets roll into each client\'s 3-year IT plan alongside hardware replacements. Overdue open projects show in the current quarter.',
    'primary' => $canEdit ? '<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-roadmap"><i class="fas fa-plus me-1"></i>Add project</button>' : '',
    'secondary' => ['<a class="btn btn-sm btn-default" href="/budget"><i class="fas fa-coins me-1"></i>Budgets</a>'],
]);
$tiles = [];
foreach ($years as $y => $yr) {
    $tiles[] = ['label' => $yr['label'] . ' projects · ' . (int) $yr['count'], 'value' => money($yr['cost']), 'tone' => 'dark', 'href' => $qs(['year' => $year === $y ? '' : (string) $y]), 'active' => $year === $y, 'title' => $yr['range'] . ($year === $y ? ' · showing only this year (click again for all)' : '')];
}
echo \Align\View::fetch('partials/tiles', ['tiles' => $tiles]);
$tabs = [];
foreach (ProjectController::VIEWS as $k => $label) {
    $tabs[] = [$label, $qs(['status' => $k === 'open' ? '' : $k]), $status === $k];
}
$cats = array_map(fn($c) => $c[0], Roadmap::CATEGORIES);
?>
<div class="card">
  <?= \Align\View::fetch('partials/toolbar', ['tabs' => $tabs,
      'search' => ['action' => '/projects', 'value' => $q, 'hidden' => ['status' => $status === 'open' ? '' : $status, 'client' => $clientId ?: '', 'category' => $category, 'year' => $year === null ? '' : (string) $year, 'ticket' => $ticket], 'table' => 'projects-table', 'placeholder' => 'Search project, client'],
      'menus' => array_merge([toolbar_menu('Client', $clients, $clientId ?: '', fn($v) => $qs(['client' => $v]), 'All clients'), toolbar_menu('Category', $cats, $category, fn($v) => $qs(['category' => $v]), 'All categories')],
          $ticketsOn ? [toolbar_menu('Ticket', ProjectController::TICKET_VIEWS, $ticket, fn($v) => $qs(['ticket' => $v]), 'All')] : [])]) ?>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0 projects-table" id="projects-table">
      <thead><tr><th>Project</th><th>Client</th><th>Category</th><th>Status</th><th>Priority</th><th class="text-end">Budget</th><th class="text-end">Recurring</th><?= $ticketsOn ? '<th>Ticket</th>' : '' ?></tr></thead>
      <tbody>
      <?php $any = false; foreach ($quarters as $qt): if (!$qt['items']) continue; $any = true; if ($shown >= $limit) break; ?>
        <tr class="proj-quarter"><th colspan="5"><?= e($qt['label']) ?> <small class="text-muted fw-normal"><?= e($qt['months']) ?><?= $qt['current'] ? ' · current quarter' : '' ?></small></th>
          <th class="text-end text-nowrap"><?= money($qt['cost']) ?></th><th class="text-end small fw-normal text-nowrap"><?= $qt['recurring'] ? '+' . money($qt['recurring']) . '/mo' : '' ?></th><?= $ticketsOn ? '<th></th>' : '' ?></tr>
        <?php $renderRows($qt['items']); ?>
      <?php endforeach; ?>
      <?php if ($beyond): $any = true; if ($shown < $limit): ?>
        <tr class="proj-quarter"><th colspan="<?= $cols ?>">Beyond the 3-year plan</th></tr>
        <?php $renderRows($beyond); ?>
      <?php endif; endif; ?>
      <?php if ($unscheduled): $any = true; if ($shown < $limit): ?>
        <tr class="proj-quarter"><th colspan="<?= $cols ?>">Not scheduled yet <small class="text-muted fw-normal">pick a target quarter to include it in the plan budget</small></th></tr>
        <?php $renderRows($unscheduled); ?>
      <?php endif; endif; ?>
      <?php if (!$any): ?>
        <tr><td colspan="<?= $cols ?>" class="text-center text-muted py-4">No projects match.<?= $canEdit ? ' Use <b>Add project</b> to plan one.' : '' ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= \Align\View::fetch('partials/list_footer', ['shown' => $shown, 'total' => (int) $count, 'moreUrl' => \Align\Paging::moreUrl($limit)]) ?>
</div>

<?php if ($canEdit): ?>
  <?php
  $cid = $clientId ?: 0;
  echo \Align\View::fetch('roadmap/_modal', ['it' => null, 'cid' => $cid, 'pickClients' => $clients, 'back' => $back]);
  ?>
<?php endif; ?>

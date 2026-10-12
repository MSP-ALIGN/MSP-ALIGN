<?php
/**
 * 2.15.0 Tech closet: the MSP's own stock (tabs per office), its loaners out now, and across clients the overdue
 * checkouts and low stock. Admins manage the offices here. @var array $offices Closet::offices(true); ?int $officeId
 * (null = all offices, 0 = no office); array $items; bool $showRetired; array $loans Closet::open(owner: null);
 * array $overdue Closet::overdueByOwner(); array $low Closet::lowByOwner(); array $clients (id, name).
 * Every value is escaped; forms post with CSRF (ClosetController checks roles).
 */
use Align\Auth;
use Align\View;

$canEdit = Auth::can('tech');
$isAdmin = Auth::can('admin');
$active = array_values(array_filter($offices, fn($o) => !$o['retired_at']));
$here = '/closet' . ($officeId !== null ? '?office=' . $officeId : '');
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-boxes-stacked text-secondary me-2"></i>Tech closet</h1>
  <div class="btn-group btn-group-sm mt-2 mt-md-0">
    <a class="btn btn-default" href="?<?= e(http_build_query(array_filter(['office' => $officeId, 'retired' => $showRetired ? null : 1], fn($v) => $v !== null))) ?>"><?= $showRetired ? 'Hide' : 'Show' ?> retired</a>
    <?php if ($canEdit): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-closet-add"><i class="fas fa-plus me-1"></i>Add item</button><?php endif; ?>
  </div>
</div>
<p class="small text-muted">Your own spare parts, loaners and stock, by office. Each client's closet is on its page (Tech closet in the client menu); computers already in Align are marked <b>spare</b> on the device.</p>

<?php // Across clients: what needs attention ?>
<?php if ($overdue || $low): ?>
  <div class="row g-2 mb-3">
    <?php if ($overdue): ?><div class="col-md-6"><div class="card card-outline card-danger h-100 mb-0"><div class="card-body py-2 small"><b><i class="fas fa-clock me-1 text-danger"></i>Overdue checkouts</b>
      <?php foreach ($overdue as $o): ?><div><a href="<?= $o['owner'] ? '/clients/' . (int) $o['owner'] . '/closet' : '/closet' ?>"><?= $o['owner'] ? e((string) $o['name']) : 'Your stock' ?></a>: <?= (int) $o['n'] ?></div><?php endforeach; ?></div></div></div><?php endif; ?>
    <?php if ($low): ?><div class="col-md-6"><div class="card card-outline card-warning h-100 mb-0"><div class="card-body py-2 small"><b><i class="fas fa-arrow-trend-down me-1 text-warning"></i>Low stock</b>
      <?php foreach ($low as $o): ?><div><a href="<?= $o['owner'] ? '/clients/' . (int) $o['owner'] . '/closet' : '/closet' ?>"><?= $o['owner'] ? e((string) $o['name']) : 'Your stock' ?></a>: <?= (int) $o['n'] ?> item<?= (int) $o['n'] === 1 ? '' : 's' ?></div><?php endforeach; ?></div></div></div><?php endif; ?>
  </div>
<?php endif; ?>

<?php // Office tabs (only when there are offices) ?>
<?php if ($offices): ?>
  <ul class="nav nav-tabs mb-0" id="closet-offices">
    <li class="nav-item"><a class="nav-link<?= $officeId === null ? ' active' : '' ?>" href="/closet">All offices</a></li>
    <?php foreach ($offices as $o): if ($o['retired_at'] && !$showRetired) continue; ?>
      <li class="nav-item"><a class="nav-link<?= $officeId === (int) $o['id'] ? ' active' : '' ?><?= $o['retired_at'] ? ' text-muted' : '' ?>" href="/closet?office=<?= (int) $o['id'] ?>"><?= e($o['name']) ?> <span class="badge text-bg-light border"><?= (int) $o['items'] ?></span></a></li>
    <?php endforeach; ?>
    <li class="nav-item"><a class="nav-link<?= $officeId === 0 ? ' active' : '' ?>" href="/closet?office=0">No office</a></li>
  </ul>
<?php endif; ?>
<div class="card card-dark">
  <div class="card-body p-0"><?= View::fetch('closet/_items', ['items' => $items, 'showOffice' => $officeId === null && (bool) $offices,
    'empty' => 'Nothing here yet.' . ($canEdit ? ' Add the spare parts, loaners and stock you keep.' : '')]) ?></div>
</div>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-right-from-bracket me-2"></i>Your stock that's checked out</h3></div>
  <div class="card-body p-0"><?= View::fetch('closet/_checkouts', ['rows' => $loans, 'showBorrower' => true, 'back' => $here, 'empty' => 'Nothing of yours is checked out.']) ?></div>
</div>

<?php // Offices (admins) ?>
<?php if ($isAdmin): ?>
  <div class="card card-dark" id="offices">
    <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-building me-2"></i>Your offices</h3></div>
    <div class="card-body">
      <p class="small text-muted">With more than one office, keep each office's stock under it. Retiring an office keeps its stock and history.</p>
      <?php foreach ($offices as $o): ?>
        <form method="post" action="/closet/offices" class="row g-2 align-items-end mb-2 small">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <div class="col-md-4"><input name="name" class="form-control form-control-sm" maxlength="190" value="<?= e($o['name']) ?>" aria-label="Office name" required></div>
          <div class="col-md-5"><input name="address" class="form-control form-control-sm" maxlength="500" value="<?= e((string) $o['address']) ?>" aria-label="Address" placeholder="Address (optional)"></div>
          <div class="col-md-3 text-nowrap"><button class="btn btn-sm btn-default" name="action" value="save">Save</button>
            <button class="btn btn-sm btn-link" name="action" value="<?= $o['retired_at'] ? 'restore' : 'retire' ?>"><?= $o['retired_at'] ? 'Restore' : 'Retire' ?></button>
            <?php if ($o['retired_at']): ?><span class="badge text-bg-secondary">retired</span><?php endif; ?></div>
        </form>
      <?php endforeach; ?>
      <form method="post" action="/closet/offices" class="row g-2 align-items-end small">
        <?= csrf_field() ?>
        <div class="col-md-4"><input name="name" class="form-control form-control-sm" maxlength="190" placeholder="New office name" aria-label="New office name" required></div>
        <div class="col-md-5"><input name="address" class="form-control form-control-sm" maxlength="500" placeholder="Address (optional)" aria-label="New office address"></div>
        <div class="col-md-3"><button class="btn btn-sm btn-primary" name="action" value="save"><i class="fas fa-plus me-1"></i>Add office</button></div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal fade" id="modal-closet-add" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/closet/items">
      <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($here) ?>">
      <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add to your stock</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><?= View::fetch('closet/_item_fields', ['i' => null, 'msp' => true, 'offices' => $active, 'officeId' => $officeId ?: null, 'pid' => 'add']) ?></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

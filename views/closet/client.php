<?php
/**
 * 2.15.0 One client's tech closet: its stock, its spare devices (with who has one), and what its people have checked
 * out (from its own closet and the MSP's loaners). @var array $client; array $items Closet::items(); bool $showRetired;
 * array $spares Closet::spares(); array $out Closet::open(borrower); array $ownOut Closet::open(owner);
 * array $contacts (id, name); array $inUse the client's devices that could be marked spare.
 * Every value is escaped; forms post with CSRF (ClosetController checks roles and that ids are this client's).
 */
use Align\Auth;
use Align\View;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$canEdit = Auth::can('tech');
$back = "/clients/$cid/closet";
// What's out: this client's own closet (any borrower, though it lends to its own people) and the MSP's loaners to them
$rows = $ownOut;
$seen = array_column($ownOut, 'id');
foreach ($out as $c) {
    if (!in_array($c['id'], $seen, true)) {
        $rows[] = $c;
    }
}
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-boxes-stacked text-secondary me-2"></i>Tech closet</h1>
  <div class="btn-group btn-group-sm mt-2 mt-md-0">
    <a class="btn btn-default" href="?retired=<?= $showRetired ? '0' : '1' ?>"><?= $showRetired ? 'Hide' : 'Show' ?> retired</a>
    <a class="btn btn-default" href="/closet"><i class="fas fa-building me-1"></i>Your stock</a>
    <?php if ($canEdit): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-closet-add"><i class="fas fa-plus me-1"></i>Add item</button><?php endif; ?>
  </div>
</div>
<p class="small text-muted">Spare parts and equipment kept at the client, and who has what. <?= e($client['name']) ?>'s portal users with Tech closet access see this list; with the other closet permissions they can check things out and in, ask for more, and add items and update counts. Their changes show with a <b>portal</b> badge in each item's history.</p>

<div class="card card-dark">
  <div class="card-body p-0"><?= View::fetch('closet/_items', ['items' => $items, 'empty' => 'Nothing in this closet yet.' . ($canEdit ? ' Add spare cables, keyboards, monitors and other parts kept here.' : '')]) ?></div>
</div>

<div class="card card-dark" id="spares">
  <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-laptop me-2"></i>Spare devices</h3></div>
  <div class="card-body p-0">
    <div class="table-responsive"><table class="table table-sm mb-0" id="closet-spares">
      <thead><tr><th>Device</th><th>Model</th><th>Spare since</th><th>Checked out to</th></tr></thead>
      <tbody>
      <?php if (!$spares): ?><tr><td colspan="4" class="empty-state">No spare devices. Mark a computer spare when it goes on the shelf: it stays in replacement plans, but isn't flagged stale or counted in health or coverage checks.</td></tr><?php endif; ?>
      <?php foreach ($spares as $s): ?>
        <tr><td><a href="/devices/<?= (int) $s['id'] ?>"><?= e($s['display_name'] ?: (string) $s['system_name']) ?></a> <span class="text-muted small"><?= e((string) $s['device_type']) ?></span></td>
          <td class="small"><?= e(trim(($s['manufacturer'] ?? '') . ' ' . ($s['model'] ?? ''))) ?: '<span class="text-muted">—</span>' ?><?= $s['serial'] ? '<div class="text-muted">S/N ' . e($s['serial']) . '</div>' : '' ?></td>
          <td class="small"><?= $s['spare_since'] ? e(fmt_date($s['spare_since'])) : '—' ?></td>
          <td class="small"><?= $s['checkout_id'] ? e($s['person']) . ($s['due_on'] ? ' · due ' . e(fmt_date($s['due_on'])) : '') : '<span class="text-muted">on the shelf</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($canEdit && $inUse): ?>
      <form method="post" action="/clients/<?= $cid ?>/closet/spare" class="row g-2 align-items-end p-2 small border-top">
        <?= csrf_field() ?>
        <div class="col-md-6"><label for="spare-device">Mark a device spare</label><select id="spare-device" name="device_id" class="form-select form-select-sm">
          <?php foreach ($inUse as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['display_name'] ?: (string) $d['system_name']) ?> (<?= e((string) $d['device_type']) ?>)</option><?php endforeach; ?></select></div>
        <div class="col-md-3"><button class="btn btn-sm btn-default"><i class="fas fa-box-archive me-1"></i>Mark spare</button></div>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card card-dark" id="checked-out">
  <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-right-from-bracket me-2"></i>Checked out</h3></div>
  <div class="card-body p-0"><?= View::fetch('closet/_checkouts', ['rows' => $rows, 'showOwner' => true, 'back' => $back, 'empty' => 'Nothing is checked out to this client\'s people.']) ?></div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="modal-closet-add" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/clients/<?= $cid ?>/closet/items">
      <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
      <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add to <?= e($client['name']) ?>'s closet</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><?= View::fetch('closet/_item_fields', ['i' => null, 'msp' => false, 'offices' => [], 'officeId' => null, 'pid' => 'add']) ?></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

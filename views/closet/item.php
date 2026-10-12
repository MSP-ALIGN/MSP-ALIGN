<?php
/**
 * 2.15.0 One closet item: its details, check out, change quantity, retire, who has some now, and its history.
 * @var array $i Closet::item(); ?array $client (the client whose closet it is; null = the MSP's stock); array $out
 * Closet::open(item); array $history Closet::history(); array $offices; array $contacts (the client's); array $clients
 * (MSP stock: clients a loaner can go to). Every value is escaped; forms post with CSRF (ClosetController checks roles).
 */
use Align\Auth;
use Align\Closet\Closet;
use Align\View;

if ($client) {
    require __DIR__ . '/../partials/client_header.php';
}
$canEdit = Auth::can('tech');
$id = (int) $i['id'];
$back = "/closet/items/$id";
[$catLabel, $catIcon] = Closet::CATEGORIES[$i['category']] ?? Closet::CATEGORIES['other'];
$actions = ['added' => 'Added', 'edited' => 'Edited', 'adjusted' => 'Quantity changed', 'checked_out' => 'Checked out', 'checked_in' => 'Checked in',
    'used' => 'Used up', 'retired' => 'Retired', 'restored' => 'Restored', 'requested' => 'Requested'];
?>
<nav class="small mb-1"><a href="<?= $client ? '/clients/' . (int) $client['id'] . '/closet' : '/closet' ?>"><i class="fas fa-arrow-left me-1"></i><?= $client ? 'Tech closet' : 'Your stock' ?></a></nav>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas <?= e($catIcon) ?> text-secondary me-2"></i><?= e($i['name']) ?>
    <?php if ($i['retired_at']): ?><span class="badge text-bg-secondary">retired</span><?php endif; ?>
    <?php if ($i['low']): ?><span class="badge text-bg-warning">low stock</span><?php endif; ?></h1>
  <?php if ($canEdit): ?><div class="btn-group btn-group-sm mt-2 mt-md-0">
    <button class="btn btn-default" data-bs-toggle="modal" data-bs-target="#modal-closet-edit"><i class="fas fa-pen me-1"></i>Edit</button>
    <form method="post" action="<?= e($back) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $i['retired_at'] ? 'restore' : 'retire' ?>">
      <button class="btn btn-sm btn-default" <?= $i['retired_at'] ? '' : 'data-confirm="Retire this item? It leaves the closet lists; its history is kept."' ?>><?= $i['retired_at'] ? 'Restore' : 'Retire' ?></button></form>
  </div><?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card card-dark h-100 mb-0"><div class="card-body small">
      <dl class="row mb-0">
        <dt class="col-5">Closet</dt><dd class="col-7"><?= $client ? e($client['name']) : 'Your stock' . ($i['office_name'] ? ' · ' . e($i['office_name']) : '') ?></dd>
        <dt class="col-5">Category</dt><dd class="col-7"><?= e($catLabel) ?></dd>
        <dt class="col-5">On the shelf</dt><dd class="col-7"><?= $i['kind'] === 'tracked' ? ((int) $i['quantity'] ? 'Yes' : 'No (checked out)') : (int) $i['quantity'] ?><?= $i['min_quantity'] !== null ? ' <span class="text-muted">(warn below ' . (int) $i['min_quantity'] . ')</span>' : '' ?></dd>
        <?php if ($i['kind'] === 'stock'): ?><dt class="col-5">Checked out</dt><dd class="col-7"><?= (int) $i['out'] ?></dd><?php endif; ?>
        <dt class="col-5">Where</dt><dd class="col-7"><?= e((string) $i['location']) ?: '—' ?></dd>
        <?php foreach (['manufacturer' => 'Manufacturer', 'model' => 'Model', 'serial' => 'Serial'] as $k => $label): if ($i[$k]): ?><dt class="col-5"><?= $label ?></dt><dd class="col-7"><?= e($i[$k]) ?></dd><?php endif; endforeach; ?>
        <?php if ($i['notes']): ?><dt class="col-5">Notes</dt><dd class="col-7"><?= nl2br(e($i['notes'])) ?></dd><?php endif; ?>
      </dl>
    </div></div>
  </div>
  <div class="col-lg-7">
    <?php if ($canEdit && !$i['retired_at']): ?>
      <div class="card card-dark mb-3"><div class="card-header py-2"><h3 class="card-title"><i class="fas fa-right-from-bracket me-2"></i>Check out</h3></div>
        <div class="card-body"><?php if ((int) $i['quantity'] > 0): ?>
          <?= View::fetch('closet/_checkout_form', ['action' => "/closet/items/$id/checkout", 'back' => $back, 'contacts' => $contacts, 'clients' => $clients,
            'qty' => $i['kind'] === 'stock', 'max' => (int) $i['quantity'], 'pid' => 'co']) ?>
        <?php else: ?><p class="small text-muted mb-0">None on the shelf right now.</p><?php endif; ?></div></div>
      <?php if ($i['kind'] === 'stock'): ?>
        <div class="card card-dark mb-0"><div class="card-header py-2"><h3 class="card-title"><i class="fas fa-calculator me-2"></i>Change quantity</h3></div>
          <div class="card-body"><form method="post" action="<?= e($back) ?>" class="row g-2 align-items-end small">
            <?= csrf_field() ?><input type="hidden" name="action" value="adjust">
            <div class="col-md-3"><label for="adj-q">On the shelf now</label><input id="adj-q" name="quantity" type="number" min="0" max="<?= Closet::MAX_QTY ?>" value="<?= (int) $i['quantity'] ?>" class="form-control form-control-sm"></div>
            <div class="col-md-6"><label for="adj-n">Why <span class="text-muted">(a count, a delivery, a breakage)</span></label><input id="adj-n" name="note" class="form-control form-control-sm" maxlength="300"></div>
            <div class="col-md-3"><button class="btn btn-sm btn-default w-100">Save</button></div>
          </form></div></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card card-dark mt-3">
  <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-user-clock me-2"></i>Who has it</h3></div>
  <div class="card-body p-0"><?= View::fetch('closet/_checkouts', ['rows' => $out, 'showBorrower' => !$client, 'back' => $back, 'empty' => 'Nobody has this checked out.']) ?></div>
</div>

<div class="card card-dark" id="history">
  <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-clock-rotate-left me-2"></i>History</h3></div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 small">
    <thead><tr><th>When</th><th>What</th><th>By</th></tr></thead><tbody>
    <?php foreach ($history as $h): ?>
      <tr><td class="text-nowrap"><?= e(fmt_datetime($h['at'])) ?></td><td><b><?= e($actions[$h['action']] ?? $h['action']) ?></b><?= $h['detail'] ? ' · ' . e($h['detail']) : '' ?></td>
        <td><?= $h['portal_name'] ? e($h['portal_name']) . ' <span class="badge text-bg-light border">portal</span>' : e((string) $h['user_name']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="modal-closet-edit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="<?= e($back) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit <?= e($i['name']) ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><?= View::fetch('closet/_item_fields', ['i' => $i, 'msp' => !$client, 'offices' => $offices, 'officeId' => null, 'pid' => 'edit']) ?></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

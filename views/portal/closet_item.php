<?php
use Align\Closet\Closet;

/**
 * 2.15.1 Client portal: one item of the client's own closet, to keep up to date (can_closet_edit). @var array $i
 * (id, kind, category, name, manufacturer, model, location, quantity, min_quantity, out, low: PortalClosetController leaves
 * out the serial, the notes and who created it); array $history (at, action, detail, who: a portal user's name, or
 * null for the IT provider's team). Every value is escaped; forms post with CSRF and PortalClosetController checks the item
 * is this client's.
 */
$id = (int) $i['id'];
$back = "/portal/closet/items/$id";
$actions = ['added' => 'Added', 'edited' => 'Details changed', 'adjusted' => 'Count changed', 'checked_out' => 'Checked out', 'checked_in' => 'Checked in',
    'used' => 'Used up', 'retired' => 'Removed', 'restored' => 'Brought back', 'requested' => 'Requested'];
$v = fn(string $k) => e((string) ($i[$k] ?? ''));
[$catLabel, $catIcon] = Closet::CATEGORIES[$i['category']] ?? Closet::CATEGORIES['other'];
?>
<nav class="small mb-1"><a href="/portal/closet"><i class="fas fa-arrow-left me-1"></i>Tech closet</a></nav>
<div class="d-flex flex-wrap align-items-center portal-page-head">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-fw <?= e($catIcon) ?> me-2 text-secondary"></i><?= e($i['name']) ?><?= $i['low'] ? ' <span class="badge text-bg-warning">running low</span>' : '' ?></h1>
    <div class="small text-muted"><?= e($catLabel) ?> · <?= $i['kind'] === 'tracked' ? ((int) $i['quantity'] ? 'on the shelf' : 'checked out') : (int) $i['quantity'] . ' on the shelf' . ($i['out'] ? ', ' . (int) $i['out'] . ' checked out' : '') ?></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100 mb-0">
      <div class="card-header"><b><i class="fas fa-pen me-1 text-secondary"></i>Details</b></div>
      <div class="card-body">
        <form method="post" action="<?= e($back) ?>" class="row g-2 small" id="closet-item-details"><?= csrf_field() ?><input type="hidden" name="action" value="save">
          <div class="col-md-8"><label for="ed-name">Name</label><input id="ed-name" name="name" class="form-control form-control-sm" maxlength="190" required value="<?= $v('name') ?>"></div>
          <div class="col-md-4"><label for="ed-category">Category</label><select id="ed-category" name="category" class="form-select form-select-sm">
            <?php foreach (Closet::CATEGORIES as $k => [$label]): ?><option value="<?= e($k) ?>"<?= $i['category'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label for="ed-make">Manufacturer</label><input id="ed-make" name="manufacturer" class="form-control form-control-sm" maxlength="190" value="<?= $v('manufacturer') ?>"></div>
          <div class="col-md-6"><label for="ed-model">Model</label><input id="ed-model" name="model" class="form-control form-control-sm" maxlength="190" value="<?= $v('model') ?>"></div>
          <div class="col-md-<?= $i['kind'] === 'stock' ? '8' : '12' ?>"><label for="ed-loc">Where <span class="text-muted">(shelf, bin)</span></label><input id="ed-loc" name="location" class="form-control form-control-sm" maxlength="190" value="<?= $v('location') ?>"></div>
          <?php if ($i['kind'] === 'stock'): ?><div class="col-md-4"><label for="ed-min">Warn below</label><input id="ed-min" name="min_quantity" type="number" min="0" max="<?= Closet::MAX_QTY ?>" class="form-control form-control-sm" value="<?= $v('min_quantity') ?>" placeholder="no minimum"></div><?php endif; ?>
          <div class="col-12"><button class="btn btn-sm btn-primary">Save</button></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <?php if ($i['kind'] === 'stock'): ?>
      <div class="card mb-3">
        <div class="card-header"><b><i class="fas fa-calculator me-1 text-secondary"></i>Update the count</b></div>
        <div class="card-body">
          <form method="post" action="<?= e($back) ?>" class="row g-2 align-items-end small" id="closet-item-count"><?= csrf_field() ?><input type="hidden" name="action" value="adjust">
            <div class="col-4"><label for="adj-q">On the shelf</label><input id="adj-q" name="quantity" type="number" min="0" max="<?= Closet::MAX_QTY ?>" value="<?= (int) $i['quantity'] ?>" class="form-control form-control-sm"></div>
            <div class="col-8"><label for="adj-n">Why <span class="text-muted">(a count, a delivery)</span></label><input id="adj-n" name="note" class="form-control form-control-sm" maxlength="300"></div>
            <div class="col-12"><button class="btn btn-sm btn-default">Update count</button> <span class="text-muted ms-1">Don't count what's checked out.</span></div>
          </form>
        </div>
      </div>
    <?php endif; ?>
    <div class="card mb-0">
      <div class="card-header"><b><i class="fas fa-box-archive me-1 text-secondary"></i>Gone for good?</b></div>
      <div class="card-body small">
        <?php if ($i['out']): ?><p class="text-muted mb-0">Some are checked out. Check them back in before removing this item.</p>
        <?php else: ?>
          <p class="text-muted mb-2">Remove it from your closet if it was thrown out or moved away. Its history is kept, and your IT provider can bring it back.</p>
          <form method="post" action="<?= e($back) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="retire">
            <button class="btn btn-sm btn-outline-danger" data-confirm="<?= e('Remove ' . $i['name'] . ' from your closet?') ?>">Remove</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card mt-3" id="closet-item-history">
  <div class="card-header"><b><i class="fas fa-clock-rotate-left me-1 text-secondary"></i>History</b></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0 small">
      <thead><tr><th>When</th><th>What</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr><td class="text-nowrap"><?= e(fmt_datetime($h['at'])) ?></td><td><b><?= e($actions[$h['action']] ?? $h['action']) ?></b><?= $h['detail'] ? ' · ' . e($h['detail']) : '' ?></td>
          <td><?= $h['who'] !== null ? e($h['who']) : '<span class="text-muted">Your IT provider</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

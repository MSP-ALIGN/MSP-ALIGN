<?php
use Align\Closet\Closet;

/**
 * 2.15.0 Client portal: the tech closet. @var array $items (only id, kind, category, name, manufacturer, model,
 * location, quantity, out, low, min_quantity: PortalController::closet leaves out serials, notes and staff fields);
 * array $spares; array $out (what the client's people have: from its closet, and the MSP's loaners); bool $canCheckout;
 * array $contacts (id, name, email) of this client. Every value is escaped; forms post with CSRF and PortalController
 * checks every id belongs to this client.
 */
$groups = [];
foreach ($items as $it) {
    $groups[$it['category']][] = $it;
}
$who = function (string $pid) use ($contacts): string {
    $h = '<select name="contact_id" class="form-select form-select-sm" aria-label="Who it\'s for" id="' . e($pid) . '"><option value="me">Me</option>';
    foreach ($contacts as $c) {
        $h .= '<option value="' . (int) $c['id'] . '">' . e($c['name']) . '</option>';
    }
    return $h . '</select>';
};
?>
<div class="d-flex flex-wrap align-items-center portal-page-head">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-boxes-stacked me-2 text-secondary"></i>Tech closet</h1>
    <div class="small text-muted">Spare parts and equipment kept for you, and who has what.<?= $canCheckout ? ' Check something out when you take it, and back in when it\'s returned.' : '' ?></div></div>
</div>

<div class="card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0 align-middle" id="portal-closet">
      <thead><tr><th>Item</th><th>Where</th><th class="text-end">Available</th><?php if ($canCheckout): ?><th>Check out</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$items): ?><tr><td colspan="<?= $canCheckout ? 4 : 3 ?>" class="text-center text-muted py-4">Nothing in your closet yet.</td></tr><?php endif; ?>
      <?php foreach ($groups as $cat => $rows): [$label, $icon] = Closet::CATEGORIES[$cat] ?? Closet::CATEGORIES['other']; ?>
        <tr class="proj-quarter"><th colspan="4"><i class="fas fa-fw <?= e($icon) ?> text-secondary me-1"></i><?= e($label) ?></th></tr>
        <?php foreach ($rows as $it): $id = (int) $it['id']; ?>
          <tr>
            <td><b><?= e($it['name']) ?></b><?= $it['low'] ? ' <span class="badge text-bg-warning">running low</span>' : '' ?>
              <?php if ($it['manufacturer'] || $it['model']): ?><div class="small text-muted"><?= e(trim($it['manufacturer'] . ' ' . $it['model'])) ?></div><?php endif; ?></td>
            <td class="small"><?= e((string) $it['location']) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-end"><?= $it['kind'] === 'tracked' ? ((int) $it['quantity'] ? 'Yes' : '<span class="text-muted">checked out</span>') : (int) $it['quantity'] . ($it['out'] ? ' <span class="small text-muted">(' . (int) $it['out'] . ' out)</span>' : '') ?></td>
            <?php if ($canCheckout): ?><td>
              <?php if ((int) $it['quantity'] > 0): ?>
                <form method="post" action="/portal/closet/items/<?= $id ?>/checkout" class="d-flex flex-wrap gap-1 align-items-center small"><?= csrf_field() ?>
                  <?php if ($it['kind'] === 'stock'): ?><input type="number" name="quantity" min="1" max="<?= (int) $it['quantity'] ?>" value="1" class="form-control form-control-sm" style="width:5rem" aria-label="How many"><?php endif; ?>
                  <div style="min-width:9rem"><?= $who("co-$id") ?></div>
                  <input type="date" name="due_on" class="form-control form-control-sm" style="width:9.5rem" aria-label="Due back (optional)" title="Due back (optional)">
                  <button class="btn btn-sm btn-primary">Check out</button></form>
              <?php else: ?><span class="small text-muted">None available</span><?php endif; ?>
            </td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($spares): ?>
<div class="card">
  <div class="card-header"><b><i class="fas fa-laptop me-1 text-secondary"></i>Spare computers and devices</b></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0 align-middle" id="portal-spares">
      <thead><tr><th>Device</th><th>Status</th><?php if ($canCheckout): ?><th>Check out</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($spares as $s): $id = (int) $s['id']; ?>
        <tr><td><b><?= e($s['display_name'] ?: (string) $s['system_name']) ?></b><div class="small text-muted"><?= e(trim(($s['manufacturer'] ?? '') . ' ' . ($s['model'] ?? ''))) ?></div></td>
          <td class="small"><?= $s['checkout_id'] ? 'With ' . e($s['person']) . ($s['due_on'] ? ' · due ' . e(fmt_date($s['due_on'])) : '') : 'Available' ?></td>
          <?php if ($canCheckout): ?><td><?php if (!$s['checkout_id']): ?>
            <form method="post" action="/portal/closet/devices/<?= $id ?>/checkout" class="d-flex flex-wrap gap-1 align-items-center small"><?= csrf_field() ?>
              <div style="min-width:9rem"><?= $who("sp-$id") ?></div>
              <input type="date" name="due_on" class="form-control form-control-sm" style="width:9.5rem" aria-label="Due back (optional)" title="Due back (optional)">
              <button class="btn btn-sm btn-primary">Check out</button></form>
          <?php endif; ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><b><i class="fas fa-right-from-bracket me-1 text-secondary"></i>Checked out</b></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0 align-middle" id="portal-closet-out">
      <thead><tr><th>What</th><th>Who has it</th><th>Due back</th><?php if ($canCheckout): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$out): ?><tr><td colspan="4" class="text-center text-muted py-3">Nothing is checked out.</td></tr><?php endif; ?>
      <?php foreach ($out as $c): ?>
        <tr><td><?= e((string) $c['what']) ?><?= (int) $c['quantity'] > 1 ? ' <span class="text-muted">× ' . (int) $c['quantity'] . '</span>' : '' ?><?= $c['msp'] ? ' <span class="badge text-bg-light border">loaner from us</span>' : '' ?></td>
          <td class="small"><?= e($c['person']) ?><div class="text-muted">since <?= e(fmt_date($c['out_at'])) ?></div></td>
          <td class="small"><?= $c['due_on'] ? '<span class="' . ($c['overdue'] ? 'text-danger fw-bold' : '') . '">' . e(fmt_date($c['due_on'])) . ($c['overdue'] ? ' · overdue' : '') . '</span>' : '<span class="text-muted">—</span>' ?></td>
          <?php if ($canCheckout): ?><td class="text-end text-nowrap"><?php if (!$c['msp']): ?>
            <form method="post" action="/portal/closet/checkouts/<?= (int) $c['id'] ?>/checkin" class="d-inline"><?= csrf_field() ?><input type="hidden" name="outcome" value="returned"><button class="btn btn-sm btn-default py-0">Check in</button></form>
            <?php if ($c['item_id'] && ($c['item_kind'] ?? '') !== 'tracked'): ?><form method="post" action="/portal/closet/checkouts/<?= (int) $c['id'] ?>/checkin" class="d-inline"><?= csrf_field() ?><input type="hidden" name="outcome" value="used"><button class="btn btn-sm btn-link py-0" data-confirm="Mark it used up? It won't go back in the closet.">Used up</button></form><?php endif; ?>
          <?php else: ?><span class="small text-muted">Return it to us</span><?php endif; ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canCheckout): ?>
<div class="card" id="closet-request">
  <div class="card-header"><b><i class="fas fa-cart-shopping me-1 text-secondary"></i>Need something?</b></div>
  <div class="card-body">
    <form method="post" action="/portal/closet/request" class="row g-2 align-items-end small"><?= csrf_field() ?>
      <div class="col-md-4"><label for="rq-what">What you need</label><input id="rq-what" name="what" class="form-control form-control-sm" maxlength="190" required placeholder="e.g. 2 USB headsets"></div>
      <div class="col-md-2"><label for="rq-qty">How many</label><input id="rq-qty" name="quantity" type="number" min="1" max="1000" class="form-control form-control-sm"></div>
      <div class="col-md-4"><label for="rq-note">Note <span class="text-muted">(optional)</span></label><input id="rq-note" name="note" class="form-control form-control-sm" maxlength="500"></div>
      <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Send request</button></div>
      <div class="col-12 text-muted">Goes to your IT provider as a request. They'll be in touch.</div>
    </form>
  </div>
</div>
<?php endif; ?>

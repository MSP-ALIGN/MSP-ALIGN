<?php
/**
 * 2.15.0 Open checkouts with Check in / Used up buttons (techs and admins). @var array $rows Closet::open();
 * bool $showOwner (show whose closet it came from); bool $showBorrower (show the borrowing client); string $back the
 * page to return to; string $empty. Every value is escaped; buttons post with CSRF to ClosetController::checkin.
 */
use Align\Auth;

$canEdit = Auth::can('tech');
$showOwner = $showOwner ?? false;
$showBorrower = $showBorrower ?? false;
?>
<div class="table-responsive">
  <table class="table table-sm mb-0 closet-out">
    <thead><tr><th>What</th><?php if ($showOwner): ?><th>From</th><?php endif; ?><th>Who has it</th><th>Since</th><th>Due</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3"><?= e($empty) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><?php if ($c['item_id']): ?><a href="/closet/items/<?= (int) $c['item_id'] ?>"><?= e((string) $c['what']) ?></a><?php else: ?><a href="/devices/<?= (int) $c['device_id'] ?>"><?= e((string) $c['what']) ?></a> <span class="badge text-bg-light border">spare device</span><?php endif; ?>
          <?= (int) $c['quantity'] > 1 ? ' <span class="text-muted">× ' . (int) $c['quantity'] . '</span>' : '' ?>
          <?php if ($c['note']): ?><div class="small text-muted"><?= e($c['note']) ?></div><?php endif; ?></td>
        <?php if ($showOwner): ?><td class="small"><?= $c['msp'] ? 'Your stock' . ($c['office_name'] ? ' · ' . e($c['office_name']) : '') : e((string) $c['owner_name']) ?></td><?php endif; ?>
        <td class="small"><?= e($c['person']) ?><?php if ($showBorrower && $c['borrower_name']): ?><div class="text-muted"><?= e($c['borrower_name']) ?></div><?php endif; ?></td>
        <td class="small text-nowrap"><?= e(fmt_date($c['out_at'])) ?></td>
        <td class="small text-nowrap"><?php if ($c['due_on']): ?><span class="<?= $c['overdue'] ? 'text-danger fw-bold' : '' ?>"><?= e(fmt_date($c['due_on'])) ?><?= $c['overdue'] ? ' · overdue' : '' ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <?php if ($canEdit): ?><td class="text-end text-nowrap">
          <form method="post" action="/closet/checkouts/<?= (int) $c['id'] ?>/checkin" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="outcome" value="returned"><button class="btn btn-sm btn-default py-0" title="Back on the shelf">Check in</button></form>
          <?php if (($c['item_kind'] ?? '') !== 'tracked'): // one item comes back, or is retired ?><form method="post" action="/closet/checkouts/<?= (int) $c['id'] ?>/checkin" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="outcome" value="used"><button class="btn btn-sm btn-link py-0" data-confirm="<?= $c['device_id'] ? 'Keep it as their device? It goes back in use (no longer a spare).' : 'Mark it used up? It won\'t go back on the shelf.' ?>" title="<?= $c['device_id'] ? 'They keep it: back in use' : 'Used up: not coming back' ?>"><?= $c['device_id'] ? 'Kept' : 'Used up' ?></button></form><?php endif; ?>
        </td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

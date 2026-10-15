<?php
/**
 * 2.23.0 Your Outlook calendar (Calendar\Outlook): connect, its state, sync now, disconnect. Used on Your account and
 * the Calendar page. @var ?array $conn the signed-in person's connection; bool $available; string $return (/account or
 * /calendar). Values are escaped; forms post with CSRF to CalendarController.
 */
$ret = $return === '/calendar' ? '/calendar' : '/account';
?>
<?php if (!$conn && !$available): ?>
  <p class="mb-0 text-muted"><?= e(\Align\Calendar\Outlook::unavailableReason()) ?></p>
<?php elseif (!$conn): ?>
  <p>Connect your own Outlook calendar (sign in with your work account, the same email as your Align account): your Outlook events show on Align's calendar for the team, and meetings you own in Align go into your Outlook and stay up to date. Private events show only as Busy.</p>
  <a class="btn btn-sm btn-primary" href="/account/calendar/connect?return=<?= e($ret) ?>"><i class="fab fa-microsoft me-1"></i>Connect my Outlook calendar</a>
<?php else: ?>
  <div class="d-flex align-items-start mb-2">
    <i class="fas <?= $conn['status'] === 'connected' && !$conn['error'] ? 'fa-circle-check text-success' : 'fa-triangle-exclamation text-warning' ?> me-2 mt-1"></i>
    <div>Connected as <b><?= e($conn['account']) ?></b><?= $conn['synced_at'] ? ' · synced ' . e(rel_time($conn['synced_at'])) : ' · not synced yet' ?>.
      <?php if ($conn['error']): ?><div class="text-danger small"><?= e($conn['error']) ?></div><?php endif; ?></div>
  </div>
  <p class="small text-muted mb-2">Change Align meetings in Align: a copy moved or renamed in Outlook is set back on the next sync.</p>
  <?php if ($conn['status'] !== 'connected'): ?>
    <a class="btn btn-xs btn-primary" href="/account/calendar/connect?return=<?= e($ret) ?>">Connect again</a>
  <?php else: ?>
    <form method="post" action="/account/calendar/sync" class="d-inline"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($ret) ?>"><button class="btn btn-xs btn-default"><i class="fas fa-rotate me-1"></i>Sync now</button></form>
  <?php endif; ?>
  <form method="post" action="/account/calendar/disconnect" class="d-inline"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($ret) ?>">
    <button class="btn btn-xs btn-outline-danger" data-confirm="Disconnect your Outlook calendar? Align's copies of your meetings are removed from Outlook, and your Outlook events no longer show in Align." data-confirm-ok="Disconnect">Disconnect</button></form>
<?php endif; ?>

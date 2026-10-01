<?php
/**
 * Remembered browsers (1.45.1) on the Account page: staff and client portal.
 * @var string $kind 'staff'|'portal'; int $uid; string $action (POST url)
 */
$rows = \Align\Remember::list($kind, $uid);
$days = \Align\Remember::days();
if (!$rows && $days === 0) {
    return;
}
?>
<div class="mt-3 pt-3 border-top">
  <h4 class="h6 mb-1">Remembered browsers</h4>
  <?php if (!$rows): ?>
    <p class="small text-muted mb-0">None. After entering your code you can tick <b>Remember this browser</b><?= $days ? ' to skip the code on it for ' . (int) $days . ' days' : '' ?>; your password is still asked for.</p>
  <?php else: ?>
    <p class="small text-muted mb-2">These skip the code at sign-in (your password is still asked for). Changing your password or authenticator forgets them all. The id matches the audit log.</p>
    <ul class="list-unstyled small mb-2">
      <?php foreach ($rows as $r): ?>
        <li class="d-flex align-items-center py-1 border-bottom">
          <span class="me-auto"><b><?= e(\Align\Remember::label($r['user_agent'])) ?></b> <span class="text-muted">#<?= (int) $r['id'] ?></span><?= $r['this'] ? ' <span class="badge text-bg-light border">this browser</span>' : '' ?>
            <span class="text-muted d-block">Last used <?= e(rel_time($r['last_used_at'] ?? $r['created_at'])) ?><?= $r['ip'] ? ' from ' . e($r['ip']) : '' ?> · until <?= e(fmt_date($r['expires_at'])) ?></span></span>
          <form method="post" action="<?= e($action) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-xs btn-default">Forget</button></form>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($rows) > 1): ?><form method="post" action="<?= e($action) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="all"><button class="btn btn-sm btn-default" data-confirm="Forget all your remembered browsers? Each one asks for a two-factor code at its next sign-in." data-confirm-ok="Forget all">Forget all</button></form><?php endif; ?>
  <?php endif; ?>
</div>

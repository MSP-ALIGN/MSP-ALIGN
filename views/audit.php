<?php if ($chain['ok']): ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-link text-success mr-1"></i><b>Tamper check passed.</b> All <?= number_format($chain['checked']) ?> entries are intact (each is sealed with a hash of the one before it, so edits or deletions would show here). Entries are kept <?= \Align\AuditChain::RETENTION_YEARS ?> years.</div>
<?php else: ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation mr-1"></i><b>The audit log has been altered.</b> The check failed at entry #<?= (int) $chain['broken_at'] ?>: <?= e($chain['reason']) ?>. Treat this as a security incident: preserve the server and backups, and review who has database access.</div>
<?php endif; ?>
<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left mr-2"></i>Audit log</h3></div>
  <div class="card-body p-0">
    <table class="table table-sm table-striped table-borderless mb-0">
      <thead class="text-dark"><tr><th>When</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap small"><?= e(date('M j, Y g:i a', strtotime($r['created_at']))) ?></td>
          <td class="small"><?php if ($r['portal_user_id']): ?><?= e($r['portal_name'] ?? 'Deleted portal user') ?> <span class="badge badge-light border" title="Client portal user<?= $r['portal_client'] ? ' at ' . e($r['portal_client']) : '' ?>">client<?= $r['portal_client'] ? ' · ' . e($r['portal_client']) : '' ?></span><?php else: ?><?= e($r['user_name'] ?? '—') ?><?php endif; ?></td>
          <td><code><?= e($r['action']) ?></code></td>
          <td class="small text-break"><?= e($r['detail']) ?></td>
          <td class="small text-muted"><?= e($r['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-muted p-3">Nothing logged yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-default" href="/audit?page=<?= $page - 1 ?>">Newer</a><?php endif; ?>
    <?php if ($hasMore): ?><a class="btn btn-sm btn-default" href="/audit?page=<?= $page + 1 ?>">Older</a><?php endif; ?>
  </div>
</div>

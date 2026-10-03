<?php
/**
 * Audit log (admins). Vars: $rows, $page, $hasMore, $filters [q, user, group], $users, $chain (AuditChain::quick()).
 * Security: every entry field is escaped (details are free text from many sources, including portal users and
 * synced data); paging links are built with http_build_query and escaped.
 */
?><?php if ($chain['ok']): ?>
  <div class="alert alert-light border small py-2 d-flex align-items-center flex-wrap"><span class="me-auto"><i class="fas fa-link text-success me-1"></i><b>Tamper check passed.</b> All <?= num($chain['checked']) ?> entries are intact (each is sealed with a hash of the one before it, so edits or deletions would show here).
    <?= empty($chain['full']) && !empty($chain['at']) ? 'Whole log last checked ' . e(rel_time($chain['at'])) . '; entries since then checked just now.' : '' ?> Entries are kept <?= \Align\AuditChain::RETENTION_YEARS ?> years.</span>
    <form method="post" action="/audit/verify" class="ms-2"><?= csrf_field() ?><button class="btn btn-xs btn-default">Check the whole log</button></form></div>
<?php else: ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation me-1"></i><b>The audit log has been altered.</b> The check failed at entry #<?= (int) $chain['broken_at'] ?>: <?= e($chain['reason']) ?>. Treat this as a security incident: preserve the server and backups, and review who has database access.</div>
<?php endif; ?>
<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left me-2"></i>Audit log</h3></div>
  <form method="get" action="/audit" class="card-body py-2 border-bottom d-flex flex-wrap align-items-center">
    <input type="search" name="q" class="form-control form-control-sm me-2 mb-1" placeholder="Search details…" value="<?= e($filters['q']) ?>" aria-label="Search">
    <select name="user" class="form-select form-select-sm me-2 mb-1 w-auto" aria-label="Staff member"><option value="0">Everyone</option><?php foreach ($users as $us): ?><option value="<?= (int) $us['id'] ?>" <?= $filters['user'] === (int) $us['id'] ? 'selected' : '' ?>><?= e($us['name']) ?></option><?php endforeach; ?></select>
    <select name="group" class="form-select form-select-sm me-2 mb-1 w-auto" aria-label="Kind of action"><option value="">All actions</option><?php foreach (\Align\Controllers\AuditController::GROUPS as $k => [$l]): ?><option value="<?= $k ?>" <?= $filters['group'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm btn-primary mb-1 me-2">Filter</button>
    <?php if ($filters['q'] !== '' || $filters['user'] || $filters['group'] !== ''): ?><a class="btn btn-sm btn-default mb-1" href="/audit">Clear</a><?php endif; ?>
  </form>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-striped table-borderless mb-0">
      <thead class="text-dark"><tr><th>When</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap small"><?= e(\Align\Fmt::dateTime($r['created_at'], 'date', ' ')) ?></td>
          <td class="small"><?php if ($r['portal_user_id']): ?><?= e($r['portal_name'] ?? 'Deleted portal user') ?> <span class="badge text-bg-light border" title="Client portal user<?= $r['portal_client'] ? ' at ' . e($r['portal_client']) : '' ?>">client<?= $r['portal_client'] ? ' · ' . e($r['portal_client']) : '' ?></span><?php else: ?><?= e($r['user_name'] ?? '—') ?><?php endif; ?></td>
          <td><code><?= e($r['action']) ?></code></td>
          <td class="small text-break"><?= e($r['detail']) ?></td>
          <td class="small text-muted"><?= e($r['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-muted p-3">Nothing matches.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-default" href="/audit?<?= e(http_build_query(array_filter($filters) + ['page' => $page - 1])) ?>">Newer</a><?php endif; ?>
    <?php if ($hasMore): ?><a class="btn btn-sm btn-default" href="/audit?<?= e(http_build_query(array_filter($filters) + ['page' => $page + 1])) ?>">Older</a><?php endif; ?>
  </div>
</div>

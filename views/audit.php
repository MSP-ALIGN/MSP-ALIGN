<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left mr-2"></i>Audit log</h3></div>
  <div class="card-body p-0">
    <table class="table table-sm table-striped table-borderless mb-0">
      <thead class="text-dark"><tr><th>When</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap small"><?= e(date('M j, Y g:i a', strtotime($r['created_at']))) ?></td>
          <td class="small"><?= e($r['user_name'] ?? '—') ?></td>
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

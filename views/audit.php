<header class="page-head"><h1>Audit log</h1></header>
<div class="card flush">
  <table class="table">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= e(date('M j, Y g:i a', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['user_name'] ?? '—') ?></td>
        <td><code><?= e($r['action']) ?></code></td>
        <td class="small wrap"><?= e($r['detail']) ?></td>
        <td class="small"><?= e($r['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Nothing logged yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="row">
  <?php if ($page > 1): ?><a class="btn" href="/audit?page=<?= $page - 1 ?>">Newer</a><?php endif; ?>
  <?php if ($hasMore): ?><a class="btn" href="/audit?page=<?= $page + 1 ?>">Older</a><?php endif; ?>
</p>

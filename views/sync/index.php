<?php use Align\Auth; ?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-rotate mr-2"></i>Sync history</h3>
    <div class="card-tools">
      <?php if (Auth::can('tech')): ?>
        <form method="post" action="/sync" class="d-inline"><?= csrf_field() ?>
          <button class="btn btn-sm btn-primary" <?= $running ? 'disabled' : '' ?>><i class="fas fa-rotate <?= $running ? 'fa-spin' : '' ?> mr-1"></i><?= $running ? 'Sync running…' : 'Run sync now' ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body py-2 small text-muted border-bottom">Runs every hour: ITFlow clients and assets, NinjaOne organizations and devices, then warranty lookups.</div>
  <div class="card-body p-0">
    <table class="table table-sm table-striped table-borderless table-hover mb-0">
      <thead class="text-dark"><tr><th>#</th><th>Started</th><th>Duration</th><th>Trigger</th><th>Status</th><th>Summary</th></tr></thead>
      <tbody>
      <?php foreach ($runs as $r):
          $status = $r['status'] === 'running' && !$running ? 'interrupted' : $r['status'];
          $tone = ['success' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'interrupted' => 'danger'][$status] ?? 'info';
          $sum = json_decode((string) $r['summary'], true) ?: [];
          $dur = $r['finished_at'] ? strtotime($r['finished_at']) - strtotime($r['started_at']) : null;
          ?>
        <tr>
          <td><a href="/sync/<?= (int) $r['id'] ?>"><?= (int) $r['id'] ?></a></td>
          <td><?= e(rel_time($r['started_at'])) ?></td>
          <td><?= $dur !== null ? ($dur >= 60 ? intdiv($dur, 60) . 'm ' : '') . ($dur % 60) . 's' : '—' ?></td>
          <td><?= e($r['triggered_by']) ?><?= $r['user_name'] ? ' · ' . e($r['user_name']) : '' ?></td>
          <td><span class="badge badge-<?= $tone ?>"><?= e($status) ?></span></td>
          <td class="small"><?= e(implode(' · ', array_filter([$sum['NinjaOne devices'] ?? null, $sum['ITFlow clients'] ?? null, $sum['Warranty lookups'] ?? null]))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$runs): ?><tr><td colspan="6" class="text-muted p-3">No syncs yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

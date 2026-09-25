<?php use Align\Auth; ?>
<header class="page-head">
  <div>
    <h1>Sync</h1>
    <p class="muted">Runs automatically every hour. Pulls ITFlow clients and assets, NinjaOne organizations and devices, then looks up warranties.</p>
  </div>
  <?php if (Auth::can('tech')): ?>
    <form method="post" action="/sync"><?= csrf_field() ?>
      <button class="btn primary" <?= $running ? 'disabled' : '' ?>><?= $running ? 'Sync running…' : 'Run sync now' ?></button>
    </form>
  <?php endif; ?>
</header>

<div class="card flush">
  <table class="table">
    <thead><tr><th>#</th><th>Started</th><th>Duration</th><th>Trigger</th><th>Status</th><th>Summary</th></tr></thead>
    <tbody>
    <?php foreach ($runs as $r):
        $status = $r['status'] === 'running' && !$running ? 'interrupted' : $r['status'];
        $tone = ['success' => 'ok', 'partial' => 'warn', 'failed' => 'bad', 'interrupted' => 'bad'][$status] ?? 'muted';
        $sum = json_decode((string) $r['summary'], true) ?: [];
        $dur = $r['finished_at'] ? strtotime($r['finished_at']) - strtotime($r['started_at']) : null;
        ?>
      <tr>
        <td><a href="/sync/<?= (int) $r['id'] ?>"><?= (int) $r['id'] ?></a></td>
        <td><?= e(rel_time($r['started_at'])) ?></td>
        <td><?= $dur !== null ? ($dur >= 60 ? intdiv($dur, 60) . 'm ' : '') . ($dur % 60) . 's' : '—' ?></td>
        <td><?= e($r['triggered_by']) ?><?= $r['user_name'] ? ' · ' . e($r['user_name']) : '' ?></td>
        <td><span class="badge tone-<?= $tone ?>"><?= e($status) ?></span></td>
        <td class="small"><?= e(implode(' · ', array_filter([$sum['NinjaOne devices'] ?? null, $sum['ITFlow clients'] ?? null, $sum['Warranty lookups'] ?? null]))) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$runs): ?><tr><td colspan="6" class="muted">No syncs yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

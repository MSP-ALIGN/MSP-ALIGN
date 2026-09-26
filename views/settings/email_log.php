<?php
use Align\Mail\Notifications as N;

/** @var array $rows, $stats; string $status */
$tone = ['sent' => 'success', 'queued' => 'warning', 'sending' => 'info', 'failed' => 'danger', 'cancelled' => 'secondary'];
?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'notifications']) ?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h2 class="h5 mb-0 mr-auto"><a href="/settings/notifications">Notifications</a> / Email log</h2>
  <div class="btn-group btn-group-sm mr-2">
    <?php foreach (['' => 'All', 'queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed'] as $k => $l): ?><a class="btn btn-<?= $status === $k ? 'secondary' : 'default' ?>" href="/settings/notifications/log<?= $k ? '?status=' . $k : '' ?>"><?= $l ?></a><?php endforeach; ?>
  </div>
  <form method="post" action="/settings/notifications/run" class="mr-2"><?= csrf_field() ?><button class="btn btn-sm btn-primary" <?= $stats['queued'] ? '' : 'disabled' ?>><i class="fas fa-paper-plane mr-1"></i>Send queued now</button></form>
  <a class="btn btn-sm btn-default" href="/integrations/email"><i class="fas fa-plug mr-1"></i>Mail connection</a>
</div>
<div class="card card-dark">
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm table-striped table-borderless mb-0">
      <thead class="text-dark"><tr><th>Status</th><th>Type</th><th>To</th><th>Subject</th><th>Created</th><th>Sent</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $to = json_decode($r['recipients'], true) ?: []; ?>
        <tr>
          <td><span class="badge badge-<?= $tone[$r['status']] ?? 'secondary' ?>"><?= e(ucfirst($r['status'])) ?></span><?= $r['attempts'] > 1 ? '<div class="small text-muted">' . (int) $r['attempts'] . ' tries</div>' : '' ?></td>
          <td class="small"><?= e(N::CATALOG[$r['kind']][0] ?? ucfirst(str_replace('_', ' ', $r['kind']))) ?><?= $r['client_name'] ? '<div class="text-muted">' . e($r['client_name']) . '</div>' : '' ?></td>
          <td class="small"><?= e(implode(', ', array_slice(array_column($to, 'address'), 0, 3))) ?><?= count($to) > 3 ? ' +' . (count($to) - 3) : '' ?></td>
          <td class="small"><?= e($r['subject']) ?><?php if ($r['last_error'] && $r['status'] !== 'sent'): ?><div class="text-danger"><?= e($r['last_error']) ?></div><?php endif; ?></td>
          <td class="small text-nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
          <td class="small text-nowrap"><?= $r['sent_at'] ? e(fmt_datetime($r['sent_at'])) : ($r['status'] === 'queued' ? '<span class="text-muted">next try ' . e(rel_time($r['send_after']) === 'just now' ? 'now' : fmt_time($r['send_after'])) . '</span>' : '—') ?></td>
          <td class="text-nowrap">
            <?php if ($r['status'] === 'failed' && !$r['purged']): ?><form method="post" action="/settings/notifications/log/<?= (int) $r['id'] ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><button class="btn btn-xs btn-outline-primary">Retry</button></form><?php endif; ?>
            <?php if ($r['status'] === 'queued'): ?><form method="post" action="/settings/notifications/log/<?= (int) $r['id'] ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-xs btn-outline-secondary">Cancel</button></form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-muted p-3">No email yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div>
</div>
<p class="small text-muted">The last 300 messages. Message content is kept for the retention period set on the mail connection page; the log line is kept for about a year.</p>

<?php
use Align\Meetings\Meetings;

/** @var array $meetings  @var bool $showClient */
$badge = ['scheduled' => 'primary', 'completed' => 'success', 'cancelled' => 'secondary'];
?>
<table class="table table-sm table-striped table-borderless table-hover mb-0" id="meeting-table">
  <thead class="text-dark"><tr><th>When</th><?php if ($showClient): ?><th>Client</th><?php endif; ?><th>Meeting</th><th>Type</th><th>Owner</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($meetings as $m): ?>
    <tr>
      <td class="text-nowrap"><a href="/meetings/<?= (int) $m['id'] ?>"><?= e(date('D M j, Y', strtotime($m['starts_at']))) ?></a><div class="small text-muted"><?= e(fmt_time($m['starts_at'])) ?>–<?= e(fmt_time($m['ends_at'])) ?></div></td>
      <?php if ($showClient): ?><td><?= $m['client_id'] ? '<a href="/clients/' . (int) $m['client_id'] . '/meetings">' . e($m['client_name']) . '</a>' : '<span class="text-muted">Internal</span>' ?></td><?php endif; ?>
      <td><a href="/meetings/<?= (int) $m['id'] ?>" class="font-weight-bold text-dark"><?= e($m['title']) ?></a>
        <?php if ($m['series_id']): ?><i class="fas fa-repeat text-muted small ml-1" title="Part of a series"></i><?php endif; ?>
        <?php if ($m['location'] || $m['video_url']): ?><div class="small text-muted"><?= $m['video_url'] ? '<i class="fas fa-video mr-1"></i>' : '<i class="fas fa-location-dot mr-1"></i>' ?><?= e($m['location'] ?: 'Online') ?></div><?php endif; ?></td>
      <td><span class="badge badge-<?= e(Meetings::typeColor($m['type'])) ?>"><?= e(Meetings::typeLabel($m['type'])) ?></span></td>
      <td class="small"><?= e($m['owner_name'] ?? '') ?></td>
      <td><span class="badge badge-<?= $badge[$m['status']] ?>"><?= e(ucfirst($m['status'])) ?></span>
        <?php if ($m['status'] === 'scheduled' && strtotime($m['ends_at']) < time()): ?><span class="badge badge-outline-warning">needs wrap-up</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$meetings): ?><tr><td colspan="6" class="text-muted p-3">No meetings.</td></tr><?php endif; ?>
  </tbody>
</table>

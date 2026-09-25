<?php /** @var array $d */ use Align\Lifecycle\Lifecycle; ?>
<span class="badge badge-<?= tone_class($d['status_tone']) ?>"><?= e($d['status_label']) ?></span>
<?php foreach ($d['flags'] as $f): if ($f === $d['status']) continue; ?>
  <span class="badge badge-outline-<?= tone_class(Lifecycle::STATUS[$f][1]) ?>"><?= e(Lifecycle::STATUS[$f][0]) ?></span>
<?php endforeach; ?>
<?php if ($d['stale']): ?><span class="badge badge-outline-secondary" title="No RMM check-in for a while">Stale</span><?php endif; ?>

<?php /** @var array $d */ ?>
<span class="badge tone-<?= e($d['status_tone']) ?>"><?= e($d['status_label']) ?></span>
<?php foreach ($d['flags'] as $f): if ($f === $d['status']) continue; ?>
  <span class="badge tone-<?= e(\Align\Lifecycle\Lifecycle::STATUS[$f][1]) ?> faint"><?= e(\Align\Lifecycle\Lifecycle::STATUS[$f][0]) ?></span>
<?php endforeach; ?>
<?php if ($d['stale']): ?><span class="badge tone-muted" title="No check-in for a while">Stale</span><?php endif; ?>

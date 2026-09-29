<?php
/**
 * Summary tiles (1.42): small stat cards in one row; a tile with a link filters the list below it.
 * @var list<array{label:string, value:int|string, tone?:string, href?:?string, active?:bool, title?:string}> $tiles
 */
?>
<div class="row stat-tiles">
  <?php foreach ($tiles as $t): $tone = $t['tone'] ?? 'dark'; $tag = !empty($t['href']) ? 'a' : 'div'; ?>
    <div class="col-6 col-md">
      <<?= $tag ?> class="card card-body stat-tile mb-3<?= !empty($t['active']) ? ' active' : '' ?>"<?= $tag === 'a' ? ' href="' . e($t['href']) . '"' : '' ?><?= !empty($t['title']) ? ' title="' . e($t['title']) . '"' : '' ?>>
        <span class="stat-label"><?= e($t['label']) ?></span>
        <span class="stat-value text-<?= e(tone_class($tone)) ?>"><?= e(is_int($t['value']) ? num($t['value']) : (string) $t['value']) ?></span>
      </<?= $tag ?>>
    </div>
  <?php endforeach; ?>
</div>

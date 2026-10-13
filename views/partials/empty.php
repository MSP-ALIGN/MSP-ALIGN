<?php
/**
 * 2.17.0 The one empty state: an icon, one sentence saying what's missing, and what to do about it.
 * @var string $text (plain text); ?string $icon (a Font Awesome name, default fa-inbox); ?string $action (HTML: a
 *      button or link, already escaped); ?string $more (plain text, a second, quieter line)
 */
?>
<div class="empty-state">
  <i class="fas <?= e($icon ?? 'fa-inbox') ?> empty-state-icon" aria-hidden="true"></i>
  <div class="empty-state-text"><?= e($text) ?></div>
  <?php if (!empty($more)): ?><div class="empty-state-more"><?= e($more) ?></div><?php endif; ?>
  <?php if (!empty($action)): ?><div class="empty-state-action"><?= $action ?></div><?php endif; ?>
</div>

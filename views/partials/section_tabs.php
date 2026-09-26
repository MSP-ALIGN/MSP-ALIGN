<?php
/** Page-level tabs for sections with more than one view. @var array $tabs [[href, label, icon, active(bool), visible(bool)?]] */
?>
<ul class="nav nav-tabs settings-tabs mb-3">
  <?php foreach ($tabs as $t): if (isset($t[4]) && !$t[4]) continue; ?>
    <li class="nav-item"><a class="nav-link<?= $t[3] ? ' active' : '' ?>" href="<?= e($t[0]) ?>"<?= $t[3] ? ' aria-current="page"' : '' ?>><i class="fas <?= e($t[2]) ?> fa-fw mr-1"></i><?= e($t[1]) ?></a></li>
  <?php endforeach; ?>
</ul>

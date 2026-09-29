<?php
/**
 * The list toolbar every list shares (1.42), one row: view tabs on the left, then search and filter menus.
 * Search runs on the server (Enter) and filters the rows already shown as you type.
 * @var list<array{0:string,1:string,2:bool,3?:int|string|null}> $tabs  [label, href, active, count]
 * @var ?array{action:string,value:string,hidden?:array,table?:string,placeholder?:string} $search
 * @var string[] $menus HTML (dropdowns, buttons) shown after the search box
 */
$tabs = $tabs ?? [];
$menus = $menus ?? [];
$search = $search ?? null;
?>
<div class="card-header list-toolbar d-flex flex-wrap align-items-center">
  <ul class="nav nav-pills view-tabs mr-auto">
    <?php foreach ($tabs as $t): ?>
      <li class="nav-item"><a class="nav-link<?= $t[2] ? ' active' : '' ?>" href="<?= e($t[1]) ?>"><?= e($t[0]) ?><?php if (isset($t[3]) && $t[3] !== null && $t[3] !== ''): ?> <span class="badge <?= $t[2] ? 'badge-light' : 'badge-secondary' ?>"><?= e(is_int($t[3]) ? num($t[3]) : (string) $t[3]) ?></span><?php endif; ?></a></li>
    <?php endforeach; ?>
  </ul>
  <div class="d-flex flex-wrap align-items-center toolbar-right">
    <?php if ($search): ?>
      <form method="get" action="<?= e($search['action']) ?>" class="mr-1 my-1" role="search">
        <?php foreach ($search['hidden'] ?? [] as $k => $v): if ($v === '' || $v === null) continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
        <input type="search" name="q" value="<?= e($search['value']) ?>" class="form-control form-control-sm list-search" placeholder="<?= e($search['placeholder'] ?? 'Search all rows') ?>" aria-label="Search"<?= !empty($search['table']) ? ' data-filter-table="' . e($search['table']) . '"' : '' ?>>
      </form>
    <?php endif; ?>
    <?php foreach ($menus as $m) echo $m; ?>
  </div>
</div>

<?php
/**
 * (2.36.0) A portal list's search box: filters the rows of the element with id $target as the person types (app.js,
 * data-filter-table; nothing is sent to the server). With $none true, prints the "nothing matches" line instead, to
 * place under the list. Every value is escaped.
 * @var string $target the list's element id; ?string $placeholder; bool $none
 */
$target = (string) $target;
if (!empty($none)): ?>
<p id="<?= e($target) ?>-none" class="small text-muted px-3 py-2 mb-0" hidden>Nothing matches your search.</p>
<?php else: ?>
<input type="search" class="form-control form-control-sm portal-search" data-filter-table="<?= e($target) ?>" data-filter-empty="<?= e($target) ?>-none"
  placeholder="<?= e($placeholder ?? 'Search') ?>" aria-label="<?= e($placeholder ?? 'Search') ?>">
<?php endif;

<?php
/** @var array $items; int $all; array $by (count per category); string $show */
use Align\Workflow\Todo;

echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-list-check', 'title' => 'To do', 'count' => $all,
    'desc' => 'Everything waiting on your team, from every client. Each item leaves the list by itself once the work is done.',
]);
$tabs = [['All', '/todo', $show === '', $all]];
foreach (Todo::CATEGORIES as $k => $label) {
    if (!empty($by[$k])) {
        $tabs[] = [$label, '/todo?show=' . $k, $show === $k, $by[$k]];
    }
}
?>
<div class="card">
  <?= \Align\View::fetch('partials/toolbar', ['tabs' => $tabs]) ?>
  <ul class="list-group list-group-flush todo-list">
    <?php foreach ($items as $i): ?>
      <li class="list-group-item d-flex align-items-center" data-todo="<?= e($i['key']) ?>">
        <i class="fas fa-fw <?= e($i['icon']) ?> text-<?= e($i['tone']) ?> fa-lg mr-3"></i>
        <div class="mr-auto pr-3"><b><?= e($i['title']) ?></b><div class="small text-muted"><?= e($i['detail']) ?></div></div>
        <span class="badge badge-light border mr-3 d-none d-md-inline"><?= e(Todo::CATEGORIES[$i['category']]) ?></span>
        <a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= e($i['link']) ?>"><?= e($i['action']) ?> <i class="fas fa-arrow-right ml-1"></i></a>
      </li>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <li class="list-group-item text-center text-muted py-5"><i class="fas fa-circle-check text-success fa-2x mb-2 d-block"></i>Nothing waiting. New items show up here as syncs bring them in.</li>
    <?php endif; ?>
  </ul>
</div>

<?php
/**
 * 2.16.0 Libraries: a card for each library the signed-in role may open. @var array $cards LibraryController::cards()
 * (filtered by role). Every value is escaped; hrefs and icons are fixed in LibraryController.
 */
echo \Align\View::fetch('partials/page_header', ['icon' => 'fa-book', 'title' => 'Libraries',
    'desc' => 'The templates and lists you reuse across clients. Documents and reviews already made keep their own copy; a vendor template\'s shared details show on every client that uses it.']);
?>
<div class="row g-3" id="libraries">
  <?php foreach ($cards as $c): ?>
    <div class="col-md-6 col-xl-4">
      <a class="card h-100 mb-0 text-reset library-card" href="<?= e($c['href']) ?>" id="library-<?= e($c['key']) ?>">
        <div class="card-body">
          <div class="d-flex align-items-center mb-2">
            <i class="fas fa-fw <?= e($c['icon']) ?> fa-lg text-secondary me-2" aria-hidden="true"></i>
            <h2 class="h6 mb-0 me-auto"><?= e($c['title']) ?></h2>
            <span class="badge text-bg-light border"><?= (int) $c['count'] ?> <?= e($c['noun']) ?><?= (int) $c['count'] === 1 ? '' : 's' ?></span>
          </div>
          <p class="small text-muted mb-0"><?= e($c['desc']) ?></p>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

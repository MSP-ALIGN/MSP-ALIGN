<?php
/** Settings -> Onboarding. @var array $templates; int $days; bool $requestsOn */
$tab = 'onboarding';
require __DIR__ . '/_tabs.php';
$email = array_values(array_filter($templates, fn($t) => $t['kind'] === 'email'));
$pages = array_values(array_filter($templates, fn($t) => $t['kind'] === 'page'));
?>
<div class="row">
  <div class="col-xl-8">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-envelope-open-text mr-2"></i>Welcome email</h3></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($email as $t): ?>
          <li class="list-group-item d-flex align-items-center"><div class="mr-auto"><a href="/settings/onboarding/templates/<?= (int) $t['id'] ?>" class="font-weight-bold"><?= e($t['title']) ?></a>
            <div class="small text-muted">Subject: <?= e($t['subject']) ?></div></div><a class="btn btn-sm btn-default" href="/settings/onboarding/templates/<?= (int) $t['id'] ?>">Edit</a></li>
        <?php endforeach; ?>
      </ul>
      <div class="card-footer small text-muted py-2">Sent from a client's <b>Onboarding</b> page, where you can still change it for that client before sending.</div>
    </div>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-book-open mr-2"></i>Onboarding page</h3>
        <div class="card-tools"><form method="post" action="/settings/onboarding/templates" class="form-inline"><?= csrf_field() ?><input class="form-control form-control-sm mr-1" name="title" placeholder="New guide page title" aria-label="New page title" required><button class="btn btn-sm btn-primary"><i class="fas fa-plus"></i><span class="sr-only">Add page</span></button></form></div></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($pages as $t): ?>
          <li class="list-group-item d-flex align-items-center">
            <div class="mr-auto"><a href="/settings/onboarding/templates/<?= (int) $t['id'] ?>" class="font-weight-bold"><?= e($t['title']) ?></a>
              <?= $t['slug'] === 'intro' ? '<span class="badge badge-light border ml-1">welcome message at the top</span>' : '<span class="badge badge-light border ml-1">guide</span>' ?>
              <?= $t['is_active'] ? '' : '<span class="badge badge-secondary ml-1">hidden</span>' ?>
              <?= $t['file_stored'] ? '<span class="badge badge-info ml-1"><i class="fas fa-file-pdf mr-1"></i>' . e($t['file_name']) . '</span>' : '' ?></div>
            <a class="btn btn-sm btn-default" href="/settings/onboarding/templates/<?= (int) $t['id'] ?>">Edit</a>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="card-footer small text-muted py-2">The client sees the welcome message, then fills in their contacts, reads the guides (and confirms them), gives getting-started details and can send requests. Guides show in this order; attach a PDF to offer the full version with screenshots.</div>
    </div>
  </div>
  <div class="col-xl-4">
    <form method="post" action="/settings/onboarding" class="card">
      <?= csrf_field() ?>
      <div class="card-header py-2"><h3 class="card-title mt-1">Options</h3></div>
      <div class="card-body">
        <div class="form-group"><label for="obd">Onboarding links work for</label>
          <div class="input-group" style="max-width:200px"><input type="number" min="1" max="180" class="form-control" id="obd" name="onboarding_link_days" value="<?= (int) $days ?>"><div class="input-group-append"><span class="input-group-text">days</span></div></div></div>
        <div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="req" name="client_requests" value="1" <?= $requestsOn ? 'checked' : '' ?>><label class="custom-control-label" for="req">Online request forms (new user, user termination)</label></div>
        <small class="form-text text-muted">On the onboarding page and in the client portal (for portal users who can edit contacts). Each request becomes a ticket in <?= e(psa_name()) ?>, or an email to your company address when there's no PSA to send it to.</small>
      </div>
      <div class="card-footer text-right"><button class="btn btn-primary btn-sm">Save</button></div>
    </form>
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1">Import / export</h3></div>
      <div class="card-body small">
        <p>Move your wording between servers, or load a prepared template pack. Importing replaces templates with the same name.</p>
        <a class="btn btn-sm btn-default mb-2" href="/settings/onboarding/export"><i class="fas fa-download mr-1"></i>Export templates</a>
        <form method="post" action="/settings/onboarding/import" enctype="multipart/form-data"><?= csrf_field() ?>
          <div class="input-group input-group-sm"><div class="custom-file"><input type="file" class="custom-file-input" id="obimp" name="file" accept=".json,application/json" required><label class="custom-file-label" for="obimp">Choose .json…</label></div>
          <div class="input-group-append"><button class="btn btn-primary">Import</button></div></div></form>
      </div>
    </div>
  </div>
</div>

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
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-envelope-open-text me-2"></i>Welcome email</h3></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($email as $t): ?>
          <li class="list-group-item d-flex align-items-center"><div class="me-auto"><a href="/settings/onboarding/templates/<?= (int) $t['id'] ?>" class="fw-bold"><?= e($t['title']) ?></a>
            <div class="small text-muted">Subject: <?= e($t['subject']) ?></div></div><a class="btn btn-sm btn-default" href="/settings/onboarding/templates/<?= (int) $t['id'] ?>">Edit</a></li>
        <?php endforeach; ?>
      </ul>
      <div class="card-footer small text-muted py-2">Sent from a client's <b>Onboarding</b> page, where you can still change it for that client before sending.</div>
    </div>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-book-open me-2"></i>Onboarding page</h3>
        <div class="card-tools"><form method="post" action="/settings/onboarding/templates" class="d-flex flex-wrap align-items-center"><?= csrf_field() ?><input class="form-control form-control-sm me-1" name="title" placeholder="New guide page title" aria-label="New page title" required><button class="btn btn-sm btn-primary"><i class="fas fa-plus"></i><span class="visually-hidden">Add page</span></button></form></div></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($pages as $t): ?>
          <li class="list-group-item d-flex align-items-center">
            <div class="me-auto"><a href="/settings/onboarding/templates/<?= (int) $t['id'] ?>" class="fw-bold"><?= e($t['title']) ?></a>
              <?= $t['slug'] === 'intro' ? '<span class="badge text-bg-light border ms-1">welcome message at the top</span>' : '<span class="badge text-bg-light border ms-1">guide</span>' ?>
              <?= $t['is_active'] ? '' : '<span class="badge text-bg-secondary ms-1">hidden</span>' ?>
              <?= $t['file_stored'] ? '<span class="badge text-bg-info ms-1"><i class="fas fa-file-pdf me-1"></i>' . e($t['file_name']) . '</span>' : '' ?></div>
            <a class="btn btn-sm btn-default" href="/settings/onboarding/templates/<?= (int) $t['id'] ?>">Edit</a>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="card-footer small text-muted py-2">The client sees the welcome message, then fills in their contacts, reads the guides (and confirms them), gives getting-started details and can send requests. Guides show in this order; attach a PDF to offer the full version with screenshots.</div>
    </div>
  </div>
  <div class="col-xl-4">
    <form method="post" action="/settings/onboarding" class="card" data-unsaved data-confirm-rules="<?= e(json_encode([
        ['changed' => 'client_requests', 'is' => ['client_requests' => '0'], 'title' => 'Turn off online requests?', 'ok' => 'Save',
         'text' => 'Clients can no longer send new user and termination requests from onboarding pages or the client portal.'],
    ])) ?>">
      <?= csrf_field() ?>
      <div class="card-header py-2"><h3 class="card-title mt-1">Options</h3></div>
      <div class="card-body">
        <div class="mb-3"><label for="obd">Onboarding links work for</label>
          <div class="input-group" style="max-width:200px"><input type="number" min="1" max="180" class="form-control" id="obd" name="onboarding_link_days" value="<?= (int) $days ?>"><span class="input-group-text">days</span></div></div>
        <div class="form-check form-switch"><input type="checkbox" class="form-check-input" id="req" name="client_requests" value="1" <?= $requestsOn ? 'checked' : '' ?>><label class="form-check-label" for="req">Online request forms (new user, user termination)</label></div>
        <small class="form-text text-muted">On the onboarding page and in the client portal (for portal users who can send requests). Each request <?= psa_on() ? 'becomes a ticket in ' . e(psa_name()) . ', or an email to your company address when the client isn\'t in ' . e(psa_name()) : 'is emailed to your company address' ?>.</small>
      </div>
      <div class="card-footer text-end"><button class="btn btn-primary btn-sm">Save</button></div>
    </form>
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1">Import / export</h3></div>
      <div class="card-body small">
        <p>Move your wording between servers, or load a prepared template pack. Importing replaces templates with the same name.</p>
        <a class="btn btn-sm btn-default mb-2" href="/settings/onboarding/export"><i class="fas fa-download me-1"></i>Export templates</a>
        <form method="post" action="/settings/onboarding/import" enctype="multipart/form-data"><?= csrf_field() ?>
          <div class="input-group input-group-sm"><input type="file" class="form-control" id="obimp" name="file" accept=".json,application/json" required>
          <button class="btn btn-primary" data-confirm="Import these templates? Templates with the same name are replaced." data-confirm-danger="0" data-confirm-ok="Import">Import</button></div></form>
      </div>
    </div>
  </div>
</div>

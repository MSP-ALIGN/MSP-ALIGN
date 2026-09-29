<?php
use Align\Contacts\Contacts;
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-address-book mr-2"></i>Contacts <span class="badge badge-light ml-1"><?= count($contacts) ?></span></h3>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="contacts-table" placeholder="Search name, email, client…">
      <?php if (\Align\Auth::can('tech')): ?><a class="btn btn-sm btn-default text-nowrap" href="/clients/import?kind=contacts"><i class="fas fa-file-import mr-1"></i>Import</a><?php endif; ?>
    </div>
  </div>
  <div class="card-body py-2 border-bottom">
    <div class="btn-group btn-group-sm flex-wrap">
      <a class="btn <?= $role === '' ? 'btn-primary' : 'btn-default' ?>" href="/contacts">All</a>
      <?php foreach (Contacts::ROLES as $col => [$label]): ?><a class="btn <?= $role === $col ? 'btn-primary' : 'btn-default' ?>" href="?role=<?= $col ?>"><?= e($label) ?></a><?php endforeach; ?>
    </div>
  </div>
  <div class="card-body p-0"><?= \Align\View::fetch('contacts/_table', ['contacts' => $contacts, 'showClient' => true, 'back' => $back]) ?></div>
</div>

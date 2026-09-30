<?php
use Align\Auth;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-address-book me-2"></i>Contacts <span class="badge text-bg-light ms-1"><?= count($contacts) ?></span></h3>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm me-2 filter-input" data-filter-table="contacts-table" placeholder="Filter…">
      <?php if ($archivedCount): ?><a class="btn btn-sm btn-outline-light me-2" href="?archived=<?= $showArchived ? '0' : '1' ?>"><?= $showArchived ? 'Hide' : 'Show' ?> archived (<?= (int) $archivedCount ?>)</a><?php endif; ?>
      <?php if (Auth::can('tech')): ?><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-contact"><i class="fas fa-plus me-1"></i>Add contact</button><?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0"><?= \Align\View::fetch('contacts/_table', ['contacts' => $contacts, 'back' => $back]) ?></div>
</div>
<p class="small text-muted"><?php if (psa_on() && $client['source'] === 'psa'): ?>Contacts sync from <?= e(psa_name()) ?> every few minutes; ones archived or deleted in <?= e(psa_name()) ?> are archived here. <?php else: ?>Add contacts here or <a href="/clients/import?kind=contacts">import a CSV file</a>. <?php endif; ?>Mark decision makers and meeting invitees here in Align. Invitees can be added to a meeting in one click.</p>
<?php if (Auth::can('tech')) echo \Align\View::fetch('contacts/_modal', ['k' => null, 'cid' => $cid, 'back' => $back]); ?>

<?php
/** One client's contacts. @var array $client, $contacts; int $archivedCount; bool $showArchived; string $back */
use Align\Auth;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-address-book me-2"></i>Contacts <span class="badge text-bg-light ms-1"><?= count($contacts) ?></span></h3>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm me-2 filter-input" data-filter-table="contacts-table" placeholder="Filter…">
      <?php if ($archivedCount): ?><a class="btn btn-sm btn-default me-2" href="?archived=<?= $showArchived ? '0' : '1' ?>"><?= $showArchived ? 'Hide' : 'Show' ?> archived (<?= (int) $archivedCount ?>)</a><?php endif; ?>
      <?php if (Auth::can('tech')): ?><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-contact"><i class="fas fa-plus me-1"></i>Add contact</button><?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0"><?= \Align\View::fetch('contacts/_table', ['contacts' => $contacts, 'back' => $back]) ?></div>
</div>
<?php $pn = e(psa_name()); $linked = psa_on() && !empty($client['psa_id']); $mapLink = Auth::can('tech') ? '/mapping?show=missing' : null; ?>
<p class="small text-muted contacts-sync">
  <?php if ($linked && \Align\Contacts\Contacts::canPush($client)): ?>
    <i class="fas fa-arrows-rotate me-1"></i><b>Syncs both ways with <?= $pn ?>.</b> Contacts added, edited<?= \Align\Contacts\Contacts::canPushArchive($client) ? ', archived or restored' : '' ?> here go to <?= $pn ?> straight away, and <?= $pn ?> changes come in every few minutes. The Primary flag and location stay managed in <?= $pn ?>.
  <?php elseif ($linked): ?>
    <i class="fas fa-arrow-down me-1"></i>Contacts come in from <?= $pn ?> every few minutes; ones archived or deleted there are archived here. Two-way sync is off<?= Auth::can('admin') ? ' (<a href="/integrations/' . e(\Align\Providers\Providers::psaKey()) . '">Integrations → ' . $pn . '</a>)' : '' ?>, so contacts added or edited here stay in Align.
  <?php elseif (psa_on()): ?>
    <i class="fas fa-link-slash me-1"></i>This client isn't linked to <?= $pn ?>, so contacts added here stay in Align.<?= $mapLink ? ' <a href="' . $mapLink . '">Link it in Client mapping</a> to sync them.' : '' ?> You can also <a href="/clients/import?kind=contacts">import a CSV file</a>.
  <?php else: ?>
    Add contacts here or <a href="/clients/import?kind=contacts">import a CSV file</a>.
  <?php endif; ?>
  Mark decision makers and meeting invitees here in Align. Invitees can be added to a meeting in one click.
</p>
<?php if (Auth::can('tech')) echo \Align\View::fetch('contacts/_modal', ['k' => null, 'cid' => $cid, 'back' => $back]); ?>

<?php
/**
 * 2.6.1 A client's Connectors page (techs and admins): the client's own connections (Microsoft 365) and how it is
 * linked to the systems set up under Integrations (PSA, RMMs, backup products), with the last sync.
 * @var array $client; array $m365 M365Controller::card(); array $linked ConnectorsController::linked(); ?array $lastSync
 * Security: record names come from the PSA, RMM or backup product and are escaped; links to the PSA come from
 * Providers::psaLink() (built from its configured address). Changes happen on the pages linked from here.
 */
use Align\Auth;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$badge = ['linked' => ['Linked', 'success'], 'missing' => ['Link broken', 'danger'], 'kept' => ['Kept unlinked', 'secondary'], 'none' => ['Not linked', 'warning']];
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-plug me-2 text-secondary"></i>Connectors</h1>
    <div class="small text-muted">What <?= e($client['name']) ?> is connected to, and where its data comes from. Staff only: the client portal doesn't show this.</div></div>
</div>

<?php // The client's own connections ?>
<?= \Align\View::fetch('m365/_client', $m365 + ['client' => $client]) ?>

<?php // Links to the systems set up under Integrations (read-only here; changed on Client mapping) ?>
<div class="card card-dark" id="linked">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-link me-2"></i>Linked systems</h3>
    <div class="card-tools"><a href="/mapping" class="btn btn-tool">Client mapping</a></div>
  </div>
  <?php if (!$linked): ?>
    <div class="card-body small text-muted">No PSA, RMM or backup product is set up yet.<?= Auth::can('admin') ? ' Add them under <a href="/integrations">Integrations</a>.' : '' ?></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle small">
        <thead><tr><th>System</th><th>Status</th><th>Linked to</th><th>Details</th></tr></thead>
        <tbody>
          <?php foreach ($linked as $l): [$label, $tone] = $badge[$l['status']]; ?>
            <tr>
              <td class="text-nowrap"><i class="<?= e($l['icon']) ?> fa-fw me-1 text-secondary"></i><b><?= e($l['name']) ?></b> <span class="text-muted">· <?= e($l['kind']) ?></span></td>
              <td><span class="badge text-bg-<?= $tone ?>"><?= $label ?></span></td>
              <td><?= $l['record'] !== null ? e($l['record']) : '<span class="text-muted">—</span>' ?>
                <?php if ($l['url']): ?> <a href="<?= e($l['url']) ?>" target="_blank" rel="noopener" class="ms-1">Open <i class="fas fa-arrow-up-right-from-square fa-xs"></i></a><?php endif; ?></td>
              <td class="text-muted"><?= e($l['detail']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <div class="card-footer small text-muted">
    <?php if ($lastSync): // the hourly sync is for every client at once; Microsoft 365 has its own time in the card above ?>Last hourly sync (all clients) <?= e(rel_time($lastSync['started_at'])) ?> (<?= e($lastSync['status']) ?>) · <a href="/sync">Sync history</a> · <?php endif; ?>
    Links to RMM organizations and backup companies are changed on <a href="/mapping">Client mapping</a>.
  </div>
</div>

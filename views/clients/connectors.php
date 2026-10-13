<?php
/**
 * 2.6.1 A client's Connectors page (techs and admins); 2.16.0 everything specific to this client's connections in one
 * place: a status line, then PSA and RMM, Security (Huntress, security awareness training), Email and domains
 * (Microsoft 365, Google Workspace, email authentication, domain expiry) and Backups (backup company links, hosted
 * machines to claim). Each RMM, backup and security tool link can be changed here for this client.
 * @var array $client; array $m365 M365Controller::card(); array $gws GoogleController::card(); ?array $emailAuth
 *      EmailAuth::stored(); array $linked ConnectorsController::linked() (with choices); ?array $lastSync;
 *      array $emailDomains ConnectorsController::emailDomains(); ?array $sat SatController::data(); array $domains
 *      Rdap::forClient(); ?array $claim BackupController::claimFor(); array $status ConnectorsController::status()
 * Security: record and client names come from the PSA, RMM, backup product or security tool and are escaped; links to
 * the PSA come from Providers::psaLink() (built from its configured address). Every form posts with CSRF to a
 * controller that checks the role again; a link change goes to MappingController::save, which accepts only records
 * the provider has and returns here (a fixed pattern).
 */
use Align\Auth;
use Align\View;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$badge = ['linked' => ['Linked', 'success'], 'missing' => ['Link broken', 'danger'], 'kept' => ['Kept unlinked', 'secondary'], 'none' => ['Not linked', 'warning']];
$tone = ['ok' => 'success', 'warn' => 'warning', 'off' => 'secondary'];
$names = array_column(\Align\DB::all('SELECT id, name FROM clients'), 'name', 'id'); // for "linked to another client"
$back = "/clients/$cid/connectors";
$section = fn(string $id, string $icon, string $title, string $desc = '') => '<h2 class="h6 text-uppercase text-muted mt-4 mb-2 connectors-section" id="' . $id . '"><i class="fas fa-fw ' . $icon . ' me-1"></i>' . e($title) . '</h2>'
    . ($desc !== '' ? '<p class="small text-muted mt-n1 mb-2">' . $desc . '</p>' : '');
// The links of one section: each row with its status, and (for an RMM, backup product or security tool) a picker to change it
$links = function (array $rows) use ($badge, $cid, $names, $back): string {
    if (!$rows) {
        return '';
    }
    ob_start(); ?>
    <div class="card card-dark">
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle small connectors-links">
          <thead><tr><th>System</th><th>Status</th><th>Linked to</th><th>Details</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $l): [$label, $tn] = $badge[$l['status']]; ?>
              <tr id="link-<?= e($l['key']) ?>">
                <td class="text-nowrap"><i class="<?= e($l['icon']) ?> fa-fw me-1 text-secondary"></i><b><?= e($l['name']) ?></b> <span class="text-muted">· <?= e($l['kind']) ?></span></td>
                <td><span class="badge text-bg-<?= $tn ?>"><?= $label ?></span></td>
                <td style="min-width:16rem">
                  <?php if (isset($l['choices'])): // change this client's link (saved like Client mapping) ?>
                    <form method="post" action="/mapping" class="d-flex gap-1 align-items-center">
                      <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                      <select name="link[<?= e($l['key']) ?>][<?= $cid ?>]" class="form-select form-select-sm" aria-label="<?= e($l['name']) ?> link">
                        <option value=""<?= $l['external_id'] === null ? ' selected' : '' ?>>Not linked</option>
                        <?php if ($l['status'] === 'missing'): // the linked record is gone from the tool: kept selected, so Save doesn't unlink it by accident ?>
                          <option value="<?= e($l['external_id']) ?>" selected>(no longer in <?= e($l['name']) ?>)</option>
                        <?php endif; ?>
                        <?php foreach ($l['choices'] as $r): $other = $r['client_id'] !== null && $r['client_id'] !== $cid; ?>
                          <option value="<?= e($r['id']) ?>"<?= $l['external_id'] === $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?><?= $other ? ' (linked to ' . e((string) ($names[$r['client_id']] ?? 'another client')) . ')' : '' ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-sm btn-default text-nowrap">Save</button>
                    </form>
                  <?php else: ?>
                    <?= $l['record'] !== null ? e($l['record']) : '<span class="text-muted">—</span>' ?>
                    <?php if ($l['url']): ?> <a href="<?= e($l['url']) ?>" target="_blank" rel="noopener" class="ms-1">Open <i class="fas fa-arrow-up-right-from-square fa-xs"></i></a><?php endif; ?>
                  <?php endif; ?>
                </td>
                <td class="text-muted"><?= e($l['detail']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php return (string) ob_get_clean();
};
$byKind = fn(array $kinds) => array_values(array_filter($linked, fn($l) => in_array($l['kind'], $kinds, true)));
?>
<div class="page-head d-flex flex-wrap align-items-start mb-2">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-fw fa-plug me-1 text-secondary"></i>Connectors</h1>
    <div class="small text-muted mt-1">Everything <?= e($client['name']) ?> is connected to, in one place: change a link, connect a service or upload results here. The overview shows the results. Staff only: the client portal doesn't show this.</div></div>
  <div class="page-actions mt-1"><a class="btn btn-sm btn-default" href="/mapping"><i class="fas fa-link me-1"></i>Client mapping</a></div>
</div>

<?php // At a glance: one chip per connection ?>
<div class="d-flex flex-wrap gap-2 mb-2" id="connector-status">
  <?php foreach ($status as $c): ?>
    <a class="badge rounded-pill text-bg-light border text-decoration-none fw-normal connector-chip" href="<?= e($c['href']) ?>"><i class="fas fa-circle fa-xs text-<?= $tone[$c['tone']] ?> me-1" aria-hidden="true"></i><?= e($c['label']) ?><span class="visually-hidden"> (<?= ['ok' => 'working', 'warn' => 'needs attention', 'off' => 'not set up'][$c['tone']] ?>)</span></a>
  <?php endforeach; ?>
</div>

<?php // PSA and RMM ?>
<?php $psaRmm = $byKind(['PSA', 'RMM', 'Other']); ?>
<?= $section('links', 'fa-link', 'PSA and RMM', $psaRmm ? '' : 'No PSA or RMM is set up yet.' . (Auth::can('admin') ? ' Add them under <a href="/integrations">Integrations</a>.' : '')) ?>
<?= $links($psaRmm) ?>

<?php // Security: Huntress and security awareness training ?>
<?php $security = $byKind(['Security']); ?>
<?php if ($security || $sat !== null): ?>
  <?= $section('security', 'fa-shield-halved', 'Security') ?>
  <?= $links($security) ?>
  <?php if ($sat !== null) echo View::fetch('sat/_setup', ['client' => $client] + $sat); ?>
<?php endif; ?>

<?php // Email and domains ?>
<?= $section('email', 'fa-envelope', 'Email and domains') ?>
<?= View::fetch('m365/_client', $m365 + ['client' => $client]) ?>
<?= View::fetch('gws/_client', $gws + ['client' => $client]) // 2.6.3 ?>
<?= View::fetch('gws/_email_auth', ['client' => $client, 'ea' => $emailAuth, 'connectors' => true, 'domains' => $emailDomains]) // 2.6.3 ?>
<?= View::fetch('vendors/_domains', ['cid' => $cid, 'domains' => $domains, 'canEdit' => true, 'back' => $back]) // 2.16.0, from Vendors ?>

<?php // Backups ?>
<?php $backups = $byKind(['Backups']); ?>
<?php if ($backups || !empty($claim)): ?>
  <?= $section('backups', 'fa-database', 'Backups') ?>
  <?= $links($backups) ?>
  <?php if (!empty($claim)) echo View::fetch('backups/_claim', ['client' => $client, 'claim' => $claim]); // 2.16.0, from Backups ?>
<?php endif; ?>

<p class="small text-muted mt-3">
  <?php if ($lastSync): // the hourly sync is for every client at once; Microsoft 365 has its own time in its card ?>Last hourly sync (all clients) <?= e(rel_time($lastSync['started_at'])) ?> (<?= e($lastSync['status']) ?>) · <a href="/sync">Sync history</a> · <?php endif; ?>
  Links changed here are the same as on <a href="/mapping">Client mapping</a>, which is quicker for many clients at once.
</p>

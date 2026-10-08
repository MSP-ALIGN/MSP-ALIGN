<?php
/**
 * 2.6.1 One line about Microsoft 365 on a client's Licensing page (the connection itself is on the Connectors page,
 * techs and admins): the tenant and last sync when connected, what needs attention (an error, a tenant to confirm,
 * permissions to approve), or a pointer to connect. Viewers see the status only, without links to Connectors.
 * @var array $client; ?array $m365 client_m365 row; bool $appReady
 * Security: the tenant name and error come from Microsoft and are escaped.
 */
use Align\Auth;
use Align\M365\Tenants;

$cid = (int) $client['id'];
$tech = Auth::can('tech');
$connected = Tenants::connected($m365);
$approve = $connected && $m365['mode'] === 'msp' && !empty(\Align\M365\Security::stored($m365)['consent']); // new permissions for the client's admin to approve
$attention = $m365 && (Tenants::awaiting($m365) || ($connected && $m365['last_error']) || $approve);
$go = $tech ? ' <a href="/clients/' . $cid . '/connectors#m365">' . ($connected ? 'Connectors' : 'Connect it on Connectors') . '</a>' : '';
?>
<?php if (!$connected && !($m365 && Tenants::awaiting($m365)) && $tech && !$appReady && Auth::can('admin')): // not set up yet: say where ?>
<div class="alert alert-light border py-2 small" id="m365"><i class="fab fa-microsoft me-1"></i>Microsoft 365 subscriptions can sync here: first <a href="/integrations/microsoft-365">set up Microsoft 365 (clients)</a>, then connect the client on its Connectors page.</div>
<?php elseif ($connected || ($m365 && Tenants::awaiting($m365)) || ($tech && $appReady)): ?>
<div class="alert alert-<?= $attention ? 'warning' : 'light' ?> border py-2 small d-flex align-items-center flex-wrap gap-1" id="m365">
  <i class="fab fa-microsoft me-1"></i>
  <?php if ($connected): ?>
    <span>Microsoft 365: synced from <b><?= e($m365['tenant_name']) ?></b> <?= $m365['last_sync_at'] ? e(rel_time($m365['last_sync_at'])) : '(not yet)' ?>.
      <?= $m365['last_error'] ? '<span class="text-danger">The last sync failed.</span>' : '' ?><?= $approve ? 'The client\'s admin needs to approve new permissions.' : '' ?></span>
  <?php elseif ($m365 && Tenants::awaiting($m365)): ?>
    <span>Microsoft 365: a tenant is waiting to be confirmed.</span>
  <?php else: ?>
    <span>Microsoft 365 isn't connected: its subscriptions can sync here.</span>
  <?php endif; ?>
  <?= $go ?>
</div>
<?php endif; ?>

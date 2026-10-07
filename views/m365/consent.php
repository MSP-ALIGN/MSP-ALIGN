<?php
/**
 * 2.6.0 The page a client's admin sees after approving (or cancelling) the MSP's Microsoft 365 app, when they aren't
 * signed in to Align (public layout). @var array $r Tenants::completeConsent() result (status, message)
 * Security: no client data beyond the tenant name Microsoft returned for the tenant they just approved; escaped.
 */
$company = (string) (\Align\Settings::get('company_name') ?: 'your IT provider');
?>
<div class="card">
  <div class="card-body">
    <?php if ($r['status'] === 'error'): ?>
      <h1 class="h5"><i class="fas fa-circle-xmark text-danger me-2"></i>Not connected</h1>
      <p class="mb-0"><?= e($r['message']) ?></p>
    <?php else: ?>
      <h1 class="h5"><i class="fas fa-circle-check text-success me-2"></i>Thank you</h1>
      <p><?= e($company) ?> can now read <b><?= e($r['message']) ?></b>'s Microsoft 365 subscriptions, read-only. They'll confirm the connection on their side.</p>
      <p class="small text-muted mb-0">You can remove this access at any time in the Microsoft Entra admin center, under Enterprise applications.</p>
    <?php endif; ?>
  </div>
</div>

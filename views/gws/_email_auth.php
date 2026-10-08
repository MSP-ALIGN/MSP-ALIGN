<?php
/**
 * 2.6.3 The email authentication card (SPF, DKIM, DMARC for the client's email domain), on the client's overview and
 * its Connectors page. The domain comes from its Google Workspace or Microsoft 365 connection; checked daily.
 * @var array $client; ?array $ea Domains\EmailAuth::stored() (checks, domain, provider, at) or null when not checked
 *      in the last two days; bool $connectors (optional) true on the Connectors page (where Check now returns to)
 * Security: details hold a short, cleaned excerpt of public DNS records (EmailAuth cuts them); escaped here.
 */
use Align\Domains\EmailAuth;

$cid = (int) $client['id'];
$icon = ['pass' => 'fa-circle-check text-success', 'fail' => 'fa-circle-xmark text-danger', 'unknown' => 'fa-circle-question text-secondary'];
$names = ['email_spf' => 'SPF', 'email_dkim' => 'DKIM', 'email_dmarc' => 'DMARC'];
?>
<div class="card card-dark" id="email-auth">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-envelope-circle-check me-2"></i>Email authentication<?= $ea ? ' · ' . e($ea['domain']) : '' ?></h3>
    <?php if (\Align\Auth::can('tech')): ?>
      <div class="card-tools"><form method="post" action="/clients/<?= $cid ?>/email-auth/check" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= empty($connectors) ? 'overview' : 'connectors' ?>"><button class="btn btn-tool"><i class="fas fa-rotate me-1"></i>Check now</button></form></div>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$ea): ?>
      <p class="small text-muted mb-0">Not checked yet: the domain's SPF, DKIM and DMARC records are read from public DNS once a day.</p>
    <?php else: ?>
      <ul class="list-unstyled small mb-0">
        <?php foreach (EmailAuth::CHECKS as $k => $label): $c = $ea['checks'][$k] ?? ['status' => 'unknown', 'detail' => 'Not checked yet.']; ?>
          <li class="mb-1"><i class="fas fa-fw <?= $icon[$c['status']] ?? $icon['unknown'] ?> me-1"></i><b><?= $names[$k] ?>:</b> <span class="text-muted"><?= e($c['detail']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <p class="small text-muted mt-2 mb-0">Checked <?= e(rel_time($ea['at'])) ?>. These stop others sending email as <?= e($ea['domain']) ?>; they count in the health score's Security area.</p>
    <?php endif; ?>
  </div>
</div>

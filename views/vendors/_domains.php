<?php
/**
 * 2.10.0 A client's domains: registrar and expiry from public RDAP, with Check now and "Add as vendor" (techs and
 * admins). 2.16.0: on the client's Connectors page (moved from Vendors).
 * @var int $cid; array $domains Rdap::forClient(); bool $canEdit (Check now, Add as vendor); string $back (where the
 *      forms return); bool $manage (read-only copy on Vendors: a link to Connectors for techs)
 * Security: domain names and registrar text come from DNS and public RDAP (remote) and are escaped; the forms post with
 * CSRF to VendorController, which checks the role and the client again.
 */
use Align\Vendors\Vendors;

?>
<div class="card card-dark" id="domains">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-globe me-2"></i>Domains</h3>
    <?php if (!empty($manage) && !$canEdit): ?><div class="card-tools small pt-1"><a href="/clients/<?= $cid ?>/connectors#domains">Manage on Connectors</a></div><?php endif; ?>
    <?php if ($canEdit && $domains): ?><div class="card-tools"><form method="post" action="/clients/<?= $cid ?>/domains/check" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn btn-tool"><i class="fas fa-rotate me-1"></i>Check now</button></form></div><?php endif; ?></div>
  <?php if (!$domains): ?>
    <div class="card-body small text-muted">No domains yet. The client's email domains (<?= $canEdit ? 'under <a href="#email-auth">Email authentication</a>' : 'on its Connectors page' ?>) and its website's domain are read here, with their registrar and expiry date.</div>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-sm mb-0 small" id="domains-table">
    <thead><tr><th>Domain</th><th>Registrar</th><th>Expires</th><th class="text-muted fw-normal">Checked</th></tr></thead>
    <tbody>
    <?php foreach ($domains as $d): ?>
      <tr>
        <td class="fw-bold"><?= e($d['domain']) ?></td>
        <td><?php if ($d['vendor']): ?><i class="fas fa-store text-muted me-1" title="One of the client's vendors"></i><?= e($d['vendor']) ?><?= $d['registrar'] && Vendors::key($d['registrar']) !== Vendors::key($d['vendor']) ? '<div class="text-muted">' . e($d['registrar']) . '</div>' : '' ?>
          <?php elseif ($d['registrar']): ?><?= e($d['registrar']) ?>
            <?php if ($canEdit): ?><form method="post" action="/clients/<?= $cid ?>/vendors" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="name" value="<?= e(\Align\Domains\Rdap::shortName($d['registrar'])) ?>"><input type="hidden" name="category" value="registrar">
              <button class="btn btn-sm btn-default py-0 ms-1" title="Add <?= e(\Align\Domains\Rdap::shortName($d['registrar'])) ?> as one of the client's vendors"><i class="fas fa-plus me-1"></i>Add as vendor</button></form><?php endif; ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td class="text-nowrap"><?php if ($d['expires_on']): $u = \Align\Budget\Contracts::urgency($d['expires_on']); ?><span class="<?= $u === 'past' ? 'text-danger fw-bold' : ($u === 'soon' ? 'text-warning fw-bold' : '') ?>"><?= e(fmt_date($d['expires_on'])) ?></span><div class="text-muted"><?= e(days_from_now($d['expires_on'])) ?></div>
          <?php elseif ($d['status'] === null): ?><span class="text-muted">Not read yet</span>
          <?php else: ?><span class="text-muted" title="<?= e((string) $d['detail']) ?>"><?= $d['status'] === 'missing' ? 'Not registered' : 'Unknown' ?></span><?php if ($d['detail']): ?><div class="text-muted"><?= e($d['detail']) ?></div><?php endif; ?><?php endif; ?></td>
        <td class="text-muted text-nowrap"><?= $d['checked_at'] ? e(rel_time($d['checked_at'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <div class="card-footer small text-muted py-2">From each domain's public registration record (RDAP), read every few days and daily once expiry is close. Expiry dates also show on <a href="/renewals?days=365&amp;client=<?= $cid ?>">Renewals</a>. Whether a domain renews by itself is set at the registrar.</div>
</div>

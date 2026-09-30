<?php
use Align\Contacts\Contacts;

/**
 * The client's contacts, view-only (1.39). Changes go through a new user / termination request, or to the IT
 * team directly, so the contact list the IT provider works from stays theirs to keep.
 * @var array $pu, $contacts, $provider; bool $canRequest
 */
$tel = fn(string $n) => 'tel:' . preg_replace('/[^\d+]/', '', $n);
?>
<div class="d-flex flex-wrap align-items-center portal-page-head">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-address-book me-2 text-secondary"></i>Contacts</h1>
    <div class="small text-muted">The people at your organization your IT provider works with.</div></div>
  <?php if ($canRequest): ?><a class="btn btn-sm btn-primary mt-2 mt-md-0" href="/portal/requests"><i class="fas fa-user-plus me-1"></i>New user or termination request</a><?php endif; ?>
</div>
<div class="card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Mobile</th><th>Roles</th></tr></thead>
      <tbody>
      <?php foreach ($contacts as $k): ?>
        <tr>
          <td><b><?= e($k['name']) ?></b>
            <?php if ($k['title'] || $k['department']): ?><div class="small text-muted"><?= e(implode(' · ', array_filter([$k['title'], $k['department']]))) ?></div><?php endif; ?></td>
          <td class="small"><?= $k['email'] ? '<a href="mailto:' . e($k['email']) . '">' . e($k['email']) . '</a>' : '' ?></td>
          <td class="small text-nowrap"><?= $k['phone'] ? '<a href="' . e($tel($k['phone'])) . '">' . e(Contacts::phone($k)) . '</a>' : '' ?></td>
          <td class="small text-nowrap"><?= $k['mobile'] ? '<a href="' . e($tel($k['mobile'])) . '">' . e($k['mobile']) . '</a>' : '' ?></td>
          <td><?php foreach (Contacts::ROLES as $col => [$label, $tone]): if (!empty($k[$col])): ?><span class="badge text-bg-<?= $tone ?> me-1"><?= e($label) ?></span><?php endif; endforeach; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$contacts): ?><tr><td colspan="5" class="text-center text-muted py-4">No contacts yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="small text-muted"><i class="fas fa-circle-info me-1"></i>Something out of date?
  <?= $canRequest ? 'For someone joining or leaving, send a <a href="/portal/requests">request</a>. For anything else,' : '' ?>
  <?= $canRequest ? 'let' : 'Let' ?> <?= e($provider['company'] ?? 'your IT provider') ?> know<?php if (!empty($provider['email'])): ?> at <a href="mailto:<?= e($provider['email']) ?>"><?= e($provider['email']) ?></a><?php endif; ?><?= !empty($provider['phone']) ? ' or ' . e($provider['phone']) : '' ?> and they'll update it.</p>

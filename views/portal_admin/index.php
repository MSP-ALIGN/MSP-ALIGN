<?php /** @var array $users; string $portalUrl */ ?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <div class="mr-auto"><h1 class="h4 mb-0"><i class="fas fa-door-open mr-2 text-secondary"></i>Client portal users</h1>
    <div class="small text-muted">Everyone with a client portal sign-in. Invite and manage users from each client's <b>Client portal</b> page. Portal address: <a href="<?= e($portalUrl) ?>" target="_blank"><?= e($portalUrl) ?></a></div></div>
</div>
<div class="card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>Name</th><th>Client</th><th>Access</th><th>Status</th><th>Last sign-in</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr class="<?= $u['is_active'] ? '' : 'text-muted' ?>">
          <td><b><?= e($u['name']) ?></b><div class="small text-muted"><?= e($u['email']) ?></div></td>
          <td><a href="/clients/<?= (int) $u['client_id'] ?>/portal"><?= e($u['client_name']) ?></a></td>
          <td class="small"><?= e(implode(', ', array_filter([$u['can_roadmap'] ? 'Roadmap' : null, $u['can_budget'] ? 'Budget' : null, $u['can_devices'] ? 'Devices' : null, $u['can_documents'] ? 'Documents' : null]))) ?>
            <?= $u['can_approve'] ? '<span class="badge badge-light border">approves</span>' : '' ?><?= $u['can_contacts'] ? '<span class="badge badge-light border">contacts</span>' : '' ?></td>
          <td><span class="badge badge-<?= !$u['is_active'] ? 'secondary' : ($u['password_hash'] ? 'success' : 'info') ?>"><?= !$u['is_active'] ? 'Disabled' : ($u['password_hash'] ? 'Active' : 'Invited') ?></span><?= $u['totp_enabled'] ? ' <span class="badge badge-light border">2FA</span>' : '' ?></td>
          <td class="small"><?= $u['last_login_at'] ? e(rel_time($u['last_login_at'])) : '<span class="text-muted">never</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="5" class="text-center text-muted py-4">No client portal users yet. Open a client and choose <b>Client portal</b> to invite someone.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

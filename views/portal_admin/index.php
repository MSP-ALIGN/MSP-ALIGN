<?php /** @var array $users; string $portalUrl */ ?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-door-open me-2 text-secondary"></i>Client portal users</h1>
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
            <?= $u['can_approve'] ? '<span class="badge text-bg-light border">approves</span>' : '' ?><?= $u['can_submit'] ? '<span class="badge text-bg-light border">suggests</span>' : '' ?><?= $u['can_contacts'] ? '<span class="badge text-bg-light border">requests</span>' : '' ?></td>
          <td><span class="badge text-bg-<?= !$u['is_active'] ? 'secondary' : ($u['password_hash'] ? 'success' : 'info') ?>"><?= !$u['is_active'] ? 'Disabled' : ($u['password_hash'] ? 'Active' : 'Invited') ?></span><?= $u['totp_enabled'] ? ' <span class="badge text-bg-light border">2FA</span>' : '' ?></td>
          <td class="small"><?= $u['last_login_at'] ? e(rel_time($u['last_login_at'])) : '<span class="text-muted">never</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="5" class="text-center text-muted py-4">No client portal users yet. Open a client and choose <b>Client portal</b> to invite someone.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if (\Align\Auth::can('admin')): ?>
<form method="post" action="/portal-users/settings" class="card" data-confirm-rules="<?= e(json_encode([['changed' => 'portal_submissions', 'is' => ['portal_submissions' => '0'], 'title' => 'Turn off suggestions for every client?', 'ok' => 'Save',
    'text' => 'No client can suggest licenses or budget items from the portal until you turn it back on. Suggestions already sent stay on the list.']])) ?>">
  <?= csrf_field() ?>
  <div class="card-body py-2 d-flex flex-wrap align-items-center">
    <div class="form-check form-switch me-auto"><input type="checkbox" class="form-check-input" id="portal_submissions" name="portal_submissions" value="1" <?= \Align\Settings::get('portal_submissions', '1') === '1' ? 'checked' : '' ?>>
      <label class="form-check-label fw-normal" for="portal_submissions">Clients can suggest licenses and budget items <span class="small text-muted d-block">For portal users with <i>Suggest licenses and budget items</i>. You review each one before it's added (client's Licensing or Budget page).</span></label></div>
    <button class="btn btn-sm btn-default mt-2 mt-md-0">Save</button>
  </div>
</form>
<?php endif; ?>

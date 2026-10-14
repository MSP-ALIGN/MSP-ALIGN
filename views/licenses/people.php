<?php
/**
 * 2.19.0 Who has a license. Microsoft 365 licenses list the accounts that hold them in the client's tenant (updated
 * hourly, read-only; an account that isn't a contact yet can be added as one in a click). Every other license is
 * assigned to the client's contacts by hand (techs).
 * @var array $client, $l (Licenses::enrich), $rows (Assignments rows of this license), $choices (contacts not yet on it); bool $canAssign;
 *      ?string $assignError (Microsoft 365: why the last read of who has it failed)
 * Every value is escaped; forms post with CSRF to LicenseController::assign and ContactController::create (techs).
 */
use Align\Auth;
use Align\Licensing\Licenses;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$lid = (int) $l['id'];
$m365 = $l['source'] === 'm365';
$n = count($rows);
$seats = $l['seats'] !== null ? (int) $l['seats'] : null;
$back = "/licenses/$lid/people";
$tech = Auth::can('tech');
?>
<div class="page-head d-flex flex-wrap align-items-start mb-3">
  <div class="me-auto pe-3">
    <div class="small"><a href="/clients/<?= $cid ?>/licenses">Licensing</a> /</div>
    <h1 class="h4 mb-0"><i class="fas fa-fw fa-users text-secondary me-1"></i><?= e($l['name']) ?></h1>
    <div class="text-muted small mt-1 page-desc">
      <?= $n ?> <?= $n === 1 ? 'person has' : 'people have' ?> this license<?= $seats !== null ? ' · ' . $seats . ' seat' . ($seats === 1 ? '' : 's') . ' bought' : '' ?>
      <?php if ($seats !== null && $n > $seats): ?> · <span class="text-danger fw-semibold"><?= $n - $seats ?> more than bought</span>
      <?php elseif ($seats !== null && $n && $n < $seats): ?> · <?= $seats - $n ?> unassigned<?php endif; ?>
      · <?= e(Licenses::TYPES[$l['license_type']]) ?>
    </div>
  </div>
</div>

<?php if ($m365): ?>
  <div class="alert alert-light border small py-2"><i class="fab fa-microsoft me-1"></i>Who has this license comes from the client's Microsoft 365 tenant and updates every hour.
    Each account is matched to the contact with the same email; <?= $tech ? 'add the others as contacts below.' : 'the others aren\'t contacts yet.' ?></div>
  <?php if (!empty($assignError)): ?><div class="alert alert-warning small py-2"><i class="fas fa-triangle-exclamation me-1"></i>The last hourly read of who has which license failed, so this list may be out of date: <?= e($assignError) ?></div><?php endif; ?>
<?php elseif ($l['retired_at']): ?>
  <div class="alert alert-light border small py-2">This license is retired, so people can't be added to it.</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h2 class="card-title">People</h2></div>
  <div class="table-responsive">
    <table class="table mb-0 align-middle" id="license-people">
      <thead><tr><th>Person</th><th>Email</th><th>From</th><?php if ($canAssign): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><b><?= e($r['person']) ?></b>
            <?php if ($r['contact_archived']): ?> <span class="badge text-bg-warning" title="Archived contact: check whether the license should be removed">archived contact</span><?php endif; ?>
            <?php if ($r['source'] === 'm365' && $r['account_enabled'] !== null && !(int) $r['account_enabled']): ?> <span class="badge text-bg-warning" title="The account can't sign in but still holds the license">sign-in blocked</span><?php endif; ?>
            <?php if ($r['source'] === 'm365' && $r['contact_id'] === null): ?>
              <div class="small text-muted">Not a contact yet</div>
            <?php endif; ?></td>
          <td class="small"><?= e((string) ($r['contact_email'] ?? $r['email'] ?? '')) ?></td>
          <td class="small text-nowrap"><?= $r['source'] === 'm365' ? '<i class="fab fa-microsoft me-1"></i>Microsoft 365' : 'Added by hand' ?></td>
          <?php if ($canAssign): ?>
            <td class="text-end"><?php if ($r['source'] === 'manual'): ?>
              <form method="post" action="/licenses/<?= $lid ?>/people" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="assignment" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" data-confirm="Remove <?= e($l['name']) ?> from <?= e($r['person']) ?>?" aria-label="Remove <?= e($r['person']) ?>"><i class="fas fa-user-minus"></i></button></form>
            <?php endif; ?></td>
          <?php endif; ?>
        </tr>
        <?php if ($tech && $m365 && $r['contact_id'] === null && ($r['name'] || $r['email'])): ?>
          <tr class="table-light"><td colspan="3" class="small py-1">
            <form method="post" action="/clients/<?= $cid ?>/contacts" class="d-inline"><?= csrf_field() ?>
              <input type="hidden" name="name" value="<?= e((string) ($r['name'] ?: $r['email'])) ?>"><input type="hidden" name="email" value="<?= e((string) $r['email']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
              <button class="btn btn-sm btn-link p-0"><i class="fas fa-user-plus me-1"></i>Add <?= e((string) ($r['name'] ?: $r['email'])) ?> as a contact</button></form></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="4" class="empty-state"><?= $m365 ? 'No account in the tenant holds this license, or the tenant hasn\'t been read yet.' : 'Nobody is assigned yet.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($canAssign): ?>
    <div class="card-footer">
      <?php if ($choices): ?>
        <form method="post" action="/licenses/<?= $lid ?>/people" class="d-flex flex-wrap gap-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="add">
          <div class="flex-grow-1" style="min-width: 14rem"><label for="lp-add" class="small">Assign to contacts</label>
            <select id="lp-add" name="contact_ids[]" class="form-select form-select-sm" multiple size="<?= min(8, max(3, count($choices))) ?>">
              <?php foreach ($choices as $k): ?><option value="<?= (int) $k['id'] ?>"><?= e($k['name']) ?><?= $k['email'] ? ' · ' . e($k['email']) : '' ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">Hold Ctrl (Cmd on a Mac) to pick several.</div></div>
          <button class="btn btn-sm btn-primary mb-4"><i class="fas fa-user-plus me-1"></i>Assign</button>
        </form>
      <?php else: ?>
        <div class="small text-muted">Every current contact has this license. <a href="/clients/<?= $cid ?>/contacts">Add a contact</a> to assign it to someone else.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

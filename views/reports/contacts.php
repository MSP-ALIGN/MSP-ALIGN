<?php
use Align\Reports\Ui;

/**
 * 2.24.0 Contact review report (ReportController::renderContacts): the client's contacts with details and roles, and
 * as switched on, client portal access, licenses and review columns, then rows for people who are missing and a
 * sign-off. Every value is escaped.
 * @var array $d ContactsReport::data(); array $opt; array $brand; array $client
 */
$lic = (bool) $opt['licenses'];
$portal = (bool) $opt['portal'];
$review = (bool) $opt['review'];
$rows = $d['contacts'];
?>
<section class="rsection">
  <?= Ui::head('Your contacts', null, count($rows) . ' ' . (count($rows) === 1 ? 'person' : 'people')) ?>
  <?php if ($review): ?>
    <p class="lede">Please check each person below. Tick <b>Yes</b> if they're still with you, or <b>No</b> if they've left,
      and write any changes (new title, phone, email or role) in the last column. Add anyone who is missing at the end,
      then sign and return this page to <?= e($brand['company']) ?><?= !empty($brand['email']) ? ' (' . e($brand['email']) . ')' : '' ?>.</p>
  <?php endif; ?>
  <?php if (!$rows): ?>
    <p class="muted">No contacts are recorded yet.</p>
  <?php else: ?>
  <table class="rtable fixed compact dense">
    <?= Ui::cols(['name' => 16, 'contact' => 25, 'roles' => 11, 'portal' => $portal ? 8 : null, 'licenses' => $lic ? 12 : null, 'here' => $review ? 11 : null, 'changes' => $review ? 17 : null]) ?>
    <thead><tr><th>Name</th><th>Contact details</th><th>Roles</th><?php if ($portal): ?><th>Portal</th><?php endif; ?><?php if ($lic): ?><th>Licenses</th><?php endif; ?><?php if ($review): ?><th>Still here?</th><th>Changes</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="avoid-break">
        <td><span class="name"><?= e($r['name']) ?></span><?php if ($r['title'] !== '' || $r['department'] !== ''): ?><div class="sub"><?= e(implode(' · ', array_filter([$r['title'], $r['department']]))) ?></div><?php endif; ?></td>
        <td><?= $r['email'] !== '' ? e($r['email']) : '<span class="muted">No email</span>' ?>
          <?php $more = array_filter([$r['phone'] !== '' ? 'Phone ' . $r['phone'] : '', $r['mobile'] !== '' ? 'Mobile ' . $r['mobile'] : '', $r['location']]); if ($more): ?><div class="sub"><?= e(implode(' · ', $more)) ?></div><?php endif; ?></td>
        <td class="role-list"><?php foreach ($r['roles'] as $label): ?><span class="pill pill-muted"><?= e($label) ?></span> <?php endforeach; ?><?= $r['roles'] ? '' : '<span class="muted">—</span>' ?></td>
        <?php if ($portal): ?><td><?= $r['portal'] ? Ui::pill($r['portal'], ['Active' => 'ok', 'Invited' => 'info'][$r['portal']] ?? 'warn') : '<span class="muted">—</span>' ?></td><?php endif; ?>
        <?php if ($lic): ?><td><?= $r['licenses'] ? e(implode(', ', $r['licenses'])) : '<span class="muted">—</span>' ?></td><?php endif; ?>
        <?php if ($review): ?><td class="decide nowrap"><span class="check-box"></span> Yes <span class="check-box"></span> No</td><td></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p class="footnote">Roles: <b>Primary</b> is the main contact for <?= e($brand['company']) ?>; <b>Decision maker</b> approves projects and spending;
    <b>Billing</b> and <b>Technical</b> receive invoices and technical notices; <b>Important</b> people are kept informed of anything significant.<?php if ($portal): ?> <b>Portal</b> shows who can sign in to the client portal (<b>Invited</b>: hasn't signed in yet; <b>Invite expired</b>: the link ran out before they signed in).<?php endif; ?></p>
</section>

<?php if ($portal && $d['portalOnly']): ?>
<section class="rsection avoid-break">
  <?= Ui::head('Portal users not in your contact list', null, count($d['portalOnly']) . ' ' . (count($d['portalOnly']) === 1 ? 'person' : 'people')) ?>
  <p class="lede">These people can sign in to the client portal but aren't in the list above. Tick <b>No</b> for anyone who should no longer have access.</p>
  <table class="rtable fixed compact dense">
    <?= Ui::cols(['name' => 30, 'email' => 40, 'status' => 12, 'keep' => $review ? 18 : null]) ?>
    <thead><tr><th>Name</th><th>Email</th><th>Portal</th><?php if ($review): ?><th>Keep access?</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($d['portalOnly'] as $u): ?>
      <tr><td><span class="name"><?= e($u['name']) ?></span></td><td><?= e($u['email']) ?></td><td><?= Ui::pill($u['status'], ['Active' => 'ok', 'Invited' => 'info'][$u['status']] ?? 'warn') ?></td>
        <?php if ($review): ?><td class="decide nowrap"><span class="check-box"></span> Yes <span class="check-box"></span> No</td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php if ($review): ?>
<section class="rsection avoid-break">
  <?= Ui::head('Anyone missing?') ?>
  <p class="lede">Add new staff, or anyone we should be contacting, with their role (for example billing or technical).</p>
  <table class="rtable fixed compact review-blank">
    <?= Ui::cols(['name' => 22, 'title' => 18, 'email' => 26, 'phone' => 14, 'role' => 20]) ?>
    <thead><tr><th>Name</th><th>Title</th><th>Email</th><th>Phone</th><th>Role</th></tr></thead>
    <tbody><?php for ($i = 0; $i < 5; $i++): ?><tr><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?></tbody>
  </table>
  <table class="rtable fixed review-sign">
    <?= Ui::cols(['who' => 34, 'title' => 26, 'date' => 16, 'sign' => 24]) ?>
    <thead><tr><th>Reviewed by</th><th>Title</th><th>Date</th><th>Signature</th></tr></thead>
    <tbody><tr><td></td><td></td><td></td><td></td></tr></tbody>
  </table>
</section>
<?php endif; ?>

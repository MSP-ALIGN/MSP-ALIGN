<?php
/**
 * 2.8.0 One client's vendors. @var array $client; array $vendors Vendors::forClient(); int $retiredCount; bool $showRetired;
 *      array $templates; array $unlinked vendor name => license count (license vendors not on the list); string $back
 * Grouped by category; each vendor shows its account number, support details (its own, else its template's), the
 * licenses linked to it with their monthly cost, and the soonest renewal among them. Every value is escaped;
 * websites are linked only when they are http(s) (Vendors::url).
 */
use Align\Auth;
use Align\Vendors\Vendors;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$canEdit = Auth::can('tech');
$groups = [];
foreach ($vendors as $v) {
    $groups[$v['category']][] = $v;
}
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+90 days'));
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-store text-secondary me-2"></i>Vendors</h1>
  <div class="btn-group btn-group-sm mt-2 mt-md-0">
    <?php if ($retiredCount): ?><a class="btn btn-default" href="?retired=<?= $showRetired ? '0' : '1' ?>"><?= $showRetired ? 'Hide' : 'Show' ?> retired (<?= (int) $retiredCount ?>)</a><?php endif; ?>
    <a class="btn btn-default" href="/vendors"><i class="fas fa-layer-group me-1"></i>Templates</a>
    <?php if ($canEdit): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-vendor"><i class="fas fa-plus me-1"></i>Add vendor</button><?php endif; ?>
  </div>
</div>
<p class="small text-muted">Who the client buys from: internet, domains, phones, software, copiers. Licenses link to the vendor they're bought from<?= psa_on() && \Align\Providers\Providers::psaSupports('vendors') ? '; vendors from ' . e(psa_name()) . ' sync every few minutes' : '' ?>.</p>

<?php if ($canEdit && $unlinked): ?>
  <div class="alert alert-light border small py-2" id="unlinked-vendors"><i class="fas fa-link-slash me-1"></i>Licenses name vendors that aren't on this list:
    <?php foreach ($unlinked as $name => $n): ?>
      <form method="post" action="/clients/<?= $cid ?>/vendors" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="name" value="<?= e($name) ?>">
        <button class="btn btn-sm btn-default py-0 ms-1" title="Add <?= e($name) ?> as a vendor and link its licenses"><i class="fas fa-plus me-1"></i><?= e($name) ?> <span class="text-muted">(<?= $n ?>)</span></button></form>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card card-dark">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" id="vendors-table">
        <thead><tr><th>Vendor</th><th>Account</th><th>Support</th><th class="text-end">Licenses</th><th>Next renewal</th></tr></thead>
        <tbody>
        <?php if (!$vendors): ?><tr><td colspan="5" class="text-center text-muted py-4">No vendors yet.<?= $canEdit ? ' Add the client\'s internet provider, registrar and software vendors, from a template or by name.' : '' ?></td></tr><?php endif; ?>
        <?php foreach ($groups as $cat => $rows): [$label, $icon] = Vendors::CATEGORIES[$cat]; ?>
          <tr class="proj-quarter"><th colspan="5"><i class="fas fa-fw <?= e($icon) ?> text-secondary me-1"></i><?= e($label) ?></th></tr>
          <?php foreach ($rows as $v): ?>
            <tr class="<?= $v['retired_at'] ? 'text-muted' : '' ?>">
              <td>
                <?php if ($canEdit): ?><a href="#" class="fw-bold" data-lazy-modal="/vendors/<?= (int) $v['id'] ?>/form?back=<?= e(rawurlencode($back)) ?>" data-bs-target="#modal-vendor-<?= (int) $v['id'] ?>"><?= e($v['name']) ?></a><?php else: ?><b><?= e($v['name']) ?></b><?php endif; ?>
                <?php if ($v['source'] === 'psa'): ?><span class="badge text-bg-light border" title="Synced from <?= e(psa_name()) ?>"><?= e(psa_name()) ?></span><?php endif; ?>
                <?php if ($v['template_id']): ?><span class="badge text-bg-light border" title="Shared details from the <?= e($v['t_name']) ?> template<?= $v['inherited'] ? ': ' . e(implode(', ', str_replace('_', ' ', $v['inherited']))) : '' ?>"><i class="fas fa-layer-group me-1"></i>template</span><?php endif; ?>
                <?php if ($v['retired_at']): ?><span class="badge text-bg-secondary">retired<?= $v['retired_reason'] === 'psa' ? ' in ' . e(psa_name()) : '' ?></span><?php endif; ?>
                <?php if ($v['services']): ?><div class="small text-muted"><?= e($v['services']) ?></div><?php endif; ?>
                <?php if ($v['contact_name']): ?><div class="small text-muted"><i class="fas fa-user me-1"></i><?= e($v['contact_name']) ?></div><?php endif; ?>
              </td>
              <td class="small"><?= $v['account_number'] ? '<span class="font-monospace">' . e($v['account_number']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
              <td class="small">
                <?php if ($v['support_phone']): ?><div class="text-nowrap"><i class="fas fa-phone fa-fw text-muted me-1"></i><?= e($v['support_phone']) ?></div><?php endif; ?>
                <?php if ($v['support_email']): ?><div><i class="fas fa-envelope fa-fw text-muted me-1"></i><?= e($v['support_email']) ?></div><?php endif; ?>
                <?php if ($v['link']): ?><div class="text-truncate" style="max-width: 16rem"><i class="fas fa-arrow-up-right-from-square fa-fw text-muted me-1"></i><a href="<?= e($v['link']) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('#^https?://(www\.)?#i', '', rtrim($v['link'], '/'))) ?></a></div>
                <?php elseif ($v['website']): ?><div><?= e($v['website']) ?></div><?php endif; ?>
                <?php if ($v['hours'] || $v['sla']): ?><div class="text-muted"><?= e(implode(' · ', array_filter([$v['hours'], $v['sla'] ? 'SLA ' . $v['sla'] : null]))) ?></div><?php endif; ?>
                <?php if (!$v['support_phone'] && !$v['support_email'] && !$v['website']): ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td class="text-end small text-nowrap"><?php if ($v['licenses']): ?>
                <a href="/clients/<?= $cid ?>/licenses"><?= count($v['licenses']) ?> license<?= count($v['licenses']) === 1 ? '' : 's' ?></a>
                <?php if ($v['monthly'] > 0): ?><div class="text-muted"><?= money_exact($v['monthly']) ?>/mo</div><?php endif; ?>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
              <td class="small text-nowrap"><?php if ($v['next']): ?><span class="<?= $v['next'] < $today ? 'text-danger fw-bold' : ($v['next'] <= $soon ? 'text-warning fw-bold' : '') ?>"><?= e(fmt_date($v['next'])) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<p class="small text-muted">Next renewal is the soonest license renewal, contract end or renegotiation date among the vendor's licenses. With a template, a blank field shows the template's; edit the template on <a href="/vendors">Vendors</a> to change it for every client.<?= psa_on() && \Align\Providers\Providers::psaSupports('vendors') ? ' Vendors archived or deleted in ' . e(psa_name()) . ' are retired here.' : '' ?></p>
<?php if ($canEdit) echo \Align\View::fetch('vendors/_modal', ['v' => null, 'cid' => $cid, 'templates' => $templates, 'back' => $back]); ?>

<?php
/**
 * 2.6.3 The email authentication card (SPF, DKIM, DMARC for the client's email domains), on the client's overview and
 * its Connectors page; checked daily through public DNS.
 * 2.7.4: any number of domains (added here, besides the one a Microsoft 365 or Google Workspace connection brings in),
 * each with its own result and DKIM selectors. On the Connectors page techs manage them; the overview links there.
 * @var array $client; ?array $ea Domains\EmailAuth::stored() (checks, domain, domains, at) or null when not checked
 *      in the last two days; bool $connectors (optional) true on the Connectors page (where actions return to);
 *      array $domains (optional) ConnectorsController::emailDomains(): list and suggest, given on the Connectors page
 * Security: details hold a short, cleaned excerpt of public DNS records (EmailAuth cuts them); escaped here, as are
 * domains and selectors. Forms post with CSRF; EmailAuthController checks the role, the client and every value.
 */
use Align\Auth;
use Align\Domains\EmailAuth;

$cid = (int) $client['id'];
$tech = Auth::can('tech');
$back = empty($connectors) ? 'overview' : 'connectors';
$icon = ['pass' => 'fa-circle-check text-success', 'fail' => 'fa-circle-xmark text-danger', 'unknown' => 'fa-circle-question text-secondary'];
$names = ['email_spf' => 'SPF', 'email_dkim' => 'DKIM', 'email_dmarc' => 'DMARC'];
$per = $ea['domains'] ?? [];
$list = $domains['list'] ?? null;      // null: not managed on this page
$source = ['m365' => 'Microsoft 365', 'google' => 'Google Workspace', 'manual' => 'added here'];
?>
<div class="card card-dark" id="email-auth">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-envelope-circle-check me-2"></i>Email authentication<?= $ea && count($per) <= 1 ? ' · ' . e($ea['domain']) : '' ?></h3>
    <?php if ($tech && ($ea || $list)): ?>
      <div class="card-tools"><form method="post" action="/clients/<?= $cid ?>/email-auth/check" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= $back ?>"><button class="btn btn-tool"><i class="fas fa-rotate me-1"></i>Check now</button></form></div>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$ea): ?>
      <p class="small text-muted"><?= $list === [] ? 'Add the client\'s email domain to check its SPF, DKIM and DMARC records.' : 'Not checked yet: the SPF, DKIM and DMARC records are read from public DNS once a day.' ?></p>
    <?php else: ?>
      <?php // The result for the client: the worst of its domains for each check ?>
      <ul class="list-unstyled small mb-0">
        <?php foreach (EmailAuth::CHECKS as $k => $label): $c = $ea['checks'][$k] ?? ['status' => 'unknown', 'detail' => 'Not checked yet.']; ?>
          <li class="mb-1"><i class="fas fa-fw <?= $icon[$c['status']] ?? $icon['unknown'] ?> me-1"></i><b><?= $names[$k] ?>:</b> <span class="text-muted"><?= e($c['detail']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <p class="small text-muted mt-2 mb-2">Checked <?= e(rel_time($ea['at'])) ?> through public DNS (Cloudflare and Google). These stop others sending email as <?= e($ea['domain']) ?>; they count in the health score's Security area.</p>
    <?php endif; ?>

    <?php // Overview: a link to manage the domains on the Connectors page ?>
    <?php if ($list === null && $tech): ?>
      <a class="small" href="/clients/<?= $cid ?>/connectors#email-auth"><i class="fas fa-gear me-1"></i>Email domains and DKIM selectors</a>
    <?php endif; ?>

    <?php // Connectors page: each domain, where it comes from, its own result and DKIM selectors (techs change them) ?>
    <?php if ($list !== null && $tech): ?>
      <?php if ($list): ?>
        <div class="table-responsive"><table class="table table-sm small align-middle mb-2">
          <thead><tr><th>Domain</th><th class="text-nowrap">SPF · DKIM · DMARC</th><th>DKIM selectors <span class="fw-normal text-muted">(besides the common ones)</span></th><th></th></tr></thead>
          <tbody>
            <?php foreach ($list as $d => $info): $r = $per[$d] ?? null; ?>
              <tr<?= $info['skipped'] ? ' class="text-muted"' : '' ?>>
                <td><b><?= e($d) ?></b><div class="text-muted"><?= e($source[$info['source']] ?? '') ?><?= $info['skipped'] ? ' · left out' : '' ?></div></td>
                <td class="text-nowrap"><?php if ($info['skipped']): ?>–<?php else: foreach ($names as $k => $n): $st = $r['checks'][$k]['status'] ?? 'unknown'; ?><i class="fas fa-fw <?= $icon[$st] ?? $icon['unknown'] ?>" title="<?= $n ?>: <?= e($r['checks'][$k]['detail'] ?? 'not checked yet') ?>"></i><?php endforeach; endif; ?></td>
                <td><?php if (!$info['skipped']): ?>
                  <form method="post" action="/clients/<?= $cid ?>/email-domains/selectors" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="back" value="<?= $back ?>"><input type="hidden" name="domain" value="<?= e($d) ?>">
                    <input name="dkim_selectors" value="<?= e(implode(', ', $info['selectors'])) ?>" class="form-control form-control-sm" placeholder="e.g. mimecast20190104" aria-label="DKIM selectors for <?= e($d) ?>" maxlength="250">
                    <button class="btn btn-sm btn-default">Save</button></form>
                <?php endif; ?></td>
                <td class="text-end text-nowrap"><form method="post" action="/clients/<?= $cid ?>/email-domains/remove" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= $back ?>"><input type="hidden" name="domain" value="<?= e($d) ?>">
                  <?php if ($info['skipped']): ?><input type="hidden" name="restore" value="1"><button class="btn btn-sm btn-default">Check it again</button>
                  <?php elseif ($info['source'] === 'manual'): ?><button class="btn btn-link btn-sm text-danger p-0" data-confirm="Stop checking <?= e($d) ?>?" title="Remove"><i class="fas fa-trash"></i></button>
                  <?php else: ?><button class="btn btn-sm btn-default" data-confirm="Leave <?= e($d) ?> out of the checks? (It comes from the client's <?= e($source[$info['source']]) ?>; you can bring it back.)" data-confirm-danger="0" data-confirm-ok="Leave out">Leave out</button><?php endif; ?>
                </form></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
      <form method="post" action="/clients/<?= $cid ?>/email-domains" class="row g-2 align-items-end small"><?= csrf_field() ?><input type="hidden" name="back" value="<?= $back ?>">
        <div class="col-md-5"><label for="ea-domain-<?= $cid ?>">Add an email domain</label><input id="ea-domain-<?= $cid ?>" name="domain" class="form-control form-control-sm" placeholder="example.com" value="<?= e($domains['suggest'] ?? '') ?>" required maxlength="253"></div>
        <div class="col-md-4"><label for="ea-sel-<?= $cid ?>">DKIM selectors <span class="text-muted">(optional)</span></label><input id="ea-sel-<?= $cid ?>" name="dkim_selectors" class="form-control form-control-sm" placeholder="if it signs with its own" maxlength="250"></div>
        <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-plus me-1"></i>Add and check</button></div>
        <div class="col-12 text-muted">Any client can be checked, with or without Microsoft 365 or Google Workspace connected. DKIM is looked for at the mail service's selector, the ones you list and <?= count(EmailAuth::COMMON_SELECTORS) ?> common ones; a mail service that uses its own (a mail filter, a newsletter tool) needs it listed here. Find it in a message's headers: <code>DKIM-Signature: … s=<i>selector</i></code>.<?= !empty($domains['suggest']) ? ' Suggested from the client\'s website or contact email.' : '' ?></div>
      </form>
    <?php endif; ?>
  </div>
</div>

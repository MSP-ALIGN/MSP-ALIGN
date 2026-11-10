<?php
/**
 * 2.35.0 One ticket from the PSA (ITFlow), read live (Portal\Support::ticket): the details and the replies the client
 * may see (never internal notes). Details and replies are printed as HTML on purpose: they were cleaned by the
 * allowlist sanitizer (Docs\Html::clean) in Support::ticket, and the page's CSP allows no inline script. Everything else
 * is escaped. "Reply" goes through /portal/tickets/{id}/reply, which redirects to the PSA's own page for the ticket.
 * @var array $t
 */
?>
<div class="d-flex flex-wrap align-items-center portal-page-head">
  <div class="me-auto min-w-0"><a href="/portal/tickets<?= $t['closed'] ? '?state=closed' : '' ?>" class="small"><i class="fas fa-arrow-left me-1"></i>Tickets</a>
    <h1 class="h4 mb-0"><?= e($t['subject'] ?: '(no subject)') ?></h1>
    <div class="small text-muted"><?= e(implode(' · ', array_filter([$t['number'], $t['status'] ?: null, $t['priority'] ? $t['priority'] . ' priority' : null, $t['created_at'] ? 'opened ' . fmt_datetime($t['created_at']) : null]))) ?></div></div>
  <?php if ($t['guest_url']): ?><a class="btn btn-sm btn-primary mt-2 mt-md-0" href="/portal/tickets/<?= e(rawurlencode((string) $t['id'])) ?>/reply" target="_blank" rel="noopener"><i class="fas fa-reply me-1"></i><?= $t['closed'] ? 'View or reopen' : 'Reply' ?> <i class="fas fa-arrow-up-right-from-square ms-1 small"></i></a><?php endif; ?>
</div>
<div class="card"><div class="card-header py-2"><h3 class="card-title mt-1">Details</h3></div>
  <div class="card-body portal-doc"><?= $t['details_html'] !== '' ? $t['details_html'] : '<p class="text-muted mb-0">No details.</p>' ?></div></div>
<?php if ($t['replies'] === null): ?>
  <p class="small text-muted">Replies show in the emails your IT team sends<?= $t['guest_url'] ? ' and on the Reply page' : '' ?>.</p>
<?php else: foreach ($t['replies'] as $r): ?>
  <div class="card<?= $r['from_client'] ? ' ms-lg-5 card-outline card-secondary' : ' me-lg-5 card-outline card-primary' ?>">
    <div class="card-header py-2 small"><b><?= e($r['by'] !== '' ? $r['by'] : ($r['from_client'] ? 'Your team' : 'Your IT team')) ?></b> <span class="text-muted">· <?= e(fmt_datetime($r['at'])) ?></span></div>
    <div class="card-body portal-doc"><?= $r['html'] ?></div>
  </div>
<?php endforeach; if (!$t['replies']): ?><p class="small text-muted">No replies yet. Your IT team will reply by email, and their replies show here too.</p><?php endif; endif; ?>
<?php if ($t['guest_url']): ?><p class="small text-muted">Replying and closing happen on your IT team's own page for this ticket (it opens in a new tab), or by replying to their email.</p><?php endif; ?>

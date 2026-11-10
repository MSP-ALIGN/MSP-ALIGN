<?php
use Align\Fmt;

/**
 * 2.35.0 Quotes the PSA (ITFlow) sent to this client (Portal\Support::quotes, drafts never). "View" goes through
 * /portal/quotes/{id}/open, which checks again and redirects to the PSA's own page for the quote, where it is accepted
 * or declined, so its secret key is never in this page. Every value is escaped.
 * @var array $quotes
 */
$tone = fn(string $s) => ['accepted' => 'success', 'declined' => 'secondary', 'invoiced' => 'success', 'sent' => 'primary', 'viewed' => 'info'][strtolower($s)] ?? 'light';
?>
<div class="portal-page-head"><h1 class="h4 mb-0"><i class="fas fa-file-signature me-2 text-secondary"></i>Quotes</h1>
  <div class="small text-muted">Quotes your IT provider has sent you. Open one to read it in full and accept or decline it.</div></div>
<div class="card">
  <?php if (!$quotes): ?><?= \Align\View::fetch('partials/empty', ['icon' => 'fa-file-signature', 'text' => 'No quotes have been sent to you yet.']) ?>
  <?php else: ?>
  <div class="card-header py-2"><div class="card-tools"><?= \Align\View::fetch('portal/_search', ['target' => 'portal-quotes', 'placeholder' => 'Search number or what it\'s for']) /* (2.36.0) */ ?></div></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle" id="portal-quotes">
    <thead><tr><th>Quote</th><th>For</th><th>Date</th><th>Expires</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($quotes as $q): $open = in_array(strtolower((string) $q['status']), ['sent', 'viewed'], true); $expired = $open && $q['expire_date'] && $q['expire_date'] < date('Y-m-d'); ?>
      <tr>
        <td class="text-nowrap fw-bold"><?= e($q['number'] ?: '—') ?></td>
        <td><?= e($q['scope'] ?: '—') ?></td>
        <td class="text-nowrap"><?= $q['quote_date'] ? e(fmt_date($q['quote_date'])) : '—' ?></td>
        <td class="text-nowrap<?= $expired ? ' text-danger' : '' ?>"><?= $q['expire_date'] ? e(fmt_date($q['expire_date'])) : '—' ?></td>
        <td class="text-end text-nowrap"><?= $q['amount'] !== null ? e(($q['currency'] && $q['currency'] !== Fmt::cfg()['currency'] ? $q['currency'] . ' ' . number_format((float) $q['amount'], 2) : Fmt::money((float) $q['amount'], true))) : '—' ?></td>
        <td><span class="badge text-bg-<?= $tone((string) $q['status']) ?><?= $tone((string) $q['status']) === 'light' ? ' border' : '' ?>"><?= e($expired ? 'Expired' : $q['status']) ?></span></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm <?= $open && !$expired ? 'btn-primary' : 'btn-default' ?>" href="/portal/quotes/<?= e(rawurlencode((string) $q['psa_id'])) ?>/open" target="_blank" rel="noopener"><?= $open && !$expired ? 'View &amp; accept' : 'View' ?> <i class="fas fa-arrow-up-right-from-square ms-1 small"></i></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= \Align\View::fetch('portal/_search', ['target' => 'portal-quotes', 'none' => true]) ?>
  <?php endif; ?>
</div>
<p class="small text-muted">The list is brought up to date every hour. A quote opens on your IT provider's own page.</p>

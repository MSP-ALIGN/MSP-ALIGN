<?php
use Align\Portal\Support;

/**
 * 2.35.0 The client's tickets in the PSA (ITFlow): the ones this user may see (Portal\Support::tickets: their own,
 * or every ticket with can_all_tickets), open or closed, and the form to open one. Subjects are plain text, escaped.
 * @var array $tickets; string $state open|closed; bool $canOpen; bool $linked (the client is linked to the PSA)
 */
$draft = $_SESSION['ticket_draft'] ?? ['subject' => '', 'details' => '', 'priority' => 'Medium'];
$tone = fn(string $s) => ['New' => 'primary', 'Open' => 'info', 'On hold' => 'warning', 'Resolved' => 'success', 'Closed' => 'secondary'][$s] ?? 'light';
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-life-ring me-2 text-secondary"></i>Tickets</h1>
    <div class="small text-muted"><?= !empty($pu['can_all_tickets']) ? 'Every ticket for your company.' : 'Tickets you opened, or that your IT team opened for you.' ?></div></div>
  <?php if ($canOpen && $linked): ?><a href="#new" class="btn btn-sm btn-primary mt-2 mt-md-0"><i class="fas fa-plus me-1"></i>New ticket</a><?php endif; ?>
</div>
<ul class="nav nav-tabs mb-0">
  <li class="nav-item"><a class="nav-link<?= $state === 'open' ? ' active' : '' ?>" href="/portal/tickets">Open</a></li>
  <li class="nav-item"><a class="nav-link<?= $state === 'closed' ? ' active' : '' ?>" href="/portal/tickets?state=closed">Resolved &amp; closed</a></li>
</ul>
<div class="card border-top-0">
  <?php if (!$linked): ?><div class="card-body small text-muted">Your company isn't linked to your IT team's ticket system yet. Please email or call them.</div>
  <?php elseif (!$tickets): ?><?= \Align\View::fetch('partials/empty', ['icon' => 'fa-life-ring', 'text' => $state === 'open' ? 'No open tickets.' : 'No resolved or closed tickets in the last 12 months.']) ?>
  <?php else: ?>
  <ul class="list-group list-group-flush">
    <?php foreach ($tickets as $t): $st = Support::status($t); ?>
      <li class="list-group-item py-2 d-flex align-items-center">
        <div class="me-auto min-w-0"><a href="/portal/tickets/<?= e(rawurlencode((string) $t['id'])) ?>" class="fw-bold"><?= e($t['subject'] ?: '(no subject)') ?></a>
          <div class="small text-muted"><?= e(implode(' · ', array_filter([$t['number'], 'opened ' . fmt_date($t['created_at']), $t['priority'] ? $t['priority'] . ' priority' : null, !empty($pu['can_all_tickets']) && $t['mine'] ? 'yours' : null]))) ?></div></div>
        <span class="badge text-bg-<?= $tone($st) ?> ms-2"><?= e($st) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php if ($canOpen && $linked): ?>
<div class="card" id="new">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-plus me-2 text-secondary"></i>Open a new ticket</h3></div>
  <form method="post" action="/portal/tickets" class="card-body">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <div class="mb-2"><label class="form-label" for="t-subject">Subject</label>
      <input class="form-control" id="t-subject" name="subject" maxlength="200" required value="<?= e($draft['subject']) ?>" placeholder="For example: Printer in the front office won't print"></div>
    <div class="mb-2"><label class="form-label" for="t-details">What's happening?</label>
      <textarea class="form-control" id="t-details" name="details" rows="5" maxlength="10000" required placeholder="What you see, who it affects and since when. Include any error message."><?= e($draft['details']) ?></textarea></div>
    <div class="mb-3"><label class="form-label" for="t-priority">How urgent is it?</label>
      <select class="form-select w-auto" id="t-priority" name="priority">
        <?php foreach (['Low' => 'Low: whenever you can', 'Medium' => 'Medium: it\'s getting in the way', 'High' => 'High: people can\'t work'] as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= $draft['priority'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Something down for everyone right now? Call your IT team as well.</div></div>
    <button class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Send ticket</button>
  </form>
</div>
<?php endif; ?>
<p class="small text-muted">The list is brought up to date every hour, and each ticket when you open it.</p>

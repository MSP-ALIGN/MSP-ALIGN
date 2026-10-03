<?php
use Align\Auth;
use Align\Onboarding\Onboarding;
use Align\Onboarding\Requests;

/**
 * Client onboarding (staff). @var array $client, $contacts, $requests; ?array $o; string $status, $subject, $body; bool $mailReady; ?string $link
 * Every value is escaped with e(); $body was sanitized with Html::clean() before it was stored or filled in.
 * $step draws one progress step; its $detail is HTML the caller has escaped.
 */
require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$canEdit = Auth::can('tech');
[$label, $tone] = Onboarding::STATUS_LABELS[$status];
$sent = $o && $o['sent_at'];
$t = $o['transition'] ?? [];
$step = function (?string $when, string $title, string $detail = '') {
    return '<li class="onb-step' . ($when ? ' done' : '') . '"><span class="onb-dot"><i class="fas fa-' . ($when ? 'check' : 'circle') . '"></i></span><div><b>' . e($title) . '</b>'
        . ($when ? ' <span class="text-muted small">' . e(fmt_datetime($when)) . '</span>' : ' <span class="text-muted small">not yet</span>') . ($detail !== '' ? '<div class="small text-muted">' . $detail . '</div>' : '') . '</div></li>';
};
$sentBy = $o && $o['sent_by'] ? \Align\DB::value('SELECT name FROM users WHERE id = ?', [$o['sent_by']]) : null;
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-mountain-sun text-secondary me-2"></i>Onboarding <span class="badge text-bg-<?= $tone ?> align-middle ms-1"><?= e($label) ?></span>
    <a href="/help#guide-onboarding" class="text-muted small" title="How onboarding works"><i class="fas fa-circle-question fa-sm"></i></a></h1>
  <?php if (Auth::can('admin')): ?><a class="btn btn-sm btn-default" href="/settings/onboarding"><i class="fas fa-pen me-1"></i>Edit templates</a><?php endif; ?>
</div>

<?php if ($link): ?>
  <div class="alert alert-success">
    <div class="fw-bold mb-1"><i class="fas fa-link me-1"></i>Onboarding link (shown once)</div>
    <div class="input-group"><input class="form-control select-all" id="onb-link" readonly value="<?= e($link) ?>" aria-label="Onboarding link"><button type="button" class="btn btn-light" data-copy="#onb-link"><i class="fas fa-copy me-1"></i>Copy</button></div>
    <div class="small mt-1">Paste it into your own welcome email. It works until <?= e(fmt_date($o['token_expires_at'])) ?>.</div>
  </div>
<?php endif; ?>

<div class="row">
  <div class="col-xl-5 order-xl-2">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check me-2"></i>Progress</h3></div>
      <div class="card-body">
        <?php if (!$sent): ?>
          <p class="text-muted mb-0">Nothing sent yet. Write the welcome email and send it when <?= e($client['name']) ?> is officially a client.</p>
        <?php else: ?>
          <ul class="onb-steps list-unstyled mb-2">
            <?= $step($o['sent_at'], 'Welcome email sent', e(($o['sent_to'] ?: 'Link created to send yourself') . ($sentBy ? ' · by ' . $sentBy : '') . ($o['send_count'] > 1 ? ' · sent ' . $o['send_count'] . ' times' : ''))) ?>
            <?= $step($o['opened_at'], 'Opened the onboarding page') ?>
            <?= $step($o['contacts_at'], 'Contacts', $o['contacts_summary'] ? e($o['contacts_summary']) . ' · <a href="/clients/' . $cid . '/contacts">Contacts</a>' : '') ?>
            <?= $step($o['reviewed_at'], 'Reviewed how we work & billing', $o['reviewed_by'] ? 'Confirmed by ' . e($o['reviewed_by']) : '') ?>
            <?= $step($o['transition_at'], 'Getting-started details') ?>
            <?= $step($o['completed_at'], 'Finished', $o['completed_by'] ? 'By ' . e($o['completed_by']) : '') ?>
          </ul>
          <div class="small text-muted mb-2">
            <?php if ($o['token_hash'] && $o['token_expires_at']): ?>Link works until <?= e(fmt_date($o['token_expires_at'])) ?>.<?php else: ?><span class="text-danger">The link is turned off.</span><?php endif; ?>
          </div>
          <?php if ($canEdit): ?>
            <div class="d-flex flex-wrap">
              <?php if ($o['token_hash']): ?>
                <form method="post" action="/clients/<?= $cid ?>/onboarding/revoke" class="me-1 mb-1"><?= csrf_field() ?><button class="btn btn-xs btn-outline-danger" data-confirm="Turn off the onboarding link? The client won't be able to open it until you send a new one.">Turn off link</button></form>
              <?php endif; ?>
              <form method="post" action="/clients/<?= $cid ?>/onboarding/status" class="me-1 mb-1"><?= csrf_field() ?>
                <?php if ($o['completed_at']): ?><button class="btn btn-xs btn-outline-secondary" name="action" value="reopen">Reopen</button>
                <?php else: ?><button class="btn btn-xs btn-outline-success" name="action" value="complete">Mark complete</button><?php endif; ?>
              </form>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($t): ?>
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-route me-2 text-secondary"></i>Getting-started details</h3></div>
      <div class="card-body small">
        <dl class="row mb-0">
          <?php foreach ([
              'Current IT provider' => trim(($t['provider'] ?? '') . (($t['provider_contact'] ?? '') ? ' · ' . $t['provider_contact'] : '')),
              'Provider email / phone' => trim(($t['provider_email'] ?? '') . (($t['provider_email'] ?? '') && ($t['provider_phone'] ?? '') ? ' · ' : '') . ($t['provider_phone'] ?? '')),
              'Told them?' => ['yes' => 'Yes', 'no' => 'Not yet', 'none' => 'No current provider', '' => ''][$t['notified'] ?? ''] ?? '',
              'Preferred onsite week' => $t['onsite_week'] ?? '',
              'Schedule with' => $t['scheduling_contact'] ?? '',
              'Visit notes' => $t['onsite_notes'] ?? '',
              'Pain points' => $t['pain_points'] ?? '',
              'Sent by' => $t['your_name'] ?? '',
          ] as $k => $v): if ($v === '') continue; ?>
            <dt class="col-sm-4 text-muted fw-normal"><?= e($k) ?></dt><dd class="col-sm-8"><?= nl2br(e($v)) ?></dd>
          <?php endforeach; ?>
        </dl>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($requests): ?>
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-user-plus me-2 text-secondary"></i>Requests from the client</h3></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($requests as $r): ?>
          <li class="list-group-item py-2"><b><?= e($r['title']) ?></b>
            <div class="text-muted"><?= e(fmt_datetime($r['created_at'])) ?> · <?= e($r['submitted_name']) ?> · via <?= $r['via'] === 'portal' ? 'portal' : 'onboarding page' ?> ·
              <?php if ($r['delivery'] === 'psa' && $r['psa_ticket_id'] && ($tu = \Align\Providers\Providers::psaLink('ticket', $r['psa_ticket_id']))): ?><a href="<?= e($tu) ?>" target="_blank" rel="noopener"><?= e(psa_name()) ?> ticket</a>
              <?php elseif ($r['delivery'] === 'psa'): ?><?= e(psa_name()) ?> ticket #<?= e($r['psa_ticket_id']) ?>
              <?php elseif ($r['delivery'] === 'email'): ?>emailed to service
              <?php else: ?><span class="text-danger">not delivered<?= $r['delivery_error'] ? ': ' . e($r['delivery_error']) : '' ?></span><?php endif; ?></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-xl-7 order-xl-1">
    <?php if ($canEdit): ?>
    <form method="post" action="/clients/<?= $cid ?>/onboarding/send" id="template-form" class="card card-outline card-primary" data-confirm-rules="<?= e(json_encode([
        ['button' => 'send', 'title' => 'Email the welcome now?', 'ok' => 'Send',
         'text' => 'It goes to the contacts you ticked and any other addresses you entered, with their Start onboarding button. If a link was made before, it stops working.'],
    ])) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="body" id="template-body">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-envelope-open-text me-2"></i><?= $sent ? 'Send again' : 'Welcome email' ?></h3></div>
      <div class="card-body">
        <?php if ($sent): ?><p class="small text-muted">Sending again creates a new link; the previous link stops working. Progress so far is kept.</p><?php endif; ?>
        <div class="mb-3"><label>Send to</label>
          <?php foreach ($contacts as $i => $k): ?>
            <div class="form-check "><input type="checkbox" class="form-check-input" id="to-<?= (int) $k['id'] ?>" name="to[]" value="<?= (int) $k['id'] ?>" <?= $i === 0 && ($k['is_primary'] || $k['decision_maker'] || count($contacts) === 1) ? 'checked' : '' ?>>
              <label class="form-check-label fw-normal" for="to-<?= (int) $k['id'] ?>"><?= e($k['name']) ?> <span class="text-muted">&lt;<?= e($k['email']) ?>&gt;<?= $k['title'] ? ' · ' . e($k['title']) : '' ?></span></label></div>
          <?php endforeach; ?>
          <input class="form-control form-control-sm mt-2" name="to_other" placeholder="Other email addresses (comma separated)" aria-label="Other email addresses" value="<?= $contacts ? '' : e($client['contact_email'] ?? '') ?>">
        </div>
        <div class="row g-2">
          <div class="mb-3 col-md-8"><label for="onb-subject">Subject</label><input id="onb-subject" class="form-control" name="subject" value="<?= e($subject) ?>" maxlength="255" required></div>
          <div class="mb-3 col-md-4"><label for="onb-week">Onsite week</label><input id="onb-week" class="form-control" name="onsite_week" value="<?= e($o['onsite_week'] ?? '') ?>" placeholder="e.g. October 12"></div>
        </div>
        <label>Message</label>
        <div class="doc-card border rounded"><div id="doc-editor" class="doc-editor onb-editor" data-mode="template"></div></div>
        <template id="doc-initial"><?= $body ?></template>
        <p class="small text-muted mt-2 mb-0"><code>{{contact_first_name}}</code>, <code>{{onsite_week}}</code> and <code>{{onboarding_link}}</code> are filled in when you send. The link becomes a <b>Start onboarding</b> button.
          The onboarding page shows the guides from <?= Auth::can('admin') ? '<a href="/settings/onboarding">Onboarding → Welcome email &amp; guide</a>' : 'Onboarding → Welcome email &amp; guide' ?>.</p>
      </div>
      <div class="card-footer d-flex flex-wrap align-items-center">
        <div class="form-check me-auto"><input type="checkbox" class="form-check-input" id="cc-me" name="cc_me" value="1" checked><label class="form-check-label small" for="cc-me">Send me a copy</label></div>
        <button class="btn btn-default me-2 mt-1" name="action" value="link" formnovalidate title="Make the link without sending, to paste into your own email" data-confirm="Make a new onboarding link? If a link was made before, it stops working." data-confirm-danger="0" data-confirm-ok="Create link"><i class="fas fa-link me-1"></i>Create link only</button>
        <button class="btn btn-primary mt-1" name="action" value="send" data-enter-skip <?= $mailReady ? '' : 'disabled title="Set up Email under Integrations to send from Align"' ?>><i class="fas fa-paper-plane me-1"></i><?= $sent ? 'Send again' : 'Send welcome email' ?></button>
      </div>
      <?php if (!$mailReady): ?><div class="card-footer small text-muted py-1">Email isn't connected, so use <b>Create link only</b> and send it from your own mailbox.</div><?php endif; ?>
    </form>
    <?php else: ?>
      <div class="card card-body text-muted small">A tech or admin sends the welcome email.</div>
    <?php endif; ?>
  </div>
</div>

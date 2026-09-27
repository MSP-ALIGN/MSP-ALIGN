<?php
use Align\Onboarding\Onboarding;
use Align\Onboarding\Requests;

/**
 * Client onboarding page. @var string $token; array $o, $client, $company, $pages, $contacts, $requests; ?array $intro, $vcio; bool $requestsOn
 */
$base = '/portal/welcome/' . $token;
$t = $o['transition'];
[$done, $total] = Onboarding::progress($o);
$steps = [
    'contacts' => ['Your team', 'fa-address-book', (bool) $o['contacts_at']],
    'review' => ['How we work', 'fa-book-open', (bool) $o['reviewed_at']],
    'transition' => ['Getting started', 'fa-route', (bool) $o['transition_at']],
];
if ($requestsOn) {
    $steps['requests'] = ['Requests', 'fa-user-plus', null];
}
$steps['finish'] = ['Finish', 'fa-flag-checkered', (bool) $o['completed_at']];
$chk = fn(array $k, string $f) => !empty($k[$f]) ? 'checked' : '';
?>
<section class="welcome-hero card card-body mb-4">
  <div class="d-flex flex-wrap align-items-start">
    <div class="mr-auto welcome-hero-text">
      <div class="welcome-kicker"><?= e($client['name']) ?></div>
      <h1 class="welcome-title"><?= e($intro['heading'] ?? 'Welcome aboard!') ?></h1>
      <?php if ($intro): ?><div class="welcome-prose"><?= $intro['html'] ?></div><?php endif; ?>
    </div>
    <div class="welcome-progress text-center">
      <div class="welcome-progress-num"><?= $done ?>/<?= $total ?></div>
      <div class="small text-muted">steps done</div>
    </div>
  </div>
  <nav class="welcome-steps mt-3" aria-label="Onboarding steps">
    <?php $n = 0; foreach ($steps as $k => [$label, $icon, $isDone]): $n++; ?>
      <a href="#<?= $k ?>" class="welcome-step<?= $isDone ? ' done' : '' ?>">
        <span class="welcome-step-num"><?= $isDone ? '<i class="fas fa-check"></i>' : ($k === 'finish' ? '<i class="fas fa-flag-checkered"></i>' : $n) ?></span>
        <span><?= e($label) ?><?= $isDone === null ? ' <small class="text-muted">(optional)</small>' : '' ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</section>

<!-- 1. Contacts -->
<section id="contacts" class="card welcome-section">
  <div class="card-header"><h2 class="h5 mb-0"><span class="welcome-num">1</span>Your team's contacts <?= $o['contacts_at'] ? '<span class="badge badge-success ml-2">Saved ' . e(fmt_date($o['contacts_at'])) . '</span>' : '' ?></h2></div>
  <div class="card-body">
    <p class="mb-2">List everyone at <?= e($client['name']) ?> who may contact us for help. <b>As part of our security standards, we can only help people on this list</b>, so please include everyone who uses a computer, phone or email account we'll support.</p>
    <p class="small text-muted mb-3">Tick who can <b>approve changes</b> (new users, access, purchases), who <b>receives invoices</b>, and your <b>main IT contact</b>. You can come back and update this list any time before the link expires.</p>
    <form method="post" action="<?= e($base) ?>/contacts" id="contacts-form" data-contacts-form>
      <?= csrf_field() ?>
      <input type="hidden" name="contacts_json" value="">
      <div class="welcome-table-wrap">
        <table class="table table-sm welcome-contacts mb-2">
          <thead><tr><th>First name</th><th>Last name</th><th>Title</th><th>Email</th><th>Phone</th><th>Mobile</th>
            <th class="text-center" title="Can approve changes, new users and purchases">Approves</th><th class="text-center">Invoices</th><th class="text-center" title="Main IT contact">IT contact</th><th></th></tr></thead>
          <tbody data-contacts-body>
          <?php $i = 0; foreach ($contacts as $k): [$first, $last] = Onboarding::splitName((string) $k['name']); ?>
            <tr data-contact-row>
              <input type="hidden" name="c[<?= $i ?>][id]" value="<?= (int) $k['id'] ?>" data-f="id">
              <td data-label="First name"><input class="form-control form-control-sm" name="c[<?= $i ?>][first]" value="<?= e($first) ?>" data-f="first" aria-label="First name"></td>
              <td data-label="Last name"><input class="form-control form-control-sm" name="c[<?= $i ?>][last]" value="<?= e($last) ?>" data-f="last" aria-label="Last name"></td>
              <td data-label="Title"><input class="form-control form-control-sm" name="c[<?= $i ?>][title]" value="<?= e($k['title']) ?>" data-f="title" aria-label="Title"></td>
              <td data-label="Email"><input type="email" class="form-control form-control-sm" name="c[<?= $i ?>][email]" value="<?= e($k['email']) ?>" data-f="email" aria-label="Email"></td>
              <td data-label="Phone"><input class="form-control form-control-sm" name="c[<?= $i ?>][phone]" value="<?= e($k['phone']) ?>" data-f="phone" aria-label="Phone"></td>
              <td data-label="Mobile"><input class="form-control form-control-sm" name="c[<?= $i ?>][mobile]" value="<?= e($k['mobile']) ?>" data-f="mobile" aria-label="Mobile"></td>
              <td data-label="Approves changes" class="text-center"><input type="checkbox" name="c[<?= $i ?>][approver]" value="1" <?= $chk($k, 'decision_maker') ?> data-f="approver" aria-label="Approves changes"></td>
              <td data-label="Receives invoices" class="text-center"><input type="checkbox" name="c[<?= $i ?>][billing]" value="1" <?= $chk($k, 'is_billing') ?> data-f="billing" aria-label="Receives invoices"></td>
              <td data-label="Main IT contact" class="text-center"><input type="checkbox" name="c[<?= $i ?>][technical]" value="1" <?= $chk($k, 'is_technical') ?> data-f="technical" aria-label="Main IT contact"></td>
              <td class="text-right"><label class="btn btn-xs btn-outline-danger mb-0 welcome-remove" title="No longer with the company"><input type="checkbox" name="c[<?= $i ?>][remove]" value="1" data-f="remove" class="d-none"><i class="fas fa-user-minus"></i><span class="sr-only">Remove</span></label></td>
            </tr>
          <?php $i++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <template id="contact-row-template">
        <tr data-contact-row class="welcome-new">
          <td data-label="First name"><input class="form-control form-control-sm" data-f="first" aria-label="First name"></td>
          <td data-label="Last name"><input class="form-control form-control-sm" data-f="last" aria-label="Last name"></td>
          <td data-label="Title"><input class="form-control form-control-sm" data-f="title" aria-label="Title"></td>
          <td data-label="Email"><input type="email" class="form-control form-control-sm" data-f="email" aria-label="Email"></td>
          <td data-label="Phone"><input class="form-control form-control-sm" data-f="phone" aria-label="Phone"></td>
          <td data-label="Mobile"><input class="form-control form-control-sm" data-f="mobile" aria-label="Mobile"></td>
          <td data-label="Approves changes" class="text-center"><input type="checkbox" data-f="approver" aria-label="Approves changes"></td>
          <td data-label="Receives invoices" class="text-center"><input type="checkbox" data-f="billing" aria-label="Receives invoices"></td>
          <td data-label="Main IT contact" class="text-center"><input type="checkbox" data-f="technical" aria-label="Main IT contact"></td>
          <td class="text-right"><button type="button" class="btn btn-xs btn-outline-secondary" data-row-delete title="Remove this row"><i class="fas fa-xmark"></i><span class="sr-only">Remove row</span></button></td>
        </tr>
      </template>
      <div class="d-flex flex-wrap align-items-center mb-3">
        <button type="button" class="btn btn-sm btn-outline-primary mr-2 mb-1" data-contact-add><i class="fas fa-plus mr-1"></i>Add a person</button>
        <button type="button" class="btn btn-sm btn-outline-secondary mb-1" data-toggle="collapse" data-target="#paste-box" aria-expanded="false"><i class="fas fa-paste mr-1"></i>Paste from a spreadsheet</button>
      </div>
      <div class="collapse mb-3" id="paste-box">
        <div class="card card-body bg-light">
          <label for="paste-text" class="small mb-1">Copy rows from Excel or Google Sheets (columns: <b>First name, Last name, Title, Phone, Email</b>) and paste them here.</label>
          <textarea id="paste-text" class="form-control form-control-sm mb-2" rows="4" placeholder="Jane&#9;Smith&#9;Office Manager&#9;(555) 555-0100&#9;jane@example.com"></textarea>
          <div><button type="button" class="btn btn-sm btn-primary" data-paste-add>Add these people</button> <span class="small text-muted ml-2" data-paste-result></span></div>
        </div>
      </div>
      <div class="form-row align-items-end">
        <div class="form-group col-md-5 mb-2"><label for="c-your-name" class="small">Your name</label><input id="c-your-name" class="form-control" name="your_name" maxlength="120" required value="<?= e($o['reviewed_by'] ?? '') ?>"></div>
        <div class="form-group col-md-7 mb-2 text-md-right"><button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save contacts</button></div>
      </div>
    </form>
  </div>
</section>

<!-- 2. How we work -->
<section id="review" class="card welcome-section">
  <div class="card-header"><h2 class="h5 mb-0"><span class="welcome-num">2</span>How we work together <?= $o['reviewed_at'] ? '<span class="badge badge-success ml-2">Confirmed</span>' : '' ?></h2></div>
  <div class="card-body">
    <?php if ($vcio): ?>
      <div class="welcome-vcio mb-3"><i class="fas fa-user-tie fa-fw mr-1 text-muted"></i>Your technology advisor: <b><?= e($vcio['name']) ?></b><?= $vcio['email'] ? ' · ' . e($vcio['email']) : '' ?></div>
    <?php endif; ?>
    <div class="welcome-guides" id="guides">
      <?php foreach ($pages as $gi => $p): ?>
        <div class="welcome-guide">
          <button type="button" class="welcome-guide-head<?= $gi ? ' collapsed' : '' ?>" data-toggle="collapse" data-target="#guide-<?= (int) $p['id'] ?>" aria-expanded="<?= $gi ? 'false' : 'true' ?>">
            <span><?= e($p['heading']) ?></span><i class="fas fa-chevron-down"></i></button>
          <div id="guide-<?= (int) $p['id'] ?>" class="collapse<?= $gi ? '' : ' show' ?>">
            <div class="welcome-prose p-3"><?= $p['html'] ?>
              <?php if ($p['has_file']): ?><p class="mb-0"><a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/guide/<?= (int) $p['id'] ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf mr-1"></i>Open the full guide (PDF)</a></p><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($o['reviewed_at']): ?>
      <p class="text-success small mt-3 mb-0"><i class="fas fa-circle-check mr-1"></i>Confirmed by <?= e($o['reviewed_by']) ?> on <?= e(fmt_date($o['reviewed_at'])) ?>.</p>
    <?php else: ?>
      <form method="post" action="<?= e($base) ?>/review" class="mt-3">
        <?= csrf_field() ?>
        <div class="custom-control custom-checkbox mb-2"><input type="checkbox" class="custom-control-input" id="ack" name="ack" value="1" required>
          <label class="custom-control-label" for="ack">I've read how to reach <?= e($company['name']) ?>, the response times, and how billing works.</label></div>
        <div class="form-row align-items-end">
          <div class="form-group col-md-5 mb-2"><label for="ack-name" class="small">Your name</label><input id="ack-name" class="form-control" name="ack_name" maxlength="190" required></div>
          <div class="form-group col-md-7 mb-2 text-md-right"><button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Confirm</button></div>
        </div>
      </form>
    <?php endif; ?>
  </div>
</section>

<!-- 3. Getting started -->
<section id="transition" class="card welcome-section">
  <div class="card-header"><h2 class="h5 mb-0"><span class="welcome-num">3</span>Getting started <?= $o['transition_at'] ? '<span class="badge badge-success ml-2">Sent</span>' : '' ?></h2></div>
  <div class="card-body">
    <p class="mb-3">A few details so we can coordinate with your current IT provider and plan our onsite visit. We typically need one full day onsite, about 10–15 minutes per computer.</p>
    <form method="post" action="<?= e($base) ?>/transition">
      <?= csrf_field() ?>
      <h3 class="h6 text-uppercase text-muted">Current IT provider</h3>
      <div class="form-row">
        <div class="form-group col-md-6"><label for="t-provider">Company</label><input id="t-provider" class="form-control" name="provider" value="<?= e($t['provider'] ?? '') ?>" placeholder="None / we handle it ourselves"></div>
        <div class="form-group col-md-6"><label for="t-pc">Contact person</label><input id="t-pc" class="form-control" name="provider_contact" value="<?= e($t['provider_contact'] ?? '') ?>"></div>
        <div class="form-group col-md-6"><label for="t-pe">Their email</label><input id="t-pe" type="email" class="form-control" name="provider_email" value="<?= e($t['provider_email'] ?? '') ?>"></div>
        <div class="form-group col-md-6"><label for="t-pp">Their phone</label><input id="t-pp" class="form-control" name="provider_phone" value="<?= e($t['provider_phone'] ?? '') ?>"></div>
      </div>
      <div class="form-group"><label class="d-block">Have you let them know you're moving to <?= e($company['name']) ?>?</label>
        <?php foreach (['yes' => 'Yes', 'no' => 'Not yet', 'none' => 'We don\'t have a provider'] as $val => $lbl): ?>
          <div class="custom-control custom-radio custom-control-inline"><input type="radio" class="custom-control-input" id="t-n-<?= $val ?>" name="notified" value="<?= $val ?>" <?= ($t['notified'] ?? '') === $val ? 'checked' : '' ?>><label class="custom-control-label" for="t-n-<?= $val ?>"><?= e($lbl) ?></label></div>
        <?php endforeach; ?>
      </div>
      <h3 class="h6 text-uppercase text-muted mt-3">Onsite visit</h3>
      <div class="form-row">
        <div class="form-group col-md-6"><label for="t-week">Preferred week</label><input id="t-week" class="form-control" name="onsite_week" value="<?= e($t['onsite_week'] ?? ($o['onsite_week'] ?? '')) ?>" placeholder="e.g. week of October 12"></div>
        <div class="form-group col-md-6"><label for="t-sc">Who should we schedule with?</label><input id="t-sc" class="form-control" name="scheduling_contact" value="<?= e($t['scheduling_contact'] ?? '') ?>"></div>
        <div class="form-group col-12"><label for="t-on">Anything we should know about the visit?</label><textarea id="t-on" class="form-control" name="onsite_notes" rows="2" placeholder="Office hours, parking, alarm codes to arrange, days to avoid…"><?= e($t['onsite_notes'] ?? '') ?></textarea></div>
      </div>
      <h3 class="h6 text-uppercase text-muted mt-2">What's not working today?</h3>
      <div class="form-group"><label for="t-pain" class="sr-only">Pain points</label><textarea id="t-pain" class="form-control" name="pain_points" rows="3" placeholder="Slow computers, printer problems, email issues… We'll open tickets to start on these right away."><?= e($t['pain_points'] ?? '') ?></textarea></div>
      <div class="form-row align-items-end">
        <div class="form-group col-md-5 mb-2"><label for="t-name" class="small">Your name</label><input id="t-name" class="form-control" name="your_name" maxlength="120" required value="<?= e($t['your_name'] ?? '') ?>"></div>
        <div class="form-group col-md-7 mb-2 text-md-right"><button class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i><?= $o['transition_at'] ? 'Update details' : 'Send details' ?></button></div>
      </div>
    </form>
  </div>
</section>

<?php if ($requestsOn): ?>
<!-- 4. Requests -->
<section id="requests" class="card welcome-section">
  <div class="card-header"><h2 class="h5 mb-0"><span class="welcome-num">4</span>Requests <small class="text-muted">(optional)</small></h2></div>
  <div class="card-body">
    <p class="mb-3">Starting someone new, or someone leaving? Send it here and it goes straight to our service desk. You can also email or call us any time.</p>
    <div class="row">
      <?php foreach (Requests::FORMS as $kind => [$title, $icon, $introText, $fields]): ?>
        <div class="col-md-6 mb-2"><button type="button" class="btn btn-outline-primary btn-block text-left welcome-req-btn collapsed" data-toggle="collapse" data-target="#rq-<?= $kind ?>" aria-expanded="false">
          <i class="fas <?= e($icon) ?> fa-fw mr-2"></i><?= e($title) ?></button></div>
      <?php endforeach; ?>
    </div>
    <?php foreach (Requests::FORMS as $kind => $_f): ?>
      <div class="collapse" id="rq-<?= $kind ?>" data-parent="#requests">
        <?= \Align\View::fetch('partials/request_form', ['kind' => $kind, 'action' => $base . '/request/' . $kind, 'askName' => true]) ?>
      </div>
    <?php endforeach; ?>
    <?php if ($requests): ?>
      <h3 class="h6 text-uppercase text-muted mt-3">Sent</h3>
      <ul class="list-unstyled small mb-0">
        <?php foreach ($requests as $r): ?><li class="mb-1"><i class="fas fa-<?= $r['delivery'] === 'failed' ? 'triangle-exclamation text-warning' : 'circle-check text-success' ?> mr-1"></i><?= e($r['title']) ?> <span class="text-muted">· <?= e(fmt_date($r['created_at'])) ?> by <?= e($r['submitted_name']) ?></span></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- Finish -->
<section id="finish" class="card welcome-section">
  <div class="card-body text-center py-4">
    <?php if ($o['completed_at']): ?>
      <i class="fas fa-mountain-sun fa-2x text-primary mb-2"></i>
      <h2 class="h4">You're all set. Welcome aboard!</h2>
      <p class="text-muted mb-1">Thanks, <?= e($o['completed_by']) ?>. We'll reach out to confirm the onsite visit and get started.</p>
      <p class="small text-muted mb-0">Need anything? <?= e($company['phone'] ?: '') ?><?= $company['phone'] && $company['email'] ? ' · ' : '' ?><?= e($company['email'] ?: '') ?></p>
    <?php elseif ($done < $total): ?>
      <h2 class="h5">Almost there</h2>
      <p class="text-muted mb-0">Finish the steps above (<?= $done ?> of <?= $total ?> done). You can come back to this page until <?= e(fmt_date($o['token_expires_at'])) ?>.</p>
    <?php else: ?>
      <h2 class="h5">That's everything</h2>
      <p class="text-muted">Let us know you're done and we'll take it from here.</p>
      <form method="post" action="<?= e($base) ?>/finish" class="d-inline-flex flex-wrap justify-content-center">
        <?= csrf_field() ?>
        <input class="form-control mr-2 mb-2" style="max-width:240px" name="your_name" placeholder="Your name" aria-label="Your name" value="<?= e($t['your_name'] ?? $o['reviewed_by'] ?? '') ?>">
        <button class="btn btn-success mb-2"><i class="fas fa-flag-checkered mr-1"></i>Finish onboarding</button>
      </form>
    <?php endif; ?>
  </div>
</section>

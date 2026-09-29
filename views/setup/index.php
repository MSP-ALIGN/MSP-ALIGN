<?php
/**
 * Setup wizard (1.40). One step at a time; every step can be skipped. The forms post to the usual
 * settings / integration / email / user pages with `return`, which brings the admin back here.
 * @var string $step, $next, $return; array $steps; bool $pending
 */
use Align\Controllers\SetupController;

$keys = array_keys(SetupController::STEPS);
$idx = array_search($step, $keys, true);
$prev = $idx === false ? '/setup/' . end($keys) : ($idx > 0 ? '/setup/' . $keys[$idx - 1] : null);
$ret = '<input type="hidden" name="return" value="' . e($return) . '">';
$stateIcon = ['done' => ['fa-circle-check', 'text-success', 'Done'], 'skipped' => ['fa-circle-minus', 'text-muted', 'Skipped'], 'todo' => ['fa-circle', 'text-black-50', 'To do']];
$connectorCard = function (array $x, string $return, bool $open) {
    /** @var \Align\Integrations\Connector $c */
    $c = $x['c'];
    [$tone, $label, $detail] = $x['status'];
    ob_start(); ?>
    <div class="card mb-3">
      <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto mb-0"><i class="<?= e($c->icon()) ?> fa-fw text-secondary mr-2"></i><?= e($c->name()) ?></h3>
        <span class="badge badge-<?= e($tone) ?> mr-2"><?= e($label) ?></span>
        <button class="btn btn-sm btn-<?= $open ? 'default' : 'outline-primary' ?>" type="button" data-toggle="collapse" data-target="#setup-<?= e($c->key()) ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>"><?= $c->configured() ? 'Settings' : 'Set up' ?></button>
      </div>
      <div class="collapse<?= $open ? ' show' : '' ?>" id="setup-<?= e($c->key()) ?>">
        <div class="card-body">
          <p class="small text-muted"><?= e($c->summary()) ?> <b><?= e($c->direction()) ?>.</b><?= $detail !== '' ? ' ' . e($detail) : '' ?></p>
          <details class="small border rounded p-2 bg-light mb-3"><summary class="font-weight-bold">How to set it up</summary><div class="mt-2"><?= $c->setup() ?></div></details>
          <form method="post" action="/integrations/<?= e($c->key()) ?>">
            <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
            <?php foreach ($c->fields() as $f) echo \Align\View::fetch('integrations/_field', ['f' => $f, 'values' => $x['values']]); ?>
            <?php if ($c->notes() !== ''): ?><p class="small text-muted"><?= $c->notes() ?></p><?php endif; ?>
            <button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save</button>
          </form>
          <?php if ($c->hasTest()): ?>
            <form method="post" action="/integrations/<?= e($c->key()) ?>/test" class="mt-2"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
              <button class="btn btn-sm btn-default" <?= $c->configured() ? '' : 'disabled title="Save the settings first"' ?>><i class="fas fa-vial mr-1"></i>Test connection</button></form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <div class="mr-auto"><h1 class="h3 mb-0"><i class="fas fa-wand-magic-sparkles text-secondary mr-2"></i>Set up MSP-ALIGN</h1>
    <div class="small text-muted">A few steps to get your first clients in. Skip anything you don't use yet; you can come back here any time from Settings → General.</div></div>
  <?php if ($pending): ?>
    <form method="post" action="/setup/finish" class="mt-2 mt-md-0"><?= csrf_field() ?><input type="hidden" name="how" value="skip">
      <button class="btn btn-sm btn-link text-muted" data-confirm="Skip the setup wizard? You can open it again from Settings → General.">Skip setup for now</button></form>
  <?php endif; ?>
</div>

<div class="row">
  <div class="col-lg-3">
    <nav class="card setup-steps" aria-label="Setup steps">
      <ul class="list-group list-group-flush">
        <?php foreach ($steps as $k => $s): [$ic, $tc, $sl] = $stateIcon[$s['state']]; ?>
          <a href="/setup/<?= $k ?>" class="list-group-item list-group-item-action d-flex align-items-center<?= $k === $step ? ' active' : '' ?>"<?= $k === $step ? ' aria-current="step"' : '' ?>>
            <i class="fas fa-fw <?= e($s['icon']) ?> mr-2"></i><span class="mr-auto"><?= e($s['label']) ?></span>
            <i class="fas <?= $ic ?> <?= $k === $step ? '' : $tc ?>" title="<?= $sl ?>"></i><span class="sr-only"> (<?= $sl ?>)</span></a>
        <?php endforeach; ?>
        <a href="/setup/finish" class="list-group-item list-group-item-action<?= $step === 'finish' ? ' active' : '' ?>"><i class="fas fa-fw fa-flag-checkered mr-2"></i>Finish</a>
      </ul>
    </nav>
  </div>
  <div class="col-lg-9">
<?php
// ---- the step's own content ------------------------------------------------------------------------
// Not done yet: the way on is "Skip this step" (it then shows as skipped and stops coming back);
// done: "Continue". The company and currency steps move on with their own Save and continue.
$footer = function (bool $showContinue = true, string $skipLabel = 'Skip this step') use ($step, $next, $prev, $steps): string {
    $done = ($steps[$step]['state'] ?? '') === 'done';
    ob_start(); ?>
    <div class="d-flex flex-wrap align-items-center mt-3 setup-footer">
      <?php if ($prev): ?><a class="btn btn-default mr-2" href="<?= e($prev) ?>"><i class="fas fa-arrow-left mr-1"></i>Back</a><?php endif; ?>
      <span class="mr-auto"></span>
      <?php if ($done && $showContinue): ?>
        <a class="btn btn-primary" href="<?= e($next) ?>">Continue<i class="fas fa-arrow-right ml-1"></i></a>
      <?php elseif (!$done): ?>
        <form method="post" action="/setup/<?= e($step) ?>/skip"><?= csrf_field() ?><button class="btn <?= $showContinue ? 'btn-primary' : 'btn-link text-muted' ?>"><?= e($skipLabel) ?><?= $showContinue ? '<i class="fas fa-arrow-right ml-1"></i>' : '' ?></button></form>
      <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
};

switch ($step):
case 'company': ?>
    <form method="post" action="/setup/company" enctype="multipart/form-data" class="card card-dark">
      <?= csrf_field() ?><input type="hidden" name="MAX_FILE_SIZE" value="<?= \Align\Branding::MAX_BYTES ?>">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-building mr-2"></i>Your company</h3></div>
      <div class="card-body">
        <p class="small text-muted">Shown on reports, emails and the client portal.</p>
        <div class="form-row">
          <div class="form-group col-md-6"><label for="s-name">Company name</label><input id="s-name" name="company_name" class="form-control" maxlength="190" required value="<?= e($v['company_name']) ?>"></div>
          <div class="form-group col-md-6"><label for="s-phone">Phone</label><input id="s-phone" name="company_phone" class="form-control" maxlength="60" value="<?= e($v['company_phone']) ?>"></div>
          <div class="form-group col-md-6"><label for="s-email">Email <small class="text-muted">(for clients)</small></label><input id="s-email" type="email" name="company_email" class="form-control" value="<?= e($v['company_email']) ?>"></div>
          <div class="form-group col-md-6"><label for="s-web">Website</label><input id="s-web" name="company_website" class="form-control" maxlength="190" value="<?= e($v['company_website']) ?>" placeholder="www.example.com"></div>
        </div>
        <div class="form-row align-items-end">
          <div class="form-group col-md-8"><label for="logo">Logo <small class="text-muted">(optional)</small></label>
            <div class="d-flex align-items-center"><div class="logo-preview mr-3"><img src="<?= e($logoUrl) ?>" alt="Current logo" id="logo-img"></div>
              <div class="custom-file"><input type="file" class="custom-file-input" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif"><label class="custom-file-label" for="logo">Choose PNG, JPG or WebP…</label></div></div></div>
          <div class="form-group col-md-4"><label for="s-color">Main colour</label><input id="s-color" type="color" name="brand_primary" class="form-control" value="<?= e($v['brand_primary'] ?: \Align\Branding::color()) ?>"></div>
        </div>
        <p class="small text-muted mb-0">More branding (portal name, sidebar, sign-in message) is under Settings → Branding.</p>
      </div>
      <div class="card-footer d-flex"><button class="btn btn-primary ml-auto">Save and continue<i class="fas fa-arrow-right ml-1"></i></button></div>
    </form>
    <?= $footer(false) ?>
<?php break; case 'locale': ?>
    <form method="post" action="/settings">
      <?= csrf_field() ?><input type="hidden" name="_tab" value="general"><?= $ret ?><input type="hidden" name="return_ok" value="<?= e($next) ?>">
      <?= \Align\View::fetch('settings/_locale', ['v' => $v]) ?>
      <div class="d-flex"><button class="btn btn-primary ml-auto">Save and continue<i class="fas fa-arrow-right ml-1"></i></button></div>
    </form>
    <?= $footer(false) ?>
<?php break; case 'psa': case 'rmm': case 'more':
    $intro = [
        'psa' => ['Your PSA', 'Clients, contacts, assets, licenses, invoices and tickets come from your PSA. MSP-ALIGN works without one too: clients can then come from your RMM, a CSV file or be added by hand.', 'No PSA? Just continue.'],
        'rmm' => ['Your RMM', 'Computers, servers, operating systems and warranty data come from your RMM, and it links each device to its client.', ''],
        'more' => ['Backups & warranty', 'Optional: backup results for each client, and warranty end dates looked up from Dell and Lenovo.', ''],
    ][$step]; ?>
    <div class="card card-body py-2 mb-3"><h2 class="h5 mb-1"><?= e($intro[0]) ?></h2><div class="small text-muted"><?= e($intro[1]) ?><?= $intro[2] ? ' <b>' . e($intro[2]) . '</b>' : '' ?></div></div>
    <?php foreach ($connectors as $i => $x) echo $connectorCard($x, $return, $x['c']->configured() || (count($connectors) === 1 && $step !== 'more')); ?>
    <?= $footer(true, $step === 'psa' ? 'Continue without a PSA' : 'Skip this step') ?>
<?php break; case 'email': ?>
    <div class="card card-body py-2 mb-3"><h2 class="h5 mb-1">Email</h2>
      <div class="small text-muted">Optional, but it's how MSP-ALIGN sends notifications, digests, client portal invitations and meeting invitations.
        <?= $ready ? '<b class="text-success">Email is set up (' . e(\Align\Mail\Mail::providerName()) . ').</b>' : '' ?></div></div>
    <div class="card mb-3">
      <div class="card-header py-2"><h3 class="card-title"><i class="fab fa-microsoft fa-fw text-secondary mr-1"></i><i class="fab fa-google fa-fw text-secondary mr-2"></i>Microsoft 365 or Google Workspace</h3></div>
      <div class="card-body small">Signs in with OAuth and can send real Outlook or Google Calendar invitations. The Email page walks through registering the app.
        <a class="btn btn-sm btn-outline-primary ml-2" href="/integrations/email" target="_blank" rel="noopener">Open the Email page <i class="fas fa-up-right-from-square ml-1"></i></a>
        <span class="text-muted d-block mt-1">It opens in a new tab; come back here when it says Ready.</span></div>
    </div>
    <form method="post" action="/integrations/email" class="card mb-3">
      <?= csrf_field() ?><?= $ret ?><input type="hidden" name="mail_provider" value="smtp"><input type="hidden" name="mail_mode" value="app">
      <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-server fa-fw text-secondary mr-2"></i>SMTP server <?= $provider === 'smtp' && $ready ? '<span class="badge badge-success ml-1">Ready</span>' : '' ?></h3></div>
      <div class="card-body">
        <p class="small text-muted">Your own mail server or a relay (SMTP2GO, Mailgun, SendGrid, Amazon SES, or the Microsoft 365 / Google relay).<?= $provider !== 'smtp' && $ready ? ' Saving this switches email from ' . e(\Align\Mail\Mail::providerName()) . ' to SMTP.' : '' ?></p>
        <div class="form-row">
          <div class="form-group col-md-6"><label for="e-host">Server</label><input id="e-host" name="smtp_host" class="form-control" value="<?= e($smtp['host']) ?>" placeholder="smtp.example.com" required></div>
          <div class="form-group col-md-2"><label for="e-port">Port</label><input id="e-port" type="number" min="1" max="65535" name="smtp_port" class="form-control" value="<?= e($smtp['port']) ?>" placeholder="587"></div>
          <div class="form-group col-md-4"><label for="e-sec">Security</label><select id="e-sec" name="smtp_security" class="custom-select"><?php foreach (\Align\Mail\Smtp::SECURITY as $k => $l): ?><option value="<?= e($k) ?>" <?= $smtp['security'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="form-group col-md-6"><label for="e-user">User name <small class="text-muted">(empty for a relay that trusts this server)</small></label><input id="e-user" name="smtp_user" class="form-control" value="<?= e($smtp['user']) ?>" autocomplete="off"></div>
          <div class="form-group col-md-6"><label for="e-pass">Password or API key</label><input id="e-pass" type="password" name="smtp_pass" class="form-control" autocomplete="new-password" placeholder="<?= $smtp['pass'] ? '•••••••• saved (leave blank to keep)' : 'Not set' ?>"></div>
          <div class="form-group col-md-6"><label for="e-from">From address</label><input id="e-from" type="email" name="mail_from" class="form-control" value="<?= e($smtp['from']) ?>" placeholder="alerts@yourdomain.com" required></div>
          <div class="form-group col-md-6"><label for="e-fname">Display name <small class="text-muted">(optional)</small></label><input id="e-fname" name="mail_from_name" class="form-control" value="<?= e($smtp['from_name']) ?>"></div>
        </div>
        <button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save</button>
      </div>
    </form>
    <?php if ($ready): ?>
      <form method="post" action="/integrations/email/test" class="card card-body py-2 mb-3"><?= csrf_field() ?><?= $ret ?>
        <label for="e-to" class="small mb-1">Send a test email</label>
        <div class="input-group"><input id="e-to" type="email" name="to" class="form-control" value="<?= e(\Align\Auth::user()['email'] ?? '') ?>" required><div class="input-group-append"><button class="btn btn-default">Send test</button></div></div>
      </form>
    <?php endif; ?>
    <?= $footer() ?>
<?php break; case 'clients': ?>
    <div class="card card-body py-2 mb-3"><h2 class="h5 mb-1">Your clients</h2>
      <div class="small text-muted"><?= $clients ? "<b>$clients</b> client" . ($clients === 1 ? '' : 's') . ' in MSP-ALIGN so far.' : 'No clients yet.' ?> Pick whichever way suits you; you can use more than one.</div></div>
    <div class="row">
      <?php if ($psa || $rmm): ?>
        <div class="col-md-6"><div class="card"><div class="card-body">
          <h3 class="h6"><i class="fas fa-rotate text-secondary mr-1"></i>Run the first sync</h3>
          <p class="small text-muted"><?= $psa ? 'Brings in your clients from ' . e(psa_name()) . ', and their devices' : 'Brings in the organizations and devices' ?><?= $rmm ? ' from ' . e(\Align\Providers\Providers::rmmNames()) : '' ?>. After this it runs every hour by itself.</p>
          <?php if ($running): ?><div class="small"><i class="fas fa-spinner fa-spin mr-1"></i>Sync running… this page refreshes until it's done.</div>
          <?php else: ?>
            <form method="post" action="/sync"><?= csrf_field() ?><?= $ret ?><button class="btn btn-primary btn-sm">Run sync now</button></form>
            <?php if ($lastSync): ?><div class="small text-muted mt-2">Last sync <?= e(rel_time($lastSync['started_at'])) ?>: <?= e($lastSync['status']) ?> · <a href="/sync/<?= (int) $lastSync['id'] ?>" target="_blank">details</a></div><?php endif; ?>
          <?php endif; ?>
        </div></div></div>
      <?php endif; ?>
      <?php if ($rmm && !$psa): ?>
        <div class="col-md-6"><div class="card"><div class="card-body">
          <h3 class="h6"><i class="fas fa-link text-secondary mr-1"></i>Clients from your RMM</h3>
          <p class="small text-muted">Add a client for each of your <?= $orgs ?: '' ?> <?= e(\Align\Providers\Providers::rmmNames()) ?> organizations, already linked.</p>
          <a class="btn btn-default btn-sm" href="/mapping" target="_blank">Open Client mapping</a>
        </div></div></div>
      <?php endif; ?>
      <div class="col-md-6"><div class="card"><div class="card-body">
        <h3 class="h6"><i class="fas fa-file-import text-secondary mr-1"></i>Import a CSV file</h3>
        <p class="small text-muted">Clients and contacts from a spreadsheet or another tool. You see what each row will do before anything is saved.</p>
        <a class="btn btn-default btn-sm" href="/clients/import" target="_blank">Import clients</a>
      </div></div></div>
      <div class="col-md-6"><div class="card"><div class="card-body">
        <h3 class="h6"><i class="fas fa-plus text-secondary mr-1"></i>Add them one by one</h3>
        <p class="small text-muted">Fine for a handful. Each client gets its own roadmap, budget, meetings and portal.</p>
        <a class="btn btn-default btn-sm" href="/clients" target="_blank">Open Clients</a>
      </div></div></div>
    </div>
    <?= $footer() ?>
<?php break; case 'team': ?>
    <?php if ($newPassword): ?>
      <div class="alert alert-success"><b><?= e($newPassword['email']) ?></b> was added. Their temporary password is <code class="user-select-all"><?= e($newPassword['password']) ?></code>. Send it to them another way (it's shown only once); they choose their own and set up two-factor sign-in when they first sign in.</div>
    <?php endif; ?>
    <div class="card"><div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-users mr-2 text-secondary"></i>Staff accounts</h3></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($users as $u): ?><li class="list-group-item py-2 d-flex"><span class="mr-auto"><b><?= e($u['name']) ?></b> · <?= e($u['email']) ?></span><span class="badge badge-light border"><?= e(ucfirst($u['role'])) ?></span></li><?php endforeach; ?>
      </ul>
      <form method="post" action="/users" class="card-body border-top"><?= csrf_field() ?><?= $ret ?>
        <div class="form-row align-items-end">
          <div class="form-group col-md-4 mb-2"><label for="t-name">Name</label><input id="t-name" name="name" class="form-control" required></div>
          <div class="form-group col-md-4 mb-2"><label for="t-email">Email</label><input id="t-email" type="email" name="email" class="form-control" required></div>
          <div class="form-group col-md-2 mb-2"><label for="t-role">Role</label><select id="t-role" name="role" class="custom-select"><?php foreach (\Align\Controllers\UserController::ROLES as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'tech' ? 'selected' : '' ?>><?= e(strtok($l, ' ')) ?></option><?php endforeach; ?></select></div>
          <div class="form-group col-md-2 mb-2"><button class="btn btn-primary btn-block">Add</button></div>
        </div>
        <div class="small text-muted">Admins manage settings and users, techs plan and edit, viewers read. More options (vCIO clients, notifications) are on the Users page.</div>
      </form>
    </div>
    <?= $footer() ?>
<?php break; case 'finish': ?>
    <div class="card card-dark"><div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-flag-checkered mr-2"></i>All done?</h3></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($steps as $k => $s): [$ic, $tc, $sl] = $stateIcon[$s['state']]; ?>
          <li class="list-group-item py-2 d-flex align-items-center"><i class="fas fa-fw <?= $ic ?> <?= $s['state'] === 'todo' ? 'text-muted' : $tc ?> mr-2"></i><span class="mr-auto"><?= e($s['label']) ?></span>
            <span class="small text-muted mr-2"><?= $sl ?></span><?php if ($s['state'] !== 'done'): ?><a class="btn btn-xs btn-default" href="/setup/<?= $k ?>">Go to step</a><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <div class="card-body small text-muted">Anything skipped can be done later from Integrations and Settings; the dashboard's <b>Getting set up</b> list shows what's left. Help has a guide for every step.</div>
      <div class="card-footer d-flex">
        <?php if ($prev): ?><a class="btn btn-default mr-auto" href="<?= e($prev) ?>"><i class="fas fa-arrow-left mr-1"></i>Back</a><?php endif; ?>
        <form method="post" action="/setup/finish"><?= csrf_field() ?><button class="btn btn-success"><i class="fas fa-check mr-1"></i>Finish and go to the dashboard</button></form>
      </div>
    </div>
<?php break; endswitch; ?>
  </div>
</div>

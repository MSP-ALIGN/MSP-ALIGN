<?php ob_start(); ?>
<?php
use Align\Auth;

$isAdmin = Auth::can('admin');
$isTech = Auth::can('tech');
$links = fn(array $l) => implode(' ', array_map(fn($k, $v) => '<a class="btn btn-xs btn-default mr-1 mb-1" href="' . e($v) . '">' . e($k) . '</a>', array_keys($l), $l));
$step = function (int $n, string $icon, string $title, string $body, array $l) use ($links) {
    return '<div class="help-step d-flex mb-3"><div class="help-num mr-3">' . $n . '</div><div class="flex-grow-1"><h5 class="mb-1"><i class="fas ' . $icon . ' text-secondary mr-2"></i>' . e($title) . '</h5>'
        . '<p class="mb-1 text-muted">' . $body . '</p><div>' . $links($l) . '</div></div></div>';
};
/** A how-to guide: collapsible card with numbered steps. $who: viewer|tech|admin */
$guide = function (string $id, string $icon, string $title, string $who, array $steps, array $l = []) use ($links) {
    if (!Auth::can($who)) {
        return '';
    }
    $badge = ['admin' => '<span class="badge badge-dark ml-2">Admin</span>', 'tech' => '<span class="badge badge-secondary ml-2">Tech</span>'][$who] ?? '';
    $ol = '<ol class="pl-3 mb-2">' . implode('', array_map(fn($s) => '<li class="mb-1">' . $s . '</li>', $steps)) . '</ol>';
    return '<div class="card mb-2 help-guide" id="guide-' . e($id) . '" data-search="' . e(strtolower($title . ' ' . strip_tags(implode(' ', $steps)))) . '">'
        . '<a class="card-header py-2 d-flex align-items-center text-reset text-decoration-none collapsed" data-toggle="collapse" href="#g-' . e($id) . '" role="button" aria-expanded="false">'
        . '<i class="fas ' . $icon . ' fa-fw text-secondary mr-2"></i><span class="font-weight-bold">' . e($title) . '</span>' . $badge . '<i class="fas fa-angle-down fa-sm ml-auto text-muted"></i></a>'
        . '<div class="collapse" id="g-' . e($id) . '"><div class="card-body small">' . $ol . ($l ? '<div>' . $links($l) . '</div>' : '') . '</div></div></div>';
};
?>
<h1 class="h3 mb-1">Help &amp; how-to</h1>
<p class="text-muted">Align keeps each client's technology picture in one place: what they have, what's changing, what it costs and what's coming up. Most of it fills itself in from ITFlow and NinjaOne; you add the planning.</p>

<ul class="nav nav-tabs settings-tabs mb-3" role="tablist">
  <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-workflow" role="tab"><i class="fas fa-route fa-fw mr-1"></i>Workflow</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-howto" role="tab"><i class="fas fa-list-check fa-fw mr-1"></i>How-to guides</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-menus" role="tab"><i class="fas fa-bars fa-fw mr-1"></i>Where things are</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-data" role="tab"><i class="fas fa-arrows-rotate fa-fw mr-1"></i>Data &amp; terms</a></li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="tab-workflow" role="tabpanel">
  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-route mr-2"></i>The vCIO workflow</h3></div>
    <div class="card-body">
      <h6 class="text-uppercase text-muted small font-weight-bold">Once, when you set up</h6>
      <?= $step(1, 'fa-plug', 'Connect your tools', 'Open <b>Integrations</b>, set up ITFlow and NinjaOne (and Veeam, email and warranty lookups if you use them), press <b>Test connection</b> on each, then run a sync. After that Align syncs hourly and checks ITFlow for asset, contact and license changes every 2 minutes.', $isAdmin ? ['Integrations' => '/integrations', 'Sync' => '/sync'] : ['Sync' => '/sync']) ?>
      <?= $step(2, 'fa-link', 'Link clients', 'Clients with the same name in each system link automatically. Link the rest (NinjaOne organization, Veeam company) on Client mapping. Clients you don\'t plan for (break-fix, vendors) can be removed from planning.', ['Client mapping' => '/mapping', 'Clients' => '/clients']) ?>
      <?= $step(3, 'fa-circle-question', 'Sort out unassigned hardware', 'ITFlow assets with a type Align doesn\'t recognize land in Unassigned hardware. Give them a type and ITFlow is updated to match.', ['Unassigned hardware' => '/devices/unassigned']) ?>
      <h6 class="text-uppercase text-muted small font-weight-bold mt-4">For each client (the overview's Planning checklist tracks this)</h6>
      <?= $step(4, 'fa-address-book', 'Contacts', 'Contacts come from ITFlow. Mark the <b>decision maker</b> and the people you <b>invite to reviews</b>. They can then be added to a meeting in one click.', ['Contacts' => '/contacts']) ?>
      <?= $step(5, 'fa-desktop', 'Devices & lifecycle', 'Check devices without an in-service date (they can\'t be planned) and set replacement costs where the policy default is wrong. Edits go to ITFlow automatically.', []) ?>
      <?= $step(6, 'fa-key', 'Licensing & contracts', 'Licenses sync from ITFlow\'s Software section without prices. Add the price and billing cycle, plus contract term, end and renegotiate-by dates.', ['Licenses needing a price' => '/licenses?filter=unpriced', 'Renewals' => '/renewals']) ?>
      <?= $step(7, 'fa-database', 'Backups', 'With Veeam connected, each client\'s Backups page shows job results, protected machines, Microsoft 365 and servers with no backup. Mark anything that doesn\'t need a backup so it stops counting as missing.', []) ?>
      <?= $step(8, 'fa-clipboard-check', 'Compliance & documents', 'Assign the frameworks the client must meet (including WISP / FTC Safeguards), work the checklist, and link written policies from Documents as evidence.', ['Compliance' => '/compliance', 'Documents' => '/documents']) ?>
      <?= $step(9, 'fa-road', 'Roadmap & projects', 'Add projects with a target quarter, budget and description. Hardware reaching end of life, OS end of support and warranty dates appear on the roadmap automatically.', ['Projects' => '/projects']) ?>
      <?= $step(10, 'fa-coins', 'Budget', 'The budget builds itself from licensing, hardware, projects and managed services. Add a <b>Managed services</b> line with your agreement amount, plus internet, phones, cloud and other costs.', ['Budgets' => '/budget']) ?>
      <?= $step(11, 'fa-door-open', 'Client portal (optional)', 'Invite the owner or office manager from the client\'s <b>Client portal</b> page. Choose what they can see and do. They sign in at <b>/portal</b> and only ever see their own company; their approvals show on the roadmap and the dashboard.', ['Client portal users' => '/portal-users']) ?>
      <?= $step(12, 'fa-handshake', 'Meet and report', 'Schedule the review (invitations go out as calendar invites if email is connected). The meeting page lists talking points; <b>Reports</b> builds the QBR pack and every other report for printing or PDF.', ['Meetings' => '/meetings', 'Reports' => '/reports']) ?>
    </div>
  </div>
</div>

<div class="tab-pane fade" id="tab-howto" role="tabpanel">
  <div class="d-flex align-items-center mb-2">
    <input type="search" class="form-control form-control-sm mr-2" style="max-width:320px" placeholder="Find a guide…" data-filter-guides aria-label="Find a guide">
    <span class="small text-muted">Click a guide to open it.<?= $isAdmin ? '' : ' Some tasks need a tech or admin account.' ?></span>
  </div>
  <h6 class="text-uppercase text-muted small font-weight-bold mt-3">Clients &amp; planning</h6>
  <?= $guide('add-client', 'fa-user-plus', 'Add a client or take one out of planning', 'tech', [
      'Clients from ITFlow appear on their own after a sync. To add one by hand: <b>Clients → New client</b> (or the <b>+</b> menu at the top).',
      'A hand-added client links to ITFlow automatically when a client with the same name shows up there.',
      'For break-fix clients or vendors: tick them on <b>Clients</b> and choose <b>Remove from planning</b>. They drop out of budgets, reports and reminders but nothing is deleted.',
  ], ['Clients' => '/clients']) ?>
  <?= $guide('unassigned', 'fa-circle-question', 'Categorize unassigned hardware', 'viewer', [
      'Open <b>Integrations → Unassigned hardware</b> (the badge shows how many are waiting).',
      'Tick the devices of one kind, choose a type and press <b>Apply</b>. ITFlow is updated to match.',
      'Things you don\'t plan for (monitors, cables) can be excluded from planning instead.',
  ], ['Unassigned hardware' => '/devices/unassigned']) ?>
  <?= $guide('lifecycle', 'fa-recycle', 'Plan device replacements', 'tech', [
      'Open the client\'s <b>Devices &amp; assets</b>. Filter <b>No plan date</b> to find devices without an in-service date.',
      'Open a device to set the purchase or in-service date, warranty, lifespan or replacement cost. These override the defaults.',
      'Default lifespans and costs per category are under <b>Settings → Planning &amp; lifecycle</b>.',
      'Replacements then land in the quarter each device reaches end of life, on the roadmap and in the 3-year plan.',
  ], $isAdmin ? ['Planning & lifecycle settings' => '/settings/planning'] : []) ?>
  <?= $guide('licenses', 'fa-key', 'Price licenses and track renewals', 'tech', [
      'Open <b>Licensing</b> and filter <b>Needs a price</b>.',
      'Edit each license: unit price, billing cycle, and contract term, end and renegotiate-by dates.',
      '<b>Renewals</b> lists everything ending or due for renegotiation in the coming months; admins can get a weekly email.',
  ], ['Licensing' => '/licenses?filter=unpriced', 'Renewals' => '/renewals']) ?>
  <?= $guide('project', 'fa-diagram-project', 'Add a project and get it approved', 'tech', [
      'Open the client\'s <b>Roadmap &amp; projects</b> (or <b>Projects → Add project</b>) and enter the title, target quarter, budget and a description the client will understand.',
      'Leave it <b>Proposed</b> until the client decides. With the client portal, they can approve or decline it themselves, and you\'re notified.',
      'Approved and scheduled projects roll into the budget for their quarter.',
  ], ['Projects' => '/projects']) ?>
  <?= $guide('budget', 'fa-coins', 'Build a client\'s budget', 'tech', [
      'Open the client\'s <b>Budget</b>. Hardware, licensing and projects fill in automatically.',
      'Add a <b>Managed services</b> line with your agreement amount (or let the ITFlow invoice estimate stand), plus internet, phones, cloud and other running costs.',
      'The budget year (calendar or fiscal) and whether the plan starts this year or next are under <b>Settings → Planning &amp; lifecycle</b>.',
  ], ['Budgets' => '/budget']) ?>
  <?= $guide('backups', 'fa-database', 'Check a client\'s backups', 'viewer', [
      'Open the client and choose <b>Backups</b>: health, failed jobs with Veeam\'s messages, overdue machines, Microsoft 365 coverage and servers with no backup.',
      'Something that genuinely doesn\'t need a backup (a test VM, a kiosk)? A tech can mark it <b>Backup not required</b> with a reason; it stops counting as missing everywhere.',
      '<b>Reports → Backup &amp; recovery</b> prints it for the client, and <b>Reports → Backups (all clients)</b> shows the whole portfolio.',
  ], ['Backups report (all clients)' => '/reports/backups']) ?>

  <h6 class="text-uppercase text-muted small font-weight-bold mt-4">Compliance &amp; documents</h6>
  <?= $guide('compliance', 'fa-clipboard-check', 'Run a compliance assessment', 'tech', [
      'Open the client\'s <b>Compliance</b> and assign a framework (HIPAA, CIS, FTC Safeguards, cyber insurance…).',
      'Work through the checklist: set each control\'s status, add notes, and link evidence (a document or a file).',
      'Some controls check themselves from Align data (backups, OS support, encryption). Export the checklist as CSV or include it in the QBR pack.',
  ], ['Compliance' => '/compliance']) ?>
  <?= $guide('frameworks', 'fa-list-check', 'Create or edit a compliance framework', 'admin', [
      'Open <b>Compliance → Frameworks</b>.',
      'Add a framework, then its controls (grouped by section). A control can be linked to an automatic check.',
  ], ['Frameworks' => '/frameworks']) ?>
  <?= $guide('document', 'fa-file-lines', 'Write a policy or WISP from a template', 'tech', [
      'Open the client\'s <b>Documents → New document</b> and pick a template (WISP, incident response, acceptable use…), or start blank.',
      'Edit in place; it saves as you type. Set it to <b>Active</b> when it\'s final and set a review date.',
      'Active policies are shared to the client portal by default. Print → Save as PDF for a signed copy.',
  ], ['Documents' => '/documents']) ?>

  <h6 class="text-uppercase text-muted small font-weight-bold mt-4">Meetings &amp; reports</h6>
  <?= $guide('meeting', 'fa-handshake', 'Schedule a review and send invitations', 'tech', [
      'Use the <b>+</b> menu → <b>Schedule meeting</b>, or the client\'s <b>Meetings</b>.',
      'Add attendees in one click from the client\'s review invitees, and tick <b>Email invitations to attendees</b>.',
      'With email connected, invitations are real Outlook or Google Calendar invites (with an optional Teams or Meet link); changes and cancellations follow automatically.',
      'Everyone\'s meetings are also on <b>Meetings → Calendar</b>, which you can subscribe to from Outlook or Google.',
  ], ['Meetings' => '/meetings', 'Calendar' => '/calendar']) ?>
  <?= $guide('qbr', 'fa-print', 'Prepare a QBR pack', 'viewer', [
      'Open <b>Reports</b>, pick the client, and choose the sections for the <b>Business review pack</b>.',
      'Press <b>Open report</b>, check it, then Print → Save as PDF. Every other report (assets, roadmap, budget, backups, compliance CSV) is on the same page.',
      'Tip: open reports from the meeting page too; it links the ones for that client.',
  ], ['Reports' => '/reports']) ?>
  <?= $guide('portal', 'fa-door-open', 'Invite someone from a client to the portal', 'tech', [
      'Open the client and choose <b>Client portal → Invite user</b>.',
      'Tick what they can see (roadmap, budget, devices, documents) and do (approve projects, update contacts).',
      'Align emails the invite if email is connected; otherwise copy the one-time link. They set a password and two-factor sign-in.',
  ], ['Client portal users' => '/portal-users']) ?>
  <?= $guide('my-emails', 'fa-bell', 'Choose which emails you get', 'viewer', [
      'Open <b>Account &amp; 2FA</b> from the menu under your name, then <b>Email notifications</b>.',
      'Turn each email on or off, and choose all clients or only the ones you\'re vCIO for.',
  ], ['Account' => '/account']) ?>

  <?php if ($isAdmin): ?>
  <h6 class="text-uppercase text-muted small font-weight-bold mt-4">Administration</h6>
  <?php endif; ?>
  <?= $guide('integration', 'fa-plug', 'Connect or change an integration', 'admin', [
      'Open <b>Integrations</b> and click the card (ITFlow, NinjaOne, Veeam, Microsoft 365 / Google Workspace, Dell, Lenovo).',
      'Follow <b>How to set it up</b> on the right, enter the details and <b>Save</b>. Keys are encrypted and never shown again; leave a key blank to keep it.',
      'Press <b>Test connection</b>, then run a sync (<b>Integrations → Sync</b>). Each card shows the result of the last sync.',
  ], ['Integrations' => '/integrations']) ?>
  <?= $guide('email', 'fa-envelope', 'Set up email and notifications', 'admin', [
      'Open <b>Integrations → Microsoft 365 / Google Workspace</b>, choose the provider and connection type, and follow the steps on the page. Send a test.',
      'Then open <b>Settings → Notifications</b> to choose which emails are sent, who gets them by default, when digests go out and how meeting invitations are sent.',
      'The <b>Email log</b> (on the same page) shows everything sent, queued or failed, with retry.',
  ], ['Mail connection' => '/integrations/email', 'Notifications' => '/settings/notifications']) ?>
  <?= $guide('users', 'fa-user-shield', 'Add staff and manage access', 'admin', [
      'Open <b>Users → New user</b>. Choose the role: <b>Viewer</b> reads, <b>Tech</b> edits clients and plans, <b>Admin</b> also manages settings, integrations and users.',
      'Everyone sets up two-factor sign-in at first sign-in. Locked out? An admin can use <b>Reset password</b> or <b>Remove 2FA</b> on the user list.',
      'Disable accounts as soon as someone leaves; the audit log keeps their history.',
  ], ['Users' => '/users']) ?>
  <?= $guide('update', 'fa-circle-arrow-up', 'Update Align', 'admin', [
      'When a new version is out you\'ll see a banner and a <b>new</b> badge on Settings.',
      'Open <b>Settings → Updates &amp; backups</b>, read what\'s new, tick the confirmation and press <b>Update</b>. It takes a minute or two and makes a safety copy first.',
      'From the server: <code>sudo mountaineer-align-update</code> does the same.',
  ], ['Updates & backups' => '/settings/system']) ?>
  <?= $guide('backup', 'fa-download', 'Back up and restore Align', 'admin', [
      'Backups aren\'t kept on the server. Open <b>Settings → Updates &amp; backups</b> and press <b>Download backup</b>; keep the file somewhere safe.',
      'To restore: upload the file under <b>Restore</b>, paste the backup private key (<code>AGE-SECRET-KEY-1…</code>) and press <b>Test this backup</b> first. <b>Restore</b> needs your two-factor code and signs everyone out.',
      'Keep the backup key in your password manager; <b>Check key</b> confirms it matches this server.',
  ], ['Updates & backups' => '/settings/system']) ?>
  <?= $guide('settings', 'fa-gear', 'Change planning defaults, branding or security', 'admin', [
      '<b>Settings → General</b>: company details on reports and sign-out timers.',
      '<b>Settings → Planning &amp; lifecycle</b>: budget year, meeting length, warning thresholds, warranty re-checks, lifespans and replacement costs. <b>OS support dates</b> has the Windows end-of-support table.',
      '<b>Settings → Branding</b>: app name, logo and colours (also used on the client portal and reports).',
  ], ['Settings' => '/settings']) ?>
  <?= $guide('audit', 'fa-clock-rotate-left', 'Review the audit log', 'admin', [
      'Open <b>Audit log</b>. Filter by person, client or action. Every change, sign-in, export and client-record view is recorded and hash-chained, so tampering is detected.',
      'Review it and the user lists at least quarterly.',
  ], ['Audit log' => '/audit']) ?>
  <p class="small text-muted mt-3 mb-0 d-none" data-guides-empty>No guide matches. Try a shorter word.</p>
</div>

<div class="tab-pane fade" id="tab-menus" role="tabpanel">
  <div class="row">
    <?php foreach ([
        ['fa-gauge-high', 'Top', [['Dashboard', 'What needs attention across all clients, the 3-year plan and each client\'s planning progress.'], ['Clients', 'Every client. Open one for its own menu: overview, contacts, devices, licensing, backups, compliance, documents, roadmap, budget, meetings and client portal.'], ['Contacts', 'Everyone at every client, searchable.']]],
        ['fa-diagram-project', 'Planning', [['Projects', 'All projects across clients by quarter.'], ['Budgets', 'Every client\'s technology budget side by side.'], ['Licensing', 'Every license, with prices and contract dates.'], ['Renewals', 'Licenses and contracts ending or up for renegotiation.']]],
        ['fa-handshake', 'Meetings & reports', [['Meetings', 'Upcoming and past meetings, with the calendar as a second tab.'], ['Reports', 'Pick a client and open any report: QBR pack, assets, roadmap, budget, backups, compliance.']]],
        ['fa-clipboard-check', 'Compliance', [['Compliance', 'Scores for every client and framework (admins also see the Frameworks tab).'], ['Documents', 'Internal and client documents, policies and templates.']]],
        ['fa-plug', 'Integrations', [['Integrations', 'Admins: connect and check ITFlow, NinjaOne, Veeam, email and warranty lookups.'], ['Client mapping', 'Which NinjaOne organization and Veeam company belongs to each client.'], ['Sync', 'Sync history and Run sync now.'], ['Unassigned hardware', 'ITFlow assets waiting for a type.']]],
        ['fa-user-shield', 'Admin', [['Settings', 'General, planning & lifecycle, OS support dates, notifications, branding, updates & backups.'], ['Users', 'Staff accounts and roles.'], ['Client portal users', 'Everyone with a client portal sign-in.'], ['Audit log', 'Who did what, and who viewed which client records.']]],
    ] as [$icon, $title, $items]): ?>
      <div class="col-md-6 col-xl-4 d-flex">
        <div class="card flex-fill">
          <div class="card-header py-2"><h3 class="card-title"><i class="fas <?= $icon ?> fa-fw text-secondary mr-2"></i><?= e($title) ?></h3></div>
          <div class="card-body small"><dl class="mb-0"><?php foreach ($items as [$k, $v]): ?><dt><?= e($k) ?></dt><dd class="text-muted"><?= e($v) ?></dd><?php endforeach; ?></dl></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="small text-muted">Top bar: search clients from any page, <b>+</b> to schedule a meeting or add a client or device, and your name for your account, two-factor and email choices.</p>
</div>

<div class="tab-pane fade" id="tab-data" role="tabpanel">
  <div class="row">
    <div class="col-xl-7">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-arrows-rotate mr-2"></i>Where data comes from</h3></div>
        <div class="card-body small">
          <table class="table table-sm mb-0">
            <tr><th>Clients &amp; address</th><td>ITFlow (read-only in Align)</td></tr>
            <tr><th>Contacts</th><td>ITFlow, <b>two-way</b>: detail edits (in Align or the client portal) go back to ITFlow</td></tr>
            <tr><th>Computers &amp; servers</th><td>NinjaOne</td></tr>
            <tr><th>Network gear, printers, UPS…</th><td>ITFlow assets, <b>two-way</b>: edits in either place sync</td></tr>
            <tr><th>Licenses</th><td>ITFlow Software (read-only); prices and contracts in Align</td></tr>
            <tr><th>Managed services estimate</th><td>ITFlow invoices, last 3 months</td></tr>
            <tr><th>Warranties</th><td>Dell / Lenovo lookups, or the ITFlow warranty date</td></tr>
            <tr><th>Backups</th><td>Veeam Service Provider Console (read-only), hourly: servers, VMs, agents and Microsoft 365</td></tr>
            <tr><th>Email &amp; invitations</th><td>Microsoft 365 or Google Workspace over OAuth, sent every minute</td></tr>
            <tr><th>Updates &amp; Align backups</th><td>GitHub checked every 6 hours; backups downloaded from Settings → Updates &amp; backups</td></tr>
            <tr><th>Projects, budget lines, compliance, documents, meetings</th><td>Align</td></tr>
          </table>
          <p class="mt-2 mb-0 text-muted">Fields marked <span class="badge badge-light border">ITFlow</span> are managed in ITFlow; change them there and they update here within minutes.</p>
        </div>
      </div>
    </div>
    <div class="col-xl-5">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-book mr-2"></i>Terms</h3></div>
        <div class="card-body small">
          <dl class="mb-0">
            <dt>3-year plan (hardware &amp; projects)</dt><dd>One-time spending: replacements in the quarter each device reaches end of life, plus project budgets.</dd>
            <dt>Technology budget</dt><dd>Everything: the 3-year plan plus licensing, managed services and running costs, by quarter.</dd>
            <dt>Planning checklist</dt><dd>On each client's overview: what's done and what's next before their plan and budget are complete.</dd>
            <dt>Unassigned</dt><dd>An ITFlow asset whose type needs choosing.</dd>
            <dt>Overdue backup</dt><dd>A machine or Microsoft 365 item whose newest restore point is older than the limit set on the Veeam integration (48 hours by default).</dd>
            <dt>Retired / archived</dt><dd>Hidden but kept. Sync never permanently deletes anything.</dd>
            <dt>Roles</dt><dd><b>Viewer</b> reads, <b>Tech</b> edits clients and plans and manages client portal access, <b>Admin</b> also manages settings, integrations and users.</dd>
            <dt>Client portal user</dt><dd>A sign-in for someone at a client. Separate from staff accounts; limited to one client and the sections you tick.</dd>
          </dl>
        </div>
      </div>
    </div>
  </div>
</div>
</div>

<?php echo str_replace(['Align keeps', 'Align syncs', 'Align doesn\'t', 'here in Align', 'in Align', '>Align<', 'Align emails', 'Update Align', 'restore Align', 'Align makes'], [e(\Align\Branding::name()) . ' keeps', 'it syncs', 'the app doesn\'t', 'here', 'in ' . e(\Align\Branding::name()), '>' . e(\Align\Branding::name()) . '<', e(\Align\Branding::name()) . ' emails', 'Update ' . e(\Align\Branding::name()), 'restore ' . e(\Align\Branding::name()), e(\Align\Branding::name()) . ' makes'], (string) ob_get_clean()); ?>

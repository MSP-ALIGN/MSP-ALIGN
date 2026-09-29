<?php ob_start(); ?>
<?php
use Align\Auth;

$isAdmin = Auth::can('admin');
$isTech = Auth::can('tech');
$rmm = \Align\Providers\Providers::rmmNames(...);
$bk = \Align\Providers\Providers::backupNames(...);
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
<p class="text-muted">Align keeps each client's technology picture in one place: what they have, what's changing, what it costs and what's coming up. Most of it fills itself in from <?= e(psa_name()) ?> and <?= e(\Align\Providers\Providers::rmmNames()) ?>; you add the planning.</p>

<?php if ($isAdmin): ?><p class="small text-muted"><i class="fas fa-book fa-fw mr-1"></i>Installing, updating, test servers and security: see the <a href="https://mspalign.org" target="_blank" rel="noopener">MSP-ALIGN documentation</a>.</p><?php endif; ?>
<ul class="nav nav-tabs settings-tabs mb-3" role="tablist">
  <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-workflow" role="tab"><i class="fas fa-route fa-fw mr-1"></i>Workflow</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-new" role="tab"><i class="fas fa-star fa-fw mr-1"></i>What's new</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-howto" role="tab"><i class="fas fa-list-check fa-fw mr-1"></i>How-to guides</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-menus" role="tab"><i class="fas fa-bars fa-fw mr-1"></i>Where things are</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-data" role="tab"><i class="fas fa-arrows-rotate fa-fw mr-1"></i>Data &amp; terms</a></li>
  <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-legal" role="tab"><i class="fas fa-scale-balanced fa-fw mr-1"></i>Terms &amp; license</a></li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="tab-workflow" role="tabpanel">
  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-route mr-2"></i>The vCIO workflow</h3></div>
    <div class="card-body">
      <h6 class="text-uppercase text-muted small font-weight-bold">Once, when you set up</h6>
      <?= $step(1, 'fa-plug', 'Connect your tools', 'Open <b>Integrations</b>, set up ' . (psa_on() ? psa_name() . ' and ' : '') . \Align\Providers\Providers::rmmNames() . (psa_on() ? '' : ' (a PSA is optional: see <i>Run MSP-ALIGN without a PSA</i>)') . ' (and ' . $bk() . ', email and warranty lookups if you use them), press <b>Test connection</b> on each, then run a sync. Using SLAs in ' . psa_name() . '? Turn on <b>Service levels</b> on the ' . psa_name() . ' card. After that Align syncs hourly and checks ' . psa_name() . ' for asset, contact and license changes every 2 minutes. Then set up your welcome email and onboarding guides under <b>Settings → Onboarding</b> (or import a saved set).', $isAdmin ? ['Integrations' => '/integrations', 'Sync' => '/sync', 'Settings → Onboarding' => '/settings/onboarding'] : ['Sync' => '/sync']) ?>
      <?= $step(2, 'fa-link', 'Link clients', 'Clients with the same name in each system link automatically. Link the rest (' . \Align\Providers\Providers::rmmNames() . ' organization, ' . $bk() . ' company) on Client mapping. Clients you don\'t plan for (break-fix, vendors) can be removed from planning.', ['Client mapping' => '/mapping', 'Clients' => '/clients']) ?>
      <?= $step(3, 'fa-circle-question', 'Sort out unassigned hardware', psa_name() . ' assets with a type Align doesn\'t recognize land in Unassigned hardware. Give them a type and ' . psa_name() . ' is updated to match.', ['Unassigned hardware' => '/devices/unassigned']) ?>
      <h6 class="text-uppercase text-muted small font-weight-bold mt-4">For each new client</h6>
      <?= $step(4, 'fa-mountain-sun', 'Welcome and onboard', 'When they sign, choose <b>⋮ → Welcome &amp; onboarding</b> on the client. The welcome email links to a private page (no sign-in) where they enter their team\'s contacts, read how to reach you and how billing works, answer a few getting-started questions and send new user or termination requests. Track their progress on the client\'s <b>Onboarding</b> page.', ['How onboarding works' => '#guide-onboarding']) ?>
      <h6 class="text-uppercase text-muted small font-weight-bold mt-4">For each client (the overview's Planning checklist tracks this)</h6>
      <?= $step(5, 'fa-address-book', 'Contacts', 'Contacts come from ' . psa_name() . ' (and from onboarding). Mark the <b>decision maker</b> and the people you <b>invite to reviews</b>. They can then be added to a meeting in one click.', ['Contacts' => '/contacts']) ?>
      <?= $step(6, 'fa-desktop', 'Devices & lifecycle', 'Check devices without an in-service date (they can\'t be planned) and set replacement costs where the policy default is wrong. Edits go to ' . psa_name() . ' automatically.', ['How to plan replacements' => '#guide-lifecycle']) ?>
      <?= $step(7, 'fa-key', 'Licensing & contracts', 'Licenses sync from ' . psa_name() . ' without prices. Add the price and billing cycle, plus contract term, end and renegotiate-by dates.', ['Licenses needing a price' => '/licenses?filter=unpriced', 'Renewals' => '/renewals']) ?>
      <?= $step(8, 'fa-database', 'Backups & service levels', 'With ' . $bk() . ' connected, each client\'s <b>Backups</b> page shows job results, protected machines, Microsoft 365 and servers with no backup. With ' . psa_name() . ' SLAs turned on, <b>Service levels</b> shows how many tickets were answered and resolved on time and which missed.', ['Backups (all clients)' => '/reports/backups', 'Service levels (all clients)' => '/reports/sla']) ?>
      <?= $step(9, 'fa-clipboard-check', 'Compliance & documents', 'Assign the frameworks the client must meet (CMMC, NIST CSF, CIS, HIPAA, PCI, SOC 2, ISO 27001, WISP and more), work the checklist and let the <b>crosswalk</b> reuse answers across frameworks. Write policies from the templates in Documents and link them as evidence.', ['Compliance' => '/compliance', 'Documents' => '/documents']) ?>
      <?= $step(10, 'fa-road', 'Roadmap & projects', 'Add projects with a target quarter, budget and description. Hardware reaching end of life, OS end of support and warranty dates appear on the roadmap automatically.', ['Projects' => '/projects']) ?>
      <?= $step(11, 'fa-coins', 'Budget', 'The budget builds itself from licensing, hardware, projects and managed services. Add a <b>Managed services</b> line with your agreement amount, plus internet, phones, cloud and other costs.', ['Budgets' => '/budget']) ?>
      <?= $step(12, 'fa-door-open', 'Client portal (optional)', 'Invite the owner or office manager from the client\'s <b>Client portal</b> page. Choose what they can see and do. They sign in at <b>/portal</b> and only ever see their own company; their approvals and requests show up for you, and their approvals appear on the roadmap and the dashboard.', ['Client portal users' => '/portal-users']) ?>
      <?= $step(13, 'fa-handshake', 'Meet and report', 'Schedule the review (invitations go out as calendar invites if email is connected). The meeting page lists talking points; <b>Reports</b> builds the QBR pack (now with a Service levels section) and every other report for printing or PDF.', ['Meetings' => '/meetings', 'Reports' => '/reports']) ?>
      <h6 class="text-uppercase text-muted small font-weight-bold mt-4">Every day</h6>
      <?= $step(14, 'fa-gauge-high', 'Work from the dashboard', 'Start with <b>Needs attention</b> on the dashboard: failed backups, missed service levels, stalled onboardings, decisions waiting on a client, renewals, compliance gaps and hardware due for replacement, across every client and most urgent first. Press <b>Customize</b> to arrange the rest of the dashboard your way.', ['Dashboard' => '/', 'How to customize it' => '#guide-dashboard']) ?>
    </div>
  </div>
</div>

<div class="tab-pane fade" id="tab-new" role="tabpanel">
  <p class="text-muted small">The biggest recent additions. Full release notes for each version are under <?= $isAdmin ? '<a href="/settings/system">Settings → Updates &amp; backups</a>' : 'Settings → Updates &amp; backups (admins)' ?>.</p>
  <div class="list-group mb-3">
    <?php foreach ([
        ['1.36', 'fa-circle-nodes', 'No PSA needed', 'Run MSP-ALIGN with just your RMM: add clients from its organizations on Client mapping, or import clients and contacts from a CSV file (Clients → Import). Screens leave out what only a PSA provides.', 'no-psa', 'tech'],
        ['1.35', 'fa-folder-tree', 'Server folders renamed', 'On the server, MSP-ALIGN now lives in /opt/msp-align, /etc/msp-align and /var/lib/msp-align, and its services and logs are named msp-align. The update moved everything in place; the old mountaineer-align folder names and commands still work.', 'update', 'admin'],
        ['1.32.1', 'fa-flask', 'Test servers', 'A test server can run the next version on a copy of your data in staging mode: it reads from your tools as usual, but writes nothing back, sends email only to one test mailbox and keeps the client portal off. See docs/TEST-SERVER.md.', 'update', 'admin'],
        ['1.31', 'fa-link', 'One mapping screen for every tool', 'Client mapping now works the same for every connected tool: a card per tool with how many clients are linked and which records aren\'t, a <b>Missing a link</b> view, and plain labels for how each link was made. Ready for more tools later.', 'mapping', 'tech'],
        ['1.30.1', 'fa-code-branch', 'A new home on GitHub', 'MSP-ALIGN now lives at github.com/MSP-ALIGN/MSP-ALIGN. This update switches your server to the new address automatically, so future updates come from there.', 'integration', 'admin'],
        ['1.30', 'fa-database', 'Ready for other backup products', 'Veeam now plugs in as one backup provider, and Align can read more than one backup product at a time. Each client links to a company in each product on Client mapping, and hosted backups work across all of them. Nothing changes in how Align works for you today.', 'integration', 'admin'],
        ['1.29', 'fa-satellite-dish', 'Ready for other RMMs', 'NinjaOne now plugs in as one RMM provider, and Align can run more than one RMM at a time (handy while moving clients from one RMM to another). Each client links to an organization in each RMM on Client mapping. Nothing changes in how Align works for you today.', 'integration', 'admin'],
        ['1.28', 'fa-plug-circle-check', 'Ready for other PSAs', 'ITFlow now plugs in as one PSA provider behind a common interface, so other PSAs (ConnectWise, HaloPSA, Autotask…) can feed the same screens later. Nothing changes in how Align works for you today.', 'integration', 'admin'],
        ['1.27', 'fa-code', 'REST API', 'Read and write planning data from n8n, Zapier, Power Automate, AI agents and your own scripts. Keys with per-area permissions, client limits, expiry and rate limits, under Settings → API.', 'api', 'admin'],
        ['1.26', 'fa-building', 'Hosted clients\' backups', 'Servers you host and back up on your own Veeam server now show for the right client: matched by device name automatically, with Client mapping → Hosted backups to assign jobs or machines by hand.', 'hosted-backups', 'tech'],
        ['1.25', 'fa-list-ol', 'Reports in meeting order', 'The QBR pack now runs the way the meeting does: look back, what they have, is it protected, where it\'s going, what it costs, then decisions. Servers, hypervisor hosts and virtual servers sit together, and the budget, roadmap and backup reports read top to bottom.', 'qbr', 'viewer'],
        ['1.24', 'fa-signature', 'Renamed to MSP-ALIGN', 'Mountaineer Align is now MSP-ALIGN. Nothing to do: your data, settings and branding are unchanged. On the server the commands are now sudo msp-align-update and msp-align-restore (the old names still work).', 'update', 'admin'],
        ['1.23', 'fa-bars-progress', 'Progress window for updates and restores', 'Updating or restoring now shows each step, a progress bar and the time so far, and reloads on its own when it\'s done. Everyone else sees a Please wait page that refreshes itself.', 'update', 'admin'],
        ['1.22', 'fa-mountain-sun', 'Client onboarding', 'Send a welcome email with a private onboarding page: the client enters their contacts, reads how to reach you and how billing works, answers getting-started questions and sends new user or termination requests. Track progress on the client\'s Onboarding page; edit everything under Settings → Onboarding.', 'onboarding', 'tech'],
        ['1.22', 'fa-user-plus', 'New user and termination requests', 'Online request forms on the onboarding page and in the client portal. Each request becomes a ticket in ' . psa_name() . '.', 'requests', 'tech'],
        ['1.21', 'fa-gauge-high', 'A new dashboard', 'Needs attention lists what to act on across every client, portfolio health tiles give the big picture, and Customize lets everyone reorder or hide cards.', 'dashboard', 'viewer'],
        ['1.20', 'fa-stopwatch', 'Service levels from ' . psa_name(), 'Response and resolution on time, missed tickets and trends per client, on the dashboard and meeting prep, in a printable report, in the QBR pack and in the client portal.', 'service-levels', 'viewer'],
        ['1.19', 'fa-clipboard-check', 'More frameworks and the crosswalk', 'CMMC Level 1 and 2, NIST CSF 2.0, CIS v8.1, PCI DSS, SOC 2, ISO 27001, HIPAA, CCPA/CPRA and more. The crosswalk reuses answers across a client\'s frameworks.', 'compliance', 'tech'],
        ['1.19', 'fa-file-lines', 'More document templates', 'Security policies, incident response, BCDR, the CMMC package (SSP, POA&M, CUI handling) and HIPAA and privacy templates.', 'document', 'tech'],
    ] as [$ver, $icon, $title, $text, $g, $who]): $canOpen = Auth::can($who); ?>
      <<?= $canOpen ? 'a' : 'div' ?> class="list-group-item<?= $canOpen ? ' list-group-item-action' : '' ?> d-flex"<?= $canOpen ? ' href="#guide-' . e($g) . '"' : '' ?>>
        <i class="fas <?= $icon ?> fa-fw text-secondary mr-3 mt-1"></i>
        <div class="flex-grow-1"><div class="d-flex flex-wrap align-items-center"><b class="mr-2"><?= e($title) ?></b><span class="badge badge-light border">v<?= e($ver) ?></span></div><div class="small text-muted"><?= e($text) ?></div></div>
        <?php if ($canOpen): ?><i class="fas fa-angle-right text-muted ml-2 mt-1"></i><?php endif; ?>
      </<?= $canOpen ? 'a' : 'div' ?>>
    <?php endforeach; ?>
  </div>
</div>

<div class="tab-pane fade" id="tab-howto" role="tabpanel">
  <div class="d-flex align-items-center mb-2">
    <input type="search" class="form-control form-control-sm mr-2" style="max-width:320px" placeholder="Find a guide…" data-filter-guides aria-label="Find a guide">
    <span class="small text-muted">Click a guide to open it.<?= $isAdmin ? '' : ' Some tasks need a tech or admin account.' ?></span>
  </div>
  <h6 class="text-uppercase text-muted small font-weight-bold mt-3">Clients &amp; planning</h6>
  <?= $guide('dashboard', 'fa-gauge-high', 'Use and customize the dashboard', 'viewer', [
      '<b>Needs attention</b> at the top lists what to act on across every client, most urgent first (red, then amber, then blue). Use the buttons to show one area, such as Backups or Renewals. Each line opens the page that fixes it.',
      '<b>Portfolio health</b> has four groups of tiles: service &amp; backups, lifecycle &amp; security, client engagement and money. Each tile opens the matching list or report.',
      'Press <b>Customize</b> to drag cards by the handle (or use the arrows) into the order you want, across the full-width row and the two columns, and the eye button to hide a card. Hidden cards are listed in the Customize bar to add back. Press <b>Done</b> to save; <b>Reset to default</b> restores the standard layout. Everyone has their own layout.',
  ], ['Dashboard' => '/']) ?>
  <?= $guide('onboarding', 'fa-mountain-sun', 'Welcome and onboard a new client', 'tech', [
      'Once they\'re officially a client, open the client and choose <b>⋮ → Welcome &amp; onboarding</b>. Pick who gets the welcome email, set the onsite week, adjust the message for this client if you like, and press <b>Send welcome email</b>. No email connected? Press <b>Create link only</b> and paste the link into your own email.',
      'The email has a <b>Start onboarding</b> button that opens a private page (no sign-in; the link works for 30 days by default and sending again replaces it). There the client fills in <b>their team\'s contacts</b> (or pastes them from a spreadsheet), marks who approves changes, gets invoices and is the main IT contact, reads <b>how to reach you, billing and email security</b> and confirms, gives <b>getting-started details</b> (current IT provider, preferred onsite week, pain points), and can send <b>new user</b> or <b>user termination</b> requests.',
      'Contacts go straight into the client\'s contacts and ' . psa_name() . ' (with two-way sync on). Requests become ' . psa_name() . ' tickets, or an email to your company address without ' . psa_name() . '. You get a notification as they go.',
      'Follow along on the client\'s <b>Onboarding</b> page: what\'s done, their answers, and their requests. The dashboard flags onboardings that stall or whose link expired. You can turn the link off, send it again or mark onboarding complete.',
      'Admins edit the welcome email and the page\'s guides in <b>Settings → Onboarding</b> (with placeholders like the client\'s name and your phone), attach a PDF to a guide, and import or export the whole set. Request forms can be turned off there; they\'re also in the client portal for users who can edit contacts.',
  ], ['Settings → Onboarding' => '/settings/onboarding']) ?>
  <?= $guide('add-client', 'fa-user-plus', 'Add a client or take one out of planning', 'tech', [
      'Clients from ' . psa_name() . ' appear on their own after a sync. To add one by hand: <b>Clients → New client</b> (or the <b>+</b> menu at the top).',
      'A hand-added client links to ' . psa_name() . ' automatically when a client with the same name shows up there.',
      'For break-fix clients or vendors: tick them on <b>Clients</b> and choose <b>Remove from planning</b>. They drop out of budgets, reports and reminders but nothing is deleted.',
  ], ['Clients' => '/clients']) ?>
  <?= $guide('unassigned', 'fa-circle-question', 'Categorize unassigned hardware', 'viewer', [
      'Open <b>Integrations → Unassigned hardware</b> (the badge shows how many are waiting).',
      'Tick the devices of one kind, choose a type and press <b>Apply</b>. ' . psa_name() . ' is updated to match.',
      'Things you don\'t plan for (monitors, cables) can be excluded from planning instead.',
  ], ['Unassigned hardware' => '/devices/unassigned']) ?>
  <?= $guide('lifecycle', 'fa-recycle', 'Plan device replacements', 'tech', [
      'Open the client\'s <b>Devices &amp; assets</b>. Filter <b>No plan date</b> to find devices without an in-service date.',
      'Open a device to set the purchase or in-service date, warranty, lifespan or replacement cost. These override the defaults.',
      'Client wants to keep it longer, or replace it sooner? On the client\'s <b>Roadmap &amp; projects</b>, open a quarter\'s <b>Replace … devices</b> list and drag a device (or the whole group by its heading) to another quarter. You can also use <b>Replace in</b> on the device, or tick several on the devices list and choose <b>Set replacement</b> to pick a quarter and note why. The roadmap, 3-year plan and budget move it there; a device past end of life that was put off shows as <b>Replacement deferred</b>. Choose <b>Automatic</b> to go back to the end-of-life date.',
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
      'Change your mind about timing? Drag the project to another quarter on the roadmap (or from the backlog into a quarter).',
      'Leave it <b>Proposed</b> until the client decides. With the client portal, they can approve or decline it themselves, and you\'re notified.',
      'Approved and scheduled projects roll into the budget for their quarter.',
  ], ['Projects' => '/projects']) ?>
  <?= $guide('budget', 'fa-coins', 'Build a client\'s budget', 'tech', [
      'Open the client\'s <b>Budget</b>. Hardware, licensing and projects fill in automatically.',
      'Add a <b>Managed services</b> line with your agreement amount (or let the ' . psa_name() . ' invoice estimate stand), plus internet, phones, cloud and other running costs.',
      'The budget year (calendar or fiscal) and whether the plan starts this year or next are under <b>Settings → Planning &amp; lifecycle</b>.',
  ], ['Budgets' => '/budget']) ?>
  <?= $guide('backups', 'fa-database', 'Check a client\'s backups', 'viewer', [
      'Open the client and choose <b>Backups</b>: health, failed jobs with the backup product\'s messages, overdue machines, Microsoft 365 coverage and servers with no backup.',
      'Something that genuinely doesn\'t need a backup (a test VM, a kiosk)? A tech can mark it <b>Backup not required</b> with a reason; it stops counting as missing everywhere.',
      '<b>Reports → Backup &amp; recovery</b> prints it for the client, and <b>Reports → Backups (all clients)</b> shows the whole portfolio.',
  ], ['Backups report (all clients)' => '/reports/backups']) ?>
  <?= $guide('mapping', 'fa-link', 'Link clients to your RMM and backup records', 'tech', [
      'Every connected tool that keeps its own list of customers gets a column on <b>Client mapping</b> (under Integrations): an organization in each RMM, a company in each backup product. The cards at the top show how many clients each tool has linked and which of its records aren\'t linked to anyone.',
      'On every sync, a client with no link yet is linked to the record with the <b>same name</b> (ignoring punctuation and words like Inc or LLC). Backup companies also match the client\'s RMM organization name. <b>How</b> shows <i>by name</i> for these.',
      'Pick a record to link a client by hand (<i>by hand</i>), or <b>— Not linked —</b> to keep it unlinked (<i>kept unlinked</i>): sync then leaves it alone. Each record can belong to one client only; one already used shows <i>(linked elsewhere)</i>. To move it, set the other client to <b>— Not linked —</b> in the same save.',
      'Open <b>Missing a link</b> to see only clients that still need a link in some tool, then press <b>Save mapping</b>. A client\'s devices come from its RMM organization, and its backups from its backup company, so fixing a link updates both straight away.',
  ], ['Client mapping' => '/mapping']) ?>
  <?= $guide('no-psa', 'fa-circle-nodes', 'Run MSP-ALIGN without a PSA', 'tech', [
      'A PSA is optional. Without one, connect your RMM under <b>Integrations</b> and run a sync: its organizations and devices come in, and screens leave out what only a PSA provides (tickets and service levels, invoices, two-way asset sync).',
      'Get your clients in: on <b>Client mapping</b>, press <b>Add clients from organizations</b> to make a client for each RMM organization, already linked. Admins can turn on adding clients for new organizations on every sync. An organization becomes a client once; if you delete that client, it isn\'t added again.',
      'Or import them: <b>Clients → Import</b> takes a CSV file of clients, then one of contacts (with the client\'s name in a Client column). You see what each row will do before anything is saved, and a client or contact that\'s already there is updated rather than duplicated. Download a template from the same page.',
      'Add licenses, budget lines and projects as usual. If you connect a PSA later, clients with the same name are linked to it automatically on the next sync.',
  ], ['Client mapping' => '/mapping', 'Import' => '/clients/import']) ?>
  <?= $guide('hosted-backups', 'fa-building', 'Match hosted clients\' backups on your own backup server', 'tech', [
      'When you host a client\'s servers and back them up on your own backup server (BDR), ' . $bk() . ' files those backups under your company. Align sorts them into clients on every sync: a machine goes to the client that has a device with the <b>same name</b> in ' . \Align\Providers\Providers::rmmNames() . ' or ' . psa_name() . ' (a full name like <i>server.client.local</i> matches too). Jobs mapped to a company in Veeam Service Provider Console already go to the right client.',
      'Open <b>Hosted backups</b> in the menu (under Integrations; the badge counts machines not matched yet), or follow the dashboard\'s <i>Needs attention</i> item. It opens on <b>Not matched</b>; the other tabs show what\'s sorted into clients and what\'s yours.',
      'Quickest from a client: on the client\'s <b>Backups</b> page, <b>Backed up on your own server?</b> lists unmatched machines and jobs, with the ones that look like that client (its initials, a word from its name, or one of its servers with no backup) first. Press <b>This client\'s</b>.',
      'On Hosted backups, assign a <b>job</b> to a client when it only backs up that client (machines added to the job later follow it), or pick the client for a <b>machine</b>. Tick several machines to set them at once. Choose <b>Ours — not a client</b> for your own servers so they stop showing as unmatched. What you set is kept across syncs.',
      'A job that backs up several clients counts for each of them. The client reports and portal leave out its error details, since they can name other clients\' machines; your staff pages still show them.',
      'If your own company is also linked to a client (you\'re set up as a client in ' . psa_name() . '), switch on <b>This is our backup server</b> for it on the same page, so its machines are sorted too.',
      'Sorted machines count everywhere the client\'s own backups do (Backups page, reports, QBR, portal, dashboard, emails) and are marked <b>Hosted</b>. They also clear the client\'s servers from <i>Servers with no backup</i>.',
  ], ['Hosted backups' => '/mapping/backups']) ?>
  <?= $guide('service-levels', 'fa-stopwatch', 'Review a client\'s service levels (SLA)', 'viewer', [
      'Set up SLAs in ' . psa_name() . ' first (response and resolution targets per priority, assigned to clients or as the default; with ITFlow this needs 26.08 or later, under <i>Admin → SLAs</i>). Turn on <b>Service levels</b> under <b>Integrations → ' . psa_name() . '</b> and set your goal (90% by default). Tickets come in with the hourly sync.',
      'Open the client and choose <b>Service levels</b>: responded and resolved on time (with the change from the period before), tickets opened, open tickets past or close to target, 12 months by month, results by priority and every ticket that missed a target. Ticket numbers open the ticket in ' . psa_name() . '.',
      'The client overview, dashboard and meeting prep show the last 90 days. <b>Reports → Service levels</b> prints it for the client (the missed-ticket list can be switched off), the QBR pack has a Service levels section, and <b>Reports → Service levels (all clients)</b> ranks every client. Portal users with Devices &amp; compliance access see the summary and can print the report, never the ticket list.',
      'Targets are measured by ' . psa_name() . ' in business hours and the resolution clock pauses while a ticket is on hold; results are shown as ' . psa_name() . ' calculated them. Average times are clock time. The app\'s timezone (config) should match ' . psa_name() . '\'s. Only ticket numbers, subjects, priorities and SLA times are copied, never ticket details.',
  ], ['Service levels report (all clients)' => '/reports/sla']) ?>

  <h6 class="text-uppercase text-muted small font-weight-bold mt-4">Compliance &amp; documents</h6>
  <?= $guide('compliance', 'fa-clipboard-check', 'Run a compliance assessment', 'tech', [
      'Open the client\'s <b>Compliance</b> and assign the frameworks they must meet: CMMC Level 1 or 2, NIST CSF 2.0, CIS Controls v8.1 (IG1 or IG2), PCI DSS 4.0.1, SOC 2, ISO/IEC 27001:2022, HIPAA, CCPA/CPRA, FTC Safeguards (WISP), the Microsoft 365 baseline, cyber insurance or the MSP baseline.',
      'Work through the checklist: set each control\'s status, add notes, and link evidence (a document or a file). Each control\'s guidance says what "met" looks like and the evidence to collect.',
      '<b>Crosswalk:</b> when a client has more than one framework, each control lists the matching controls in the others (<i>Matches N controls</i>). Press <b>Use this answer</b> to copy a status, notes, evidence and linked document, or <b>Fill from matching answers</b> to fill every not-assessed control that has a strong, consistent match. Filled rows are highlighted; review them, then save. Nothing is saved until you press Save.',
      'Some controls check themselves from synced data (backups, OS support, encryption). Export the checklist as CSV or include it in the QBR pack.',
  ], ['Compliance' => '/compliance']) ?>
  <?= $guide('frameworks', 'fa-list-check', 'Create or edit a compliance framework', 'admin', [
      'Open <b>Compliance → Frameworks</b>.',
      'Add a framework, then its controls (grouped by section). A control can be linked to an automatic check.',
      'To include your own framework in the crosswalk, give its controls <b>crosswalk tags</b> (pick from the list, e.g. <code>iam_mfa</code>, <code>backup_offsite</code>). Controls in different frameworks that share tags are suggested to each other. <b>Copy from</b> an existing framework keeps its tags.',
  ], ['Frameworks' => '/frameworks']) ?>
  <?= $guide('document', 'fa-file-lines', 'Write a policy or WISP from a template', 'tech', [
      'Open the client\'s <b>Documents → New document</b> and pick a template, or start blank. Templates include WISP, incident response, BCDR, backup, risk assessment, 12 core security policies, the CMMC package (SSP, POA&amp;M, CUI handling, Level 1 affirmation) and HIPAA/privacy (risk analysis, breach notification, BAA checklist, CCPA privacy policy). Link the finished document as evidence on the matching controls.',
      'Templates are starting points, not legal advice: fill in the [bracketed] items and have policies that carry legal obligations (privacy notices, breach procedures, BAAs) reviewed by the client\'s counsel.',
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
      'Open <b>Reports</b>, pick the client, and choose the sections for the <b>Business review pack</b>. It runs in meeting order: executive summary, service levels (how we did), assets and licensing (what they have, with servers, hosts and virtual servers together), backups and compliance (is it protected), roadmap and budget (where it\'s going and what it costs), then <b>Decisions &amp; next steps</b> with a tick box for each project awaiting approval. The full inventory is an appendix.',
      'Press <b>Open report</b>, check it, then Print → Save as PDF. Every other report (assets, roadmap, budget, backup &amp; recovery, service levels, compliance CSV, device list) is on the same page. Reports with a period, such as service levels, let you pick it at the top of the printed page.',
      'The <b>All clients (internal)</b> reports compare the whole portfolio: summary, backup status, service levels and contracts &amp; renewals. They\'re for your team, not clients.',
      'Tip: open reports from the meeting page too; it links the ones for that client.',
  ], ['Reports' => '/reports']) ?>
  <?= $guide('portal', 'fa-door-open', 'Invite someone from a client to the portal', 'tech', [
      'Open the client and choose <b>Client portal → Invite user</b>.',
      'Tick what they can see (roadmap, budget, devices &amp; compliance, documents) and do (approve projects, update contacts).',
      'Align emails the invite if email is connected; otherwise copy the one-time link. They set a password and two-factor sign-in.',
      'Users who can update contacts also get <b>Requests</b> in the portal to send new user and termination requests. Users with devices &amp; compliance access see the service levels summary and can print the report (never the ticket list).',
  ], ['Client portal users' => '/portal-users']) ?>
  <?= $guide('requests', 'fa-user-plus', 'Handle new user and termination requests', 'tech', [
      'Clients send these from the onboarding page or the portal\'s <b>Requests</b>. A new user request asks for the name, job title, start date, location, supervisor, login name and whose permissions to copy; a suspend or termination request asks when to disable the account, whether it\'s temporary, and what to do with their email, remote access, groups and files.',
      'With ' . psa_name() . ' connected, each request becomes a ticket in ' . psa_name() . ' for that client, so it lands in your normal queue. Without ' . psa_name() . ', it\'s emailed to the company email in <b>Settings → General</b>. You also get a notification.',
      'Requests from onboarding are listed on the client\'s <b>Onboarding</b> page. Admins can turn request forms off in <b>Settings → Onboarding</b>.',
  ], ['Settings → Onboarding' => '/settings/onboarding']) ?>
  <?= $guide('my-emails', 'fa-bell', 'Choose which emails you get', 'viewer', [
      'Open <b>Account &amp; 2FA</b> from the menu under your name, then <b>Email notifications</b>.',
      'Turn each email on or off, and choose all clients or only the ones you\'re vCIO for.',
  ], ['Account' => '/account']) ?>

  <?php if ($isAdmin): ?>
  <h6 class="text-uppercase text-muted small font-weight-bold mt-4">Administration</h6>
  <?php endif; ?>
  <?= $guide('integration', 'fa-plug', 'Connect or change an integration', 'admin', [
      'Open <b>Integrations</b> and click the card (' . psa_name() . ', ' . $rmm(', ') . ', ' . $bk(', ') . ', Microsoft 365 / Google Workspace, Dell, Lenovo).',
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
      'Open <b>Settings → Updates &amp; backups</b>, read what\'s new, tick the confirmation and press <b>Update</b>. It makes a safety copy first and usually takes a few minutes.',
      'A progress window shows each step (safety copy, download, install, final checks) with a progress bar and the time so far. Leave the page open; it reloads on its own when the update is done. While it runs, everyone else sees a <b>Please wait</b> page that also refreshes itself.',
      'The web server restarts near the end, so the window may briefly say <i>Restarting the web server…</i>. That\'s normal. If something fails, the window says so; the details, the job log and any safety copy are on the same page.',
      'From the server: <code>sudo msp-align-update</code> does the same.',
  ], ['Updates & backups' => '/settings/system']) ?>
  <?= $guide('backup', 'fa-download', 'Back up and restore Align', 'admin', [
      'Backups aren\'t kept on the server. Open <b>Settings → Updates &amp; backups</b> and press <b>Download backup</b>; keep the file somewhere safe.',
      'To restore: upload the file under <b>Restore</b>, paste the backup private key (<code>AGE-SECRET-KEY-1…</code>) and press <b>Test this backup</b> first. <b>Restore</b> needs your two-factor code and signs everyone out. The same progress window follows the restore and reloads when it\'s done.',
      'Keep the backup key in your password manager; <b>Check key</b> confirms it matches this server.',
  ], ['Updates & backups' => '/settings/system']) ?>
  <?= $guide('settings', 'fa-gear', 'Change settings: planning, onboarding, branding and more', 'admin', [
      '<b>Settings → General</b>: company details on reports and sign-out timers.',
      '<b>Settings → Planning &amp; lifecycle</b>: budget year, meeting length, warning thresholds, warranty re-checks, lifespans and replacement costs. <b>OS support dates</b> has the Windows end-of-support table.',
      '<b>Settings → Notifications</b>: which emails are sent and when (see <a href="#guide-email">Set up email</a>).',
      '<b>Settings → Onboarding</b>: the welcome email, the guides on the onboarding page, how long links last, and whether request forms are on (see <a href="#guide-onboarding">Welcome and onboard</a>).',
      '<b>Settings → Branding</b>: app name, logo and colours (also used on the client portal, onboarding page and reports).',
      '<b>Settings → API</b>: turn the REST API on and manage its keys (see <a href="#guide-api">Use the API</a>).',
      '<b>Settings → Updates &amp; backups</b>: new versions, downloading a backup and restoring one.',
  ], ['Settings' => '/settings']) ?>
  <?= $guide('api', 'fa-code', 'Use the API (n8n, Zapier, AI agents, scripts)', 'admin', [
      'Open <b>Settings → API</b> and press <b>Turn on</b>. The API lives at <code>/api/v1</code> and speaks JSON.',
      'Press <b>New API key</b>. Name it after the tool that will use it, tick only the permissions it needs (<b>read</b> or <b>write</b> per area; presets help), and limit it to certain clients if it only works with a few. Set an expiry and a rate limit, then <b>Create key</b>.',
      'Copy the key straight away; it\'s shown once. In the tool, send it as the header <code>Authorization: Bearer msa_…</code>. For AI agents and tools that import API descriptions, use the <b>OpenAPI (JSON)</b> file.',
      '<b>API reference</b> on the same page lists every endpoint with its parameters, fields and a ready-to-run example. Changes go through the same rules as the screens: device edits reach ' . psa_name() . ', meeting invitations only go out when asked, and fields ' . psa_name() . ' manages stay read-only.',
      'Every change made with a key is in the audit log with the key\'s name, and every request is in <b>Recent requests</b> for 30 days. Open a key to change what it may do, or <b>Revoke</b> it the moment it\'s no longer needed or might have leaked. The dashboard warns two weeks before a key expires. A key stops working when the person who created it is disabled; create a new one under an active admin.',
      'Keys limited to certain clients can\'t send meeting invitations, pick a meeting owner or see hosted-backup mapping; use a key for all clients for those.',
  ], ['Settings → API' => '/settings/api', 'API reference' => '/settings/api/docs']) ?>
  <?= $guide('audit', 'fa-clock-rotate-left', 'Review the audit log', 'admin', [
      'Open <b>Audit log</b>. Filter by person, client or action. Every change, sign-in, export and client-record view is recorded and hash-chained, so tampering is detected.',
      'Review it and the user lists at least quarterly.',
  ], ['Audit log' => '/audit']) ?>
  <p class="small text-muted mt-3 mb-0 d-none" data-guides-empty>No guide matches. Try a shorter word.</p>
</div>

<div class="tab-pane fade" id="tab-menus" role="tabpanel">
  <div class="row">
    <?php foreach ([
        ['fa-gauge-high', 'Top', [['Dashboard', 'Needs attention (one list across every client), portfolio health tiles, the 3-year plan and the rest of your cards. Customize to reorder or hide cards.'], ['Clients', 'Every client. Open one for its own menu: overview, onboarding (while it\'s under way), contacts, devices, licensing, backups, service levels, compliance, documents, roadmap, budget, meetings and client portal. The ⋮ menu has Welcome & onboarding.'], ['Contacts', 'Everyone at every client, searchable.']]],
        ['fa-diagram-project', 'Planning', [['Projects', 'All projects across clients by quarter.'], ['Budgets', 'Every client\'s technology budget side by side.'], ['Licensing', 'Every license, with prices and contract dates.'], ['Renewals', 'Licenses and contracts ending or up for renegotiation.']]],
        ['fa-handshake', 'Meetings & reports', [['Meetings', 'Upcoming and past meetings, with the calendar as a second tab.'], ['Reports', 'Pick a client and open any report: QBR pack, assets, roadmap, budget, backups, service levels, compliance. All-clients reports: portfolio, backups, service levels, renewals.']]],
        ['fa-clipboard-check', 'Compliance', [['Compliance', 'Scores for every client and framework (admins also see the Frameworks tab and crosswalk tags).'], ['Documents', 'Internal and client documents, policies and templates (security policies, WISP, CMMC, HIPAA and privacy).']]],
        ['fa-plug', 'Integrations', [['Integrations', 'Admins: connect and check ' . psa_name() . ', ' . $rmm(', ') . ', ' . $bk(', ') . ', email and warranty lookups.'], ['Client mapping', 'Which ' . \Align\Providers\Providers::rmmNames() . ' organization and ' . $bk() . ' company belongs to each client.'], ['Sync', 'Sync history and Run sync now.'], ['Unassigned hardware', psa_name() . ' assets waiting for a type.']]],
        ['fa-user-shield', 'Admin', [['Settings', 'General, planning & lifecycle, OS support dates, notifications, onboarding, branding, API, updates & backups.'], ['Users', 'Staff accounts and roles.'], ['Client portal users', 'Everyone with a client portal sign-in. In the portal, clients see their roadmap, budget, devices, service levels and documents, and can send requests.'], ['Audit log', 'Who did what, and who viewed which client records.']]],
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
            <tr><th>Clients &amp; address</th><td><?= e(psa_name()) ?> (read-only in Align)</td></tr>
            <tr><th>Contacts</th><td><?= e(psa_name()) ?>, <b>two-way</b>: detail edits (in Align, the client portal or the onboarding page) go back to <?= e(psa_name()) ?>, and new contacts from onboarding are created there</td></tr>
            <tr><th>Computers &amp; servers</th><td><?= e(\Align\Providers\Providers::rmmNames()) ?></td></tr>
            <tr><th>Network gear, printers, UPS…</th><td><?= e(psa_name()) ?> assets, <b>two-way</b>: edits in either place sync</td></tr>
            <tr><th>Licenses</th><td><?= e(psa_name()) ?> (read-only); prices and contracts in Align</td></tr>
            <tr><th>Managed services estimate</th><td><?= e(psa_name()) ?> invoices, last 3 months</td></tr>
            <tr><th>Warranties</th><td>Dell / Lenovo lookups, or the <?= e(psa_name()) ?> warranty date</td></tr>
            <tr><th>Service levels (SLA)</th><td><?= e(psa_name()) ?> tickets and SLA results, hourly; only numbers, subjects, priorities and times are copied</td></tr>
            <tr><th>New user &amp; termination requests</th><td>Sent from onboarding or the portal; become <?= e(psa_name()) ?> tickets (or an email without <?= e(psa_name()) ?>)</td></tr>
            <tr><th>Backups</th><td><?= e($bk(', ')) ?> (read-only), hourly: servers, VMs, agents and Microsoft 365</td></tr>
            <tr><th>Email &amp; invitations</th><td>Microsoft 365 or Google Workspace over OAuth, sent every minute</td></tr>
            <tr><th>Updates &amp; app backups</th><td>GitHub checked every 6 hours; backups downloaded from Settings → Updates &amp; backups</td></tr>
            <tr><th>Projects, budget lines, compliance, documents, meetings, onboarding progress, dashboard layout</th><td>Align</td></tr>
          </table>
          <p class="mt-2 mb-0 text-muted">Fields marked <span class="badge badge-light border"><?= e(psa_name()) ?></span> are managed in <?= e(psa_name()) ?>; change them there and they update here within minutes.</p>
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
            <dt>Unassigned</dt><dd>An asset from <?= e(psa_name()) ?> whose type needs choosing.</dd>
            <dt>Overdue backup</dt><dd>A machine or Microsoft 365 item whose newest restore point is older than the limit set on the backup integration (48 hours by default).</dd>
            <dt>Needs attention</dt><dd>The dashboard's single to-do list across every client, ordered red (act now), amber (soon), then blue (for information).</dd>
            <dt>Service level (SLA)</dt><dd><?= e(psa_name()) ?>'s response and resolution targets per ticket priority. <i>On time</i> means <?= e(psa_name()) ?> recorded the first response or resolution before the target; your goal (90% by default) sets when a client shows amber or red.</dd>
            <dt>Crosswalk</dt><dd>Controls in different frameworks that ask for the same thing (for example MFA in CMMC, CIS and PCI DSS), matched by shared tags so one answer can fill several.</dd>
            <dt>Onboarding link</dt><dd>A private, expiring link in the welcome email. Anyone with it can open that client's onboarding page without signing in, so it's only sent to the client's own people.</dd>
            <dt>Retired / archived</dt><dd>Hidden but kept. Sync never permanently deletes anything.</dd>
            <dt>Roles</dt><dd><b>Viewer</b> reads, <b>Tech</b> edits clients and plans and manages client portal access, <b>Admin</b> also manages settings, integrations and users.</dd>
            <dt>Client portal user</dt><dd>A sign-in for someone at a client. Separate from staff accounts; limited to one client and the sections you tick.</dd>
          </dl>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="tab-pane fade" id="tab-legal" role="tabpanel">
  <div class="row">
    <div class="col-md-4 d-flex">
      <div class="card flex-fill">
        <div class="card-body">
          <h3 class="h6"><i class="fas fa-user-check fa-fw text-secondary mr-1"></i>Terms of use</h3>
          <p class="small text-muted">The rules for staff accounts: keeping sign-ins private, client confidentiality, keeping sensitive personal data out, monitoring, and that planning figures are estimates.</p>
          <a class="btn btn-sm btn-default" href="/terms">Read the terms</a>
        </div>
      </div>
    </div>
    <div class="col-md-4 d-flex">
      <div class="card flex-fill">
        <div class="card-body">
          <h3 class="h6"><i class="fas fa-door-open fa-fw text-secondary mr-1"></i>Client portal terms</h3>
          <p class="small text-muted">What clients agree to when they sign in to the portal: their own account, confidentiality, what an approval means, and that prices are estimates. Linked from the portal sign-in page and footer.</p>
          <a class="btn btn-sm btn-default" href="/portal/terms">Read the portal terms</a>
        </div>
      </div>
    </div>
    <div class="col-md-4 d-flex">
      <div class="card flex-fill">
        <div class="card-body">
          <h3 class="h6"><i class="fas fa-scale-balanced fa-fw text-secondary mr-1"></i>License</h3>
          <p class="small text-muted">MSP-ALIGN is free software under the GNU Affero General Public License v3. Anyone who runs a changed version for others must share its source. Includes the third-party components and their licenses.</p>
          <a class="btn btn-sm btn-default mr-1" href="/license">License &amp; credits</a><a class="btn btn-sm btn-default" href="<?= e(\Align\Controllers\LegalController::sourceUrl()) ?>" target="_blank" rel="noopener">Source code</a>
        </div>
      </div>
    </div>
  </div>
  <p class="small text-muted">Terms last updated <?= e(fmt_date(\Align\Controllers\LegalController::TERMS_UPDATED)) ?>.<?= $isAdmin ? ' The terms use the company name, email and phone from Settings → General.' : '' ?></p>
</div>
</div>

<?php echo str_replace(['Align keeps', 'Align syncs', 'Align doesn\'t', 'here in Align', 'in Align', '>Align<', 'Align emails', 'Update Align', 'restore Align', 'Align makes'], [e(\Align\Branding::name()) . ' keeps', 'it syncs', 'the app doesn\'t', 'here', 'in ' . e(\Align\Branding::name()), '>' . e(\Align\Branding::name()) . '<', e(\Align\Branding::name()) . ' emails', 'Update ' . e(\Align\Branding::name()), 'restore ' . e(\Align\Branding::name()), e(\Align\Branding::name()) . ' makes'], (string) ob_get_clean()); ?>

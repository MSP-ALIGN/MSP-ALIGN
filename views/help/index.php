<?php ob_start(); ?>
<?php
/**
 * Help & how-to (any signed-in staff user). Guides for roles the user doesn't have are left out.
 * Security: the text is written here (HTML on purpose, printed as is); the only values put into it are connector
 * names (psa_name(), rmmNames(), backupNames(): constants in the connector classes) and the app name, which is
 * escaped where the output is rewritten at the end of this file.
 */
use Align\Auth;

$isAdmin = Auth::can('admin');
$isTech = Auth::can('tech');
$rmm = \Align\Providers\Providers::rmmNames(...);
$bk = \Align\Providers\Providers::backupNames(...);
$links = fn(array $l) => implode(' ', array_map(fn($k, $v) => '<a class="btn btn-xs btn-default me-1 mb-1" href="' . e($v) . '">' . e($k) . '</a>', array_keys($l), $l));
$step = function (int $n, string $icon, string $title, string $body, array $l) use ($links) {
    return '<div class="help-step d-flex mb-3"><div class="help-num me-3">' . $n . '</div><div class="flex-grow-1"><h5 class="mb-1"><i class="fas ' . $icon . ' text-secondary me-2"></i>' . e($title) . '</h5>'
        . '<p class="mb-1 text-muted">' . $body . '</p><div>' . $links($l) . '</div></div></div>';
};
/** A how-to guide: collapsible card with numbered steps. $who: viewer|tech|admin */
$guide = function (string $id, string $icon, string $title, string $who, array $steps, array $l = []) use ($links) {
    if (!Auth::can($who)) {
        return '';
    }
    $badge = ['admin' => '<span class="badge text-bg-dark ms-2">Admin</span>', 'tech' => '<span class="badge text-bg-secondary ms-2">Tech</span>'][$who] ?? '';
    $ol = '<ol class="ps-3 mb-2">' . implode('', array_map(fn($s) => '<li class="mb-1">' . $s . '</li>', $steps)) . '</ol>';
    return '<div class="card mb-2 help-guide" id="guide-' . e($id) . '" data-search="' . e(strtolower($title . ' ' . strip_tags(implode(' ', $steps)))) . '">'
        . '<a class="card-header py-2 d-flex align-items-center text-reset text-decoration-none collapsed" data-bs-toggle="collapse" href="#g-' . e($id) . '" role="button" aria-expanded="false">'
        . '<i class="fas ' . $icon . ' fa-fw text-secondary me-2"></i><span class="fw-bold">' . e($title) . '</span>' . $badge . '<i class="fas fa-angle-down fa-sm ms-auto text-muted"></i></a>'
        . '<div class="collapse" id="g-' . e($id) . '"><div class="card-body small">' . $ol . ($l ? '<div>' . $links($l) . '</div>' : '') . '</div></div></div>';
};
?>
<h1 class="h3 mb-1">Help &amp; how-to</h1>
<p class="text-muted">Align keeps each client's technology picture in one place: what they have, what's changing, what it costs and what's coming up. Most of it fills itself in from <?= e(psa_name()) ?> and <?= e(\Align\Providers\Providers::rmmNames()) ?>; you add the planning.</p>

<?php if ($isAdmin): ?><p class="small text-muted"><i class="fas fa-book fa-fw me-1"></i>Installing, updating, test servers and security: see the <a href="https://mspalign.org" target="_blank" rel="noopener">MSP Align documentation</a>.</p><?php endif; ?>
<ul class="nav nav-tabs settings-tabs mb-3" role="tablist">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-workflow" role="tab"><i class="fas fa-route fa-fw me-1"></i>Workflow</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-new" role="tab"><i class="fas fa-star fa-fw me-1"></i>What's new</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-howto" role="tab"><i class="fas fa-list-check fa-fw me-1"></i>How-to guides</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-menus" role="tab"><i class="fas fa-bars fa-fw me-1"></i>Where things are</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-data" role="tab"><i class="fas fa-arrows-rotate fa-fw me-1"></i>Data &amp; terms</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-legal" role="tab"><i class="fas fa-scale-balanced fa-fw me-1"></i>Terms &amp; license</a></li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="tab-workflow" role="tabpanel">
  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-route me-2"></i>The vCIO workflow</h3></div>
    <div class="card-body">
      <h6 class="text-uppercase text-muted small fw-bold">Once, when you set up</h6>
      <?= $step(1, 'fa-plug', 'Connect your tools', 'Open <b>Integrations</b>, set up ' . (psa_on() ? psa_name() . ' and ' : '') . \Align\Providers\Providers::rmmNames() . (psa_on() ? '' : ' (a PSA is optional: see <i>Run MSP Align without a PSA</i>)') . ' (and ' . $bk() . ', email and warranty lookups if you use them), press <b>Test connection</b> on each, then run a sync. Using SLAs in ' . psa_name() . '? Turn on <b>Service levels</b> on the ' . psa_name() . ' card. After that Align syncs hourly and checks ' . psa_name() . ' for asset, contact and license changes every 2 minutes. Then set up your welcome email and onboarding guides under <b>Onboarding → Welcome email &amp; guide</b> (or import a saved set).', $isAdmin ? ['Integrations' => '/integrations', 'Sync' => '/sync', 'Welcome email & guide' => '/settings/onboarding'] : ['Sync' => '/sync']) ?>
      <?= $step(2, 'fa-link', 'Link clients', 'Clients with the same name in each system link automatically. Link the rest (' . \Align\Providers\Providers::rmmNames() . ' organization, ' . $bk() . ' company) on Client mapping. Clients you don\'t plan for (break-fix, vendors) can be removed from planning.', ['Client mapping' => '/mapping', 'Clients' => '/clients']) ?>
      <?= $step(3, 'fa-list-check', 'Clear the To do list', psa_name() . ' assets with a type Align doesn\'t recognize, licenses without a price and clients not linked to a tool all show on <b>To do</b>. Give hardware a type and ' . psa_name() . ' is updated to match.', ['To do' => '/todo', 'Unassigned hardware' => '/devices/unassigned']) ?>
      <h6 class="text-uppercase text-muted small fw-bold mt-4">For each new client</h6>
      <?= $step(4, 'fa-mountain-sun', 'Contract, then welcome and onboard', 'Send the contract from <b>Onboarding → Contracts</b> (they sign online; for a new client, press <b>Add as a client</b> on the signed contract). Then choose <b>⋮ → Welcome &amp; onboarding</b> on the client. The welcome email links to a private page (no sign-in) where they enter their team\'s contacts, read how to reach you and how billing works, answer a few getting-started questions and send new user or termination requests. Track their progress on the client\'s <b>Onboarding</b> page, or everyone\'s under <b>Onboarding → New clients</b>.', ['Contracts' => '#guide-contracts', 'How onboarding works' => '#guide-onboarding']) ?>
      <h6 class="text-uppercase text-muted small fw-bold mt-4">For each client (the overview's Planning checklist tracks this)</h6>
      <?= $step(5, 'fa-address-book', 'Contacts', 'Contacts come from ' . psa_name() . ' (and from onboarding). Mark the <b>decision maker</b> and the people you <b>invite to reviews</b>. They can then be added to a meeting in one click.', ['Contacts' => '/contacts']) ?>
      <?= $step(6, 'fa-desktop', 'Devices & lifecycle', 'Check devices without an in-service date (they can\'t be planned) and set replacement costs where the policy default is wrong. Edits go to ' . psa_name() . ' automatically.', ['How to plan replacements' => '#guide-lifecycle']) ?>
      <?= $step(7, 'fa-key', 'Licensing & contracts', 'Licenses sync from ' . psa_name() . ' without prices. Add the price and billing cycle, plus contract term, end and renegotiate-by dates.', ['Licenses needing a price' => '/licenses?filter=unpriced', 'Renewals' => '/renewals']) ?>
      <?= $step(8, 'fa-database', 'Backups & service levels', 'With ' . $bk() . ' connected, each client\'s <b>Backups</b> page shows job results, protected machines, Microsoft 365 and servers with no backup. With ' . psa_name() . ' SLAs turned on, <b>Service levels</b> shows how many tickets were answered and resolved on time and which missed.', ['Backups (all clients)' => '/reports/backups', 'Service levels (all clients)' => '/reports/sla']) ?>
      <?= $step(9, 'fa-clipboard-check', 'Compliance & documents', 'Assign the frameworks the client must meet (CMMC, NIST CSF, CIS, HIPAA, PCI, SOC 2, ISO 27001, WISP and more), work the checklist and let the <b>crosswalk</b> reuse answers across frameworks. Write policies from the templates in Documents and link them as evidence.', ['Compliance' => '/compliance', 'Documents' => '/documents']) ?>
      <?= $step(10, 'fa-road', 'Roadmap & projects', 'Add projects with a target quarter, budget and description. Hardware reaching end of life, OS end of support and warranty dates appear on the roadmap automatically.', ['Projects' => '/projects']) ?>
      <?= $step(11, 'fa-coins', 'Budget', 'The budget builds itself from licensing, hardware, projects and managed services. Add a <b>Managed services</b> line with your agreement amount, plus internet, phones, cloud and other costs.', ['Budgets' => '/budget']) ?>
      <?= $step(12, 'fa-door-open', 'Client portal (optional)', 'Invite the owner or office manager from the client\'s <b>Client portal</b> page. Choose what they can see and do. They sign in at <b>/portal</b> and only ever see their own company; their approvals and requests show up for you, and their approvals appear on the roadmap and the dashboard.', ['Client portal users' => '/portal-users']) ?>
      <?= $step(13, 'fa-handshake', 'Meet and report', 'Schedule the review (invitations go out as calendar invites if email is connected). The meeting page lists talking points; <b>Reports</b> builds the QBR pack (now with a Service levels section) and every other report for printing or PDF.', ['Meetings' => '/meetings', 'Reports' => '/reports']) ?>
      <h6 class="text-uppercase text-muted small fw-bold mt-4">Every day</h6>
      <?= $step(14, 'fa-gauge-high', 'Work from the dashboard', 'Start with <b>Needs attention</b> on the dashboard: failed backups, missed service levels, stalled onboardings, decisions waiting on a client, renewals, compliance gaps and hardware due for replacement, across every client and most urgent first. Press <b>Customize</b> to arrange the rest of the dashboard your way.', ['Dashboard' => '/', 'How to customize it' => '#guide-dashboard']) ?>
    </div>
  </div>
</div>

<div class="tab-pane fade" id="tab-new" role="tabpanel">
  <p class="text-muted small">The biggest recent additions. Full release notes for each version are under <?= $isAdmin ? '<a href="/settings/system">Settings → Updates &amp; backups</a>' : 'Settings → Updates &amp; backups (admins)' ?>.</p>
  <div class="list-group mb-3">
    <?php foreach ([
        ['2.2.3', 'fa-stethoscope', 'Diagnostics', 'Settings → <b>Diagnostics</b> shows the server, the database, storage and the background jobs at a glance, marks anything that needs a look, lists the last week\'s problems, and gives you a report to paste into a support request.', 'diagnostics', 'admin'],
        ['2.2.2', 'fa-palette', 'A new logo and name', 'The app is called MSP Align now (it was MSP-ALIGN), with a new logo in the menu, the browser tab and on the sign-in pages, and the default brand color is now its blue. Your own logo, name and color from Settings → Branding still take its place.', 'branding', 'admin'],
        ['2.2.2', 'fa-filter', 'More device filters', 'The <b>Filters</b> button on Devices &amp; assets narrows the list by make and model, operating system, age, replacement year, warranty, status, backup, location, project and last user, with a count beside each choice. Active filters show as chips (press one to remove it), stay in the page address so you can bookmark or share the list, and the CSV follows them.', 'search', 'viewer'],
        ['2.2.2', 'fa-play', 'Ready to start', 'Approve a whole year of projects without filling the ticket board: a project\'s <b>QUOTE-</b> ticket in ' . psa_name() . ' is made only when you press <b>Ready to start</b> (or tick <b>Make the QUOTE- ticket now</b> when you add it). Projects go on <b>To do</b> on the first day of their quarter, and <b>Not yet</b> hides one for 1, 2, 3 or 6 months. Projects added by hand work the same way. Without a PSA (or for a client not linked to one), Ready to start marks the project started instead.', 'project', 'tech'],
        ['2.2.1', 'fa-shield-halved', 'Security and quality review', 'Every file was read line by line and the whole program audited again. Wrong two-factor codes after a correct password now alert admins, and after 50 the password is replaced. A password or 2FA change, or Sign out everywhere, turns off your calendar feed link (make a new one on the Meetings page). Integrations never connect to the server itself or cloud metadata addresses and don\'t follow redirects. A contract the client signed stays signed after it\'s cancelled. More views and syncs are in the audit log.', 'users', 'viewer'],
        ['2.2', 'fa-file-signature', 'Contracts, signed online', 'A new <b>Onboarding</b> section in the menu: upload your own contract PDF and place boxes on it for the fields, prices and signatures (Align can find the blanks for you), send them to sign online (with an emailed code), countersign, and keep the signed PDF with a signature certificate. Upload contracts signed elsewhere too. The welcome email and guide moved here from Settings, and <b>New clients</b> shows every onboarding at a glance.', 'contracts', 'tech'],
        ['2.1.1', 'fa-image', 'Sign-in backgrounds', 'The sign-in pages have a background now: a built-in one for your team and a lighter one for the client portal. Under Settings → Branding, upload your own for either, choose how much to darken it, or pick No image for the plain page.', 'branding', 'admin'],
        ['2.1', 'fa-diagram-project', 'Replacements become projects', 'Client approved a replacement? Tick the devices on Devices &amp; assets (or open one) and choose <b>Make projects</b>: one per device or one for several, in their replacement quarter or one you pick, at the budgeted cost or the quote. Align can create a <b>QUOTE-</b> ticket in ' . psa_name() . ' for each. The devices leave the automatic plan, so nothing counts twice in the budget.', 'lifecycle', 'tech'],
        ['2.0.2', 'fa-box-archive', 'Microsoft 365: old backups kept', 'Users, groups, teams and sites with no new backup for 30 days (people who left, a tenant no longer used) move to a <b>No longer backed up</b> list on the client\'s Backups page. Their old backups are still kept and restorable, but they no longer count as protected or overdue. Change the days under Integrations → Veeam (0 = never).', 'backups', 'tech'],
        ['2.0.1', 'fa-cloud', 'Microsoft 365 backups in two repositories', 'When a tenant moved from a legacy Veeam repository to a new one, each mailbox, group, team and site now counts once, with its newest restore point, so it no longer shows as overdue. Each client\'s Backups page shows the days with a backup for every protected user, group, team and site (a day counts once, however many restore points it has), counted from the first sync after this update.', 'backups', 'tech'],
        ['2.0.1', 'fa-circle-question', '"Are you sure?" before big changes', 'Changes that are wide, hard to undo, email clients or write to ' . psa_name() . ' now ask first and say what will happen, often with a count (14 devices, 3 controls for 7 clients). Closing an edit window or leaving a long form with unsaved changes asks too, and Enter in a field always saves rather than deleting.', 'settings', 'admin'],
        ['2.0', 'fa-signature', 'MSP Align 2.0: signed releases', 'The first public release. Updates now install only releases signed with the MSP Align release key (never stored on GitHub), checked by your own server; anything else is refused with a security alert. Settings → Updates &amp; backups shows the key\'s fingerprint. Documentation, troubleshooting and an FAQ are at mspalign.org.', 'update', 'admin'],
        ['2.0', 'fa-code', 'API: PSA ids are text', 'In the REST API, <code>psa_id</code> and <code>psa_asset_id</code> are now always text (<code>"57"</code>), whatever the PSA. If an n8n flow or script compares them with a number, update it, or use the <code>itflow_*</code> fields, which stay numbers. From 2.0, v1 only adds things.', 'api', 'admin'],
        ['1.45.2', 'fa-palette', 'Branding page in the new look', 'Settings → Branding matches the rest of the app now, and its live preview shows the app, the sign-in page and the client portal as they look today, in light or dark mode.', 'settings', 'admin'],
        ['1.45.1', 'fa-laptop', 'Remember this browser, and yearly invoices', 'After the two-factor code, tick Remember this browser to skip the code on that browser for 14 days (the password is still asked for). See or forget remembered browsers on your Account page; admins set the days under Settings → General. The managed-services estimate from ' . psa_name() . ' now counts a yearly recurring invoice as a twelfth a month instead of the whole amount every month.', 'users', 'viewer'],
        ['1.45', 'fa-shield-halved', 'Security audit', 'Every part of MSP Align was reviewed and tested before 2.0. The biggest fix is in the backup service on the server. The audit log now also catches entries cut off either end. Removing someone\'s 2FA gives them a one-time password, and moving 2FA to a new phone needs a code from the old one. Changing an integration\'s address or the SMTP server asks for its key or password again. Only admins can delete a document. Portal reports show key contacts only to users who may see contacts. More actions and page views are in the audit log.', 'users', 'admin'],
        ['1.44.1', 'fa-address-book', 'Contacts sync both ways, archiving too', 'Archiving or restoring a contact in Align now does the same in ' . psa_name() . ', on top of new contacts and edits. Each client\'s Contacts page says whether its contacts sync both ways, come in only, or stay in Align, and the Integrations switch is now called Two-way sync (devices and contacts).', 'contacts', 'viewer'],
        ['1.44', 'fa-box', 'Install with Docker', 'MSP Align now also comes as a container image with a ready-made Docker Compose file (the app and MariaDB, plus an optional add-on for automatic HTTPS). It\'s the same app with the same backups, so a backup moves between a Docker install and a dedicated server. Dedicated installs are unchanged. See Install with Docker on mspalign.org.', 'update', 'admin'],
        ['1.43', 'fa-circle-half-stroke', 'A cleaner look, and dark mode', 'A fresh, cleaner design: white cards on a light gray page, softer colors and a compact dark menu. Each person can pick <b>Light</b>, <b>Dark</b> or <b>Match my computer</b> under <b>Account → Light or dark</b>; printed reports always stay light. The client portal is friendlier too: the client\'s logo at the top, larger text and more room.', 'appearance', 'viewer'],
        ['1.42', 'fa-gauge-high', 'Faster, and easier to find your way', 'Pages stay under a second at 150 clients and 10,000 devices, and long lists load a fraction of what they did. The menu is shorter, with a new <b>To do</b> list and a <b>Devices &amp; assets</b> list across every client; the top search also finds devices by serial, contacts and licenses. Client menus are grouped, every page has the same header and filter row, device pages have tabs, and the client portal has six tabs instead of ten.', 'todo', 'viewer'],
        ['1.41', 'fa-flask', 'Demo data', 'Try MSP Align with four made-up clients before adding your own: load them from the setup wizard or Settings → General, and remove them with one click.', 'demo', 'admin'],
        ['1.40', 'fa-wand-magic-sparkles', 'Setup wizard', 'New installs start with a step-by-step setup: company, currency & dates, PSA, RMM, backups & warranty, email, clients and team. Skip any step; open it again any time from Settings → General.', 'setup', 'admin'],
        ['1.39', 'fa-door-open', 'Client portal update', 'Clients can suggest licenses and budget items from the portal; you add them (edited if needed) or decline with a note, and they see each one as waiting until then. Portal contacts are view-only, and the portal has a clearer layout with every section on one bar.', 'suggestions', 'tech'],
        ['1.38', 'fa-globe', 'Your currency and date style', 'Choose the currency, how numbers and dates are written, a 12- or 24-hour clock, the first day of the week and the timezone under Settings → General. Pages, reports, emails and the client portal all follow it.', 'currency', 'admin'],
        ['1.37', 'fa-server', 'Email through any SMTP server', 'Send notifications, portal invitations and meeting invitations through your own mail server or a relay (SMTP2GO, Mailgun, SendGrid, Amazon SES, or the Microsoft 365 / Google relay), with STARTTLS or TLS and an optional user name and password. Meeting invitations go out as .ics emails with Accept / Decline.', 'email', 'admin'],
        ['1.36', 'fa-circle-nodes', 'No PSA needed', 'Run MSP Align with just your RMM: add clients from its organizations on Client mapping, or import clients and contacts from a CSV file (Clients → Import). Screens leave out what only a PSA provides.', 'no-psa', 'tech'],
        ['1.35', 'fa-folder-tree', 'Server folders renamed', 'On the server, MSP Align now lives in /opt/msp-align, /etc/msp-align and /var/lib/msp-align, and its services and logs are named msp-align. The update moved everything in place; the old mountaineer-align folder names and commands still work.', 'update', 'admin'],
        ['1.32.1', 'fa-flask', 'Test servers', 'A test server can run the next version on a copy of your data in staging mode: it reads from your tools as usual, but writes nothing back, sends email only to one test mailbox and keeps the client portal off. See docs/TEST-SERVER.md.', 'update', 'admin'],
        ['1.31', 'fa-link', 'One mapping screen for every tool', 'Client mapping now works the same for every connected tool: a card per tool with how many clients are linked and which records aren\'t, a <b>Missing a link</b> view, and plain labels for how each link was made. Ready for more tools later.', 'mapping', 'tech'],
        ['1.30.1', 'fa-code-branch', 'A new home on GitHub', 'MSP Align now lives at github.com/MSP-ALIGN/MSP-ALIGN. This update switches your server to the new address automatically, so future updates come from there.', 'integration', 'admin'],
        ['1.30', 'fa-database', 'Ready for other backup products', 'Veeam now plugs in as one backup provider, and Align can read more than one backup product at a time. Each client links to a company in each product on Client mapping, and hosted backups work across all of them. Nothing changes in how Align works for you today.', 'integration', 'admin'],
        ['1.29', 'fa-satellite-dish', 'Ready for other RMMs', 'NinjaOne now plugs in as one RMM provider, and Align can run more than one RMM at a time (handy while moving clients from one RMM to another). Each client links to an organization in each RMM on Client mapping. Nothing changes in how Align works for you today.', 'integration', 'admin'],
        ['1.28', 'fa-plug-circle-check', 'Ready for other PSAs', 'ITFlow now plugs in as one PSA provider behind a common interface, so other PSAs (ConnectWise, HaloPSA, Autotask…) can feed the same screens later. Nothing changes in how Align works for you today.', 'integration', 'admin'],
        ['1.27', 'fa-code', 'REST API', 'Read and write planning data from n8n, Zapier, Power Automate, AI agents and your own scripts. Keys with per-area permissions, client limits, expiry and rate limits, under Settings → API.', 'api', 'admin'],
        ['1.26', 'fa-building', 'Hosted clients\' backups', 'Servers you host and back up on your own Veeam server now show for the right client: matched by device name automatically, with Client mapping → Hosted backups to assign jobs or machines by hand.', 'hosted-backups', 'tech'],
        ['1.25', 'fa-list-ol', 'Reports in meeting order', 'The QBR pack now runs the way the meeting does: look back, what they have, is it protected, where it\'s going, what it costs, then decisions. Servers, hypervisor hosts and virtual servers sit together, and the budget, roadmap and backup reports read top to bottom.', 'qbr', 'viewer'],
        ['1.24', 'fa-signature', 'Renamed to MSP Align', 'Mountaineer Align is now MSP Align. Nothing to do: your data, settings and branding are unchanged. On the server the commands are now sudo msp-align-update and msp-align-restore (the old names still work).', 'update', 'admin'],
        ['1.23', 'fa-bars-progress', 'Progress window for updates and restores', 'Updating or restoring now shows each step, a progress bar and the time so far, and reloads on its own when it\'s done. Everyone else sees a Please wait page that refreshes itself.', 'update', 'admin'],
        ['1.22', 'fa-mountain-sun', 'Client onboarding', 'Send a welcome email with a private onboarding page: the client enters their contacts, reads how to reach you and how billing works, answers getting-started questions and sends new user or termination requests. Track progress on the client\'s Onboarding page; edit everything under Onboarding → Welcome email & guide.', 'onboarding', 'tech'],
        ['1.22', 'fa-user-plus', 'New user and termination requests', 'Online request forms on the onboarding page and in the client portal. Each request becomes a ticket in ' . psa_name() . '.', 'requests', 'tech'],
        ['1.21', 'fa-gauge-high', 'A new dashboard', 'Needs attention lists what to act on across every client, portfolio health tiles give the big picture, and Customize lets everyone reorder or hide cards.', 'dashboard', 'viewer'],
        ['1.20', 'fa-stopwatch', 'Service levels from ' . psa_name(), 'Response and resolution on time, missed tickets and trends per client, on the dashboard and meeting prep, in a printable report, in the QBR pack and in the client portal.', 'service-levels', 'viewer'],
        ['1.19', 'fa-clipboard-check', 'More frameworks and the crosswalk', 'CMMC Level 1 and 2, NIST CSF 2.0, CIS v8.1, PCI DSS, SOC 2, ISO 27001, HIPAA, CCPA/CPRA and more. The crosswalk reuses answers across a client\'s frameworks.', 'compliance', 'tech'],
        ['1.19', 'fa-file-lines', 'More document templates', 'Security policies, incident response, BCDR, the CMMC package (SSP, POA&M, CUI handling) and HIPAA and privacy templates.', 'document', 'tech'],
    ] as [$ver, $icon, $title, $text, $g, $who]): $canOpen = Auth::can($who); ?>
      <<?= $canOpen ? 'a' : 'div' ?> class="list-group-item<?= $canOpen ? ' list-group-item-action' : '' ?> d-flex"<?= $canOpen ? ' href="#guide-' . e($g) . '"' : '' ?>>
        <i class="fas <?= $icon ?> fa-fw text-secondary me-3 mt-1"></i>
        <div class="flex-grow-1"><div class="d-flex flex-wrap align-items-center"><b class="me-2"><?= e($title) ?></b><span class="badge text-bg-light border">v<?= e($ver) ?></span></div><div class="small text-muted"><?= $text /* HTML written above, like the guides (2.2.1: it was escaped, so "<b>" showed as text) */ ?></div></div>
        <?php if ($canOpen): ?><i class="fas fa-angle-right text-muted ms-2 mt-1"></i><?php endif; ?>
      </<?= $canOpen ? 'a' : 'div' ?>>
    <?php endforeach; ?>
  </div>
</div>

<div class="tab-pane fade" id="tab-howto" role="tabpanel">
  <div class="d-flex align-items-center mb-2">
    <input type="search" class="form-control form-control-sm me-2" style="max-width:320px" placeholder="Find a guide…" data-filter-guides aria-label="Find a guide">
    <span class="small text-muted">Click a guide to open it.<?= $isAdmin ? '' : ' Some tasks need a tech or admin account.' ?></span>
  </div>
  <h6 class="text-uppercase text-muted small fw-bold mt-3">Clients &amp; planning</h6>
  <?= $guide('dashboard', 'fa-gauge-high', 'Use and customize the dashboard', 'viewer', [
      '<b>Needs attention</b> at the top lists what to act on across every client, most urgent first (red, then amber, then blue). Use the buttons to show one area, such as Backups or Renewals. Each line opens the page that fixes it.',
      '<b>Portfolio health</b> has four groups of tiles: service &amp; backups, lifecycle &amp; security, client engagement and money. Each tile opens the matching list or report.',
      'Press <b>Customize</b> to drag cards by the handle (or use the arrows) into the order you want, across the full-width row and the two columns, and the eye button to hide a card. Hidden cards are listed in the Customize bar to add back. Press <b>Done</b> to save; <b>Reset to default</b> restores the standard layout. Everyone has their own layout.',
  ], ['Dashboard' => '/']) ?>
  <?= $guide('contracts', 'fa-file-signature', 'Send a contract to sign, and keep it', 'tech', [
      '<b>Once (admins):</b> under <b>Onboarding → Contract templates</b>, press <b>New template</b> and upload your own agreement as a PDF (the blank one, not a signed copy). Align keeps your PDF exactly as it is and puts boxes on top of it: press <b>Find the blanks</b> to have Align suggest boxes for the underscores, brackets and $ spots it finds (client name, contract start date, both signatures, names, titles and dates, the prices), then <b>Add them</b> and drag or resize any that need it. Add more with <b>Add a box</b> → <b>Place it</b>. A box shows something Align knows (the client\'s name and address, your details, the contract start date, a price, a signature) or, with <b>New blank…</b>, something it doesn\'t, like an onsite rate: click the box to name it, choose its kind (text, date, amount, choice…) and who fills it in (you before sending, or the client when signing). To show the same blank twice, place another box and pick it from the list. The other tabs set up the <b>services</b> with your prices (Align can count workstations, servers, firewalls, users and licenses for existing clients; place their quantity, price and total boxes on your pricing table), and <b>signing</b>: who signs (the client then you, you first, or only the client), initials, and your profile picture next to your signature. Changed your agreement? <b>Upload a new version of the PDF</b> (in the ⋯ menu) keeps the boxes where they are. Make one template per agreement, for example MSP and MSSP. Prefer to write it here? <b>Write it in Align</b> starts a blank page with your wording, logo, header and footer instead. Export a template to move it to another server.',
      '<b>Each contract:</b> on <b>Onboarding → Contracts</b> (or a client\'s Documents page) press <b>New contract</b>, pick the template and an existing client or a new one. Fill in the <b>contract start date</b> (it prints on the contract, and its renewal dates count from it) and your other fields, check the quantities and prices (untick a service to leave it out, or add a line), and watch the preview on the right. A contract keeps its own copy of the template, so later template changes don\'t touch it.',
      '<b>Send for signature</b> emails the person who signs a private link (or press <b>Create link only</b> to send it yourself). With <b>Ask for a code</b> on, they also enter a one-time code emailed to them, so a forwarded link isn\'t enough. On your PDF they press <b>Start</b>, and <b>Next</b> takes them to each yellow tag in turn: their own blanks, their name and title (nothing is filled in for them), each <b>Initial</b> box (they adopt their initials once, then click each box) and <b>Sign</b> (typed or drawn); <b>Finish</b> asks them to agree to sign electronically and signs. A contract written in Align is read, filled in and signed at the bottom. They can decline with a reason instead. Unsigned contracts get a reminder every 3 days (twice, by default; change it under Contract templates) with the same link; links expire after 30 days by default (set per template).',
      'When the client has signed, countersign it from the contract page (the menu shows how many are waiting). Then Align makes the signed PDF with a <b>signature certificate</b> page (who signed, when, from which IP address and browser, how their email was checked, and a fingerprint of the content), saves it with the contract and the client, and emails a copy to the signer. For a new client, press <b>Add as a client</b>, then send the welcome email.',
      '<b>Signed elsewhere?</b> Press <b>Upload signed contract</b> to keep a PDF signed in DocuSeal or on paper with its dates. <b>Check a signed PDF</b> (in the ⋯ menu) tells you whether a copy someone sends you is exactly the signed one.',
      'Electronic signatures are valid for most business contracts in the US under the ESIGN Act and state UETA laws, and Align records the consent and signing trail they rely on. It\'s not legal advice: have your attorney review your contract wording and the signing process before you use them.',
  ], ['Contracts' => '/contracts'] + ($isAdmin ? ['Contract templates' => '/contracts/templates'] : [])) ?>
  <?= $guide('onboarding', 'fa-mountain-sun', 'Welcome and onboard a new client', 'tech', [
      'Once they\'re officially a client, open the client and choose <b>⋮ → Welcome &amp; onboarding</b>. Pick who gets the welcome email, set the onsite week, adjust the message for this client if you like, and press <b>Send welcome email</b>. No email connected? Press <b>Create link only</b> and paste the link into your own email.',
      'The email has a <b>Start onboarding</b> button that opens a private page (no sign-in; the link works for 30 days by default and sending again replaces it). There the client fills in <b>their team\'s contacts</b> (or pastes them from a spreadsheet), marks who approves changes, gets invoices and is the main IT contact, reads <b>how to reach you, billing and email security</b> and confirms, gives <b>getting-started details</b> (current IT provider, preferred onsite week, pain points), and can send <b>new user</b> or <b>user termination</b> requests.',
      'Contacts go straight into the client\'s contacts and ' . psa_name() . ' (with two-way sync on). Requests become ' . psa_name() . ' tickets, or an email to your company address without ' . psa_name() . '. You get a notification as they go.',
      'Follow along on the client\'s <b>Onboarding</b> page: what\'s done, their answers, and their requests. The dashboard flags onboardings that stall or whose link expired. You can turn the link off, send it again or mark onboarding complete.',
      'Admins edit the welcome email and the page\'s guides in <b>Onboarding → Welcome email &amp; guide</b> (with placeholders like the client\'s name and your phone), attach a PDF to a guide, and import or export the whole set. Request forms can be turned off there; they\'re also in the client portal for users who can send requests.',
  ], ['Welcome email & guide' => '/settings/onboarding']) ?>
  <?= $guide('add-client', 'fa-user-plus', 'Add a client or take one out of planning', 'tech', [
      'Clients from ' . psa_name() . ' appear on their own after a sync. To add one by hand: <b>Clients → New client</b> (or the <b>+</b> menu at the top).',
      'A hand-added client links to ' . psa_name() . ' automatically when a client with the same name shows up there.',
      'For break-fix clients or vendors: tick them on <b>Clients</b> and choose <b>Remove from planning</b>. They drop out of budgets, reports and reminders but nothing is deleted.',
  ], ['Clients' => '/clients']) ?>
  <?= $guide('appearance', 'fa-circle-half-stroke', 'Switch between light and dark', 'viewer', [
      'Open your name at the top right and choose <b>Light or dark</b> (or go to <b>Account</b>). Pick <b>Light</b>, <b>Dark</b> or <b>Match my computer</b>, which follows your computer\'s own setting and changes when it does.',
      'The choice is yours alone and follows you to every computer you sign in from. Everyone else keeps their own.',
      'Printed reports, PDFs and the client portal always use the light colors, so what clients see doesn\'t change. Your brand color (Settings → Branding) is used in both modes.',
  ], ['Account' => '/account#appearance']) ?>
  <?= $guide('branding', 'fa-image', 'Brand the app and the sign-in pages', 'admin', [
      'Open <b>Settings → Branding</b>: the app\'s name, your logo, the brand color and the menu color. The preview on the right shows the app, the sign-in page and the client portal as you change them.',
      'Under <b>Sign-in page</b>, set the message above the form, and upload a background for your team\'s sign-in and a separate one for the client portal\'s (JPG, PNG or WebP, 1920 × 1080 or larger). Use <b>Darken it</b> so the logo and form stay easy to read on a busy photo; something calm and neutral suits the client side.',
      'Press <b>Save branding</b>. Until you upload your own, each page uses a built-in image. <b>Remove my image</b> brings the built-in one back; <b>No image</b> gives the plain page.',
  ], ['Branding' => '/settings/branding']) ?>
  <?= $guide('contacts', 'fa-address-book', 'Keep contacts in sync with ' . psa_name(), 'viewer', [
      'Each client\'s <b>Contacts</b> page says how its contacts sync: <b>both ways</b>, <b>in from ' . psa_name() . ' only</b> (two-way sync is off) or <b>in Align only</b> (the client isn\'t linked; link it on Client mapping).',
      'Both ways: a contact you add in Align is created in ' . psa_name() . ', and edits to name, title, department, email and phones go there as you save. Archiving or restoring a contact in Align does the same in ' . psa_name() . ' (1.44.1). ' . psa_name() . ' changes come in every few minutes.',
      psa_name() . '\'s own archive also clears the contact\'s Important, Billing and Technical flags and archives their client portal login there. The Primary flag and location are always managed in ' . psa_name() . '.',
      'Restoring does the same in ' . psa_name() . ' and re-enables the contact\'s client portal login there. Contacts a client removes on their onboarding page are archived in Align only.',
      'If ' . psa_name() . ' refuses a change, Align says why and keeps it in Align only. Two-way sync is the <b>Two-way sync (devices and contacts)</b> switch under Integrations → ' . psa_name() . '.',
  ], ['Contacts' => '/contacts']) ?>
  <?= $guide('todo', 'fa-list-check', 'Work the To do list', 'viewer', [
      '<b>To do</b> (top of the menu) lists everything waiting on your team from every client: projects ready to start (their quarter is here and they haven\'t been started), hardware without a type, licenses without a price, clients not linked to a connected tool, hosted backups to match and suggestions sent from the client portal.',
      'Each line has a button that goes straight to the fix. A line leaves the list by itself once the work is done; the badge in the menu shows how many are left.',
      'The dashboard\'s <b>Needs attention</b> is the other list: problems at clients (failed backups, missed service levels, renewals, hardware due). To do is setup and upkeep for you.',
  ], ['To do' => '/todo']) ?>
  <?= $guide('search', 'fa-magnifying-glass', 'Find a device, contact or license', 'viewer', [
      'Type in the search box at the top of any page: a client name, a device name, a <b>serial number</b>, a last-logged-in user, a contact\'s name, email or phone, or a license or vendor.',
      'Results are grouped by clients, devices, contacts and licenses. On long lists (Devices &amp; assets, Licensing, Contacts, Projects) the search box searches every row, not just the ones on screen; lists show 100 rows and a <b>Show 100 more</b> button.',
      'On Devices &amp; assets, <b>Filters</b> narrows the list further: make and model, operating system, age, replacement year, warranty, status, backup, location, project and last user. Pick any mix and press <b>Apply filters</b>; chips above the list remove one each, and the page address keeps them for a bookmark or a link to send.',
  ], ['Devices & assets' => '/devices']) ?>
  <?= $guide('unassigned', 'fa-circle-question', 'Categorize unassigned hardware', 'viewer', [
      'Open <b>To do</b> and choose <b>Categorize</b>, or <b>Devices &amp; assets → Unassigned hardware</b> (the badge shows how many are waiting).',
      'Tick the devices of one kind, choose a type and press <b>Apply</b>. ' . psa_name() . ' is updated to match.',
      'Things you don\'t plan for (monitors, cables) can be excluded from planning instead.',
  ], ['Unassigned hardware' => '/devices/unassigned']) ?>
  <?= $guide('lifecycle', 'fa-recycle', 'Plan device replacements', 'tech', [
      'Open the client\'s <b>Devices &amp; assets</b> (or <b>Devices &amp; assets</b> in the main menu for every client). Choose <b>More → No in-service date</b> to find devices that can\'t be planned yet. <b>Columns</b> adds Type, Serial, Warranty, Backup and cost to the table.',
      'Open a device to set the purchase or in-service date, warranty, lifespan or replacement cost. These override the defaults.',
      'Client wants to keep it longer, or replace it sooner? On the client\'s <b>Roadmap &amp; projects</b>, open a quarter\'s <b>Replace … devices</b> list and drag a device (or the whole group by its heading) to another quarter. You can also use <b>Replace in</b> on the device, or tick several on the devices list and choose <b>Set replacement</b> to pick a quarter and note why. The roadmap, 3-year plan and budget move it there; a device past end of life that was put off shows as <b>Replacement deferred</b>. Choose <b>Automatic</b> to go back to the end-of-life date.',
      'Client approved a replacement? Tick the devices and choose <b>Make projects…</b> (or <b>Make a project</b> on a device): one project per device, or one for several replaced together, with the budgeted cost or the quote amount. No ticket is made unless you tick <b>Make the QUOTE- ticket now</b>; otherwise <b>Ready to start</b> makes it when the time comes (see the project guide). Those devices then count through the project, not their own replacement date, until the project is declined or deleted.',
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
      'Each project gets one <b>QUOTE-</b> ticket in ' . psa_name() . ', made only when you say: tick <b>Make the QUOTE- ticket now</b> when you add it, or press <b>Ready to start</b> later. An approved or scheduled project without a ticket goes on <b>To do</b> on the first day of its quarter; <b>Not yet</b> hides it for a month (or 2, 3 or 6 from its menu). Ready to start shows what goes into the ticket before it\'s made, and also works from the project window and the <b>Ticket</b> column on Projects, any time. So you can approve a whole year of projects without filling the ticket board.',
      'On a test server, Ready to start saves a pretend ticket number (TEST-…) and sends nothing to ' . psa_name() . '. No PSA, or a client that isn\'t linked to one? Projects still go on To do when their quarter starts, and <b>Ready to start</b> marks the project started (the date and your name are saved) so it leaves the list. Once a ticket can be made, the project window offers <b>Make the ticket</b>; <b>Not started</b> there undoes the mark.',
  ], ['Projects' => '/projects']) ?>
  <?= $guide('budget', 'fa-coins', 'Build a client\'s budget', 'tech', [
      'Open the client\'s <b>Budget</b>. Hardware, licensing and projects fill in automatically.',
      'Add a <b>Managed services</b> line with your agreement amount (or let the ' . psa_name() . ' invoice estimate stand), plus internet, phones, cloud and other running costs.',
      'The budget year (calendar or fiscal) and whether the plan starts this year or next are under <b>Settings → Planning &amp; lifecycle</b>.',
  ], ['Budgets' => '/budget']) ?>
  <?= $guide('backups', 'fa-database', 'Check a client\'s backups', 'viewer', [
      'Open the client and choose <b>Backups</b>: health, failed jobs with the backup product\'s messages, overdue machines, Microsoft 365 coverage and servers with no backup.',
      'Under Microsoft 365, open <b>All protected users, groups, teams and sites</b> to see each one\'s newest restore point and its <b>days with a backup</b> (a day counts once, however many restore points it has, and across repositories). A mailbox kept in a legacy and a current repository counts once, with its newest restore point. Those with no new backup for 30 days are listed under <b>No longer backed up</b> and not counted.',
      'Something that genuinely doesn\'t need a backup (a test VM, a kiosk)? A tech can mark it <b>Backup not required</b> with a reason; it stops counting as missing everywhere.',
      '<b>Reports → Backup &amp; recovery</b> prints it for the client, and <b>Reports → Backups (all clients)</b> shows the whole portfolio.',
  ], ['Backups report (all clients)' => '/reports/backups']) ?>
  <?= $guide('mapping', 'fa-link', 'Link clients to your RMM and backup records', 'tech', [
      'Every connected tool that keeps its own list of customers gets a column on <b>Client mapping</b> (a tab of Integrations): an organization in each RMM, a company in each backup product. The cards at the top show how many clients each tool has linked and which of its records aren\'t linked to anyone.',
      'On every sync, a client with no link yet is linked to the record with the <b>same name</b> (ignoring punctuation and words like Inc or LLC). Backup companies also match the client\'s RMM organization name. <b>How</b> shows <i>by name</i> for these.',
      'Pick a record to link a client by hand (<i>by hand</i>), or <b>— Not linked —</b> to keep it unlinked (<i>kept unlinked</i>): sync then leaves it alone. Each record can belong to one client only; one already used shows <i>(linked elsewhere)</i>. To move it, set the other client to <b>— Not linked —</b> in the same save.',
      'Open <b>Missing a link</b> to see only clients that still need a link in some tool, then press <b>Save mapping</b>. A client\'s devices come from its RMM organization, and its backups from its backup company, so fixing a link updates both straight away.',
  ], ['Client mapping' => '/mapping']) ?>
  <?= $guide('no-psa', 'fa-circle-nodes', 'Run MSP Align without a PSA', 'tech', [
      'A PSA is optional. Without one, connect your RMM under <b>Integrations</b> and run a sync: its organizations and devices come in, and screens leave out what only a PSA provides (tickets and service levels, invoices, two-way asset sync).',
      'Get your clients in: on <b>Client mapping</b>, press <b>Add clients from organizations</b> to make a client for each RMM organization, already linked. Admins can turn on adding clients for new organizations on every sync. An organization becomes a client once; if you delete that client, it isn\'t added again.',
      'Or import them: <b>Clients → Import</b> takes a CSV file of clients, then one of contacts (with the client\'s name in a Client column). You see what each row will do before anything is saved, and a client or contact that\'s already there is updated rather than duplicated. Download a template from the same page.',
      'Add licenses, budget lines and projects as usual. If you connect a PSA later, clients with the same name are linked to it automatically on the next sync.',
  ], ['Client mapping' => '/mapping', 'Import' => '/clients/import']) ?>
  <?= $guide('hosted-backups', 'fa-building', 'Match hosted clients\' backups on your own backup server', 'tech', [
      'When you host a client\'s servers and back them up on your own backup server (BDR), ' . $bk() . ' files those backups under your company. Align sorts them into clients on every sync: a machine goes to the client that has a device with the <b>same name</b> in ' . \Align\Providers\Providers::rmmNames() . ' or ' . psa_name() . ' (a full name like <i>server.client.local</i> matches too). Jobs mapped to a company in Veeam Service Provider Console already go to the right client.',
      'Open the <b>Hosted backups</b> tab of Integrations (its badge counts machines not matched yet), the To do list, or follow the dashboard\'s <i>Needs attention</i> item. It opens on <b>Not matched</b>; the other tabs show what\'s sorted into clients and what\'s yours.',
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

  <h6 class="text-uppercase text-muted small fw-bold mt-4">Compliance &amp; documents</h6>
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

  <h6 class="text-uppercase text-muted small fw-bold mt-4">Meetings &amp; reports</h6>
  <?= $guide('meeting', 'fa-handshake', 'Schedule a review and send invitations', 'tech', [
      'Use the <b>+</b> menu → <b>Schedule meeting</b>, or the client\'s <b>Meetings</b>.',
      'Add attendees in one click from the client\'s review invitees, and tick <b>Email invitations to attendees</b>.',
      'With Microsoft 365 or Google Workspace connected, invitations are real Outlook or Google Calendar invites (with an optional Teams or Meet link); changes and cancellations follow automatically. With an SMTP server they are emails with an .ics invitation, which mail apps show with Accept / Decline; updates and cancellations are emailed the same way.',
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
      'Tick what they can see (roadmap, budget, devices &amp; compliance, documents) and do (approve projects, suggest licenses and budget items, send new user and termination requests).',
      'Align emails the invite if email is connected; otherwise copy the one-time link. They set a password and two-factor sign-in.',
      'Contacts are view-only in the portal; changes come to you as requests or by email. Users with devices &amp; compliance access see the service levels summary and can print the report (never the ticket list).',
  ], ['Client portal users' => '/portal-users']) ?>
  <?= $guide('suggestions', 'fa-inbox', 'Review licenses and budget items a client suggests', 'tech', [
      'Portal users with <b>Suggest licenses and budget items</b> (it needs Budget &amp; licensing) see <b>Suggest a license</b> on the portal\'s Licensing page and <b>Suggest a cost</b> on its Budget page. They fill in what they know: product or cost, vendor, how many, price and how often, renewal date and a note.',
      'Nothing changes until you review it. Each suggestion shows on the dashboard under <b>Needs attention</b> and at the top of the client\'s <b>Licensing</b> or <b>Budget</b> page under <b>Suggested by the client</b>.',
      '<b>Review and add</b> opens the usual Add form filled in from the suggestion: correct anything (price, category, contract dates) and save. <b>Decline</b> takes an optional note. The client sees it as added, or declined with your note, and gets an email if <i>Portal suggestions reviewed</i> is on in Settings → Notifications.',
      'Admins can switch suggestions off for every client on <b>Client portal users</b>. Suggestions already sent stay in the review list.',
  ], ['Client portal users' => '/portal-users']) ?>
  <?= $guide('requests', 'fa-user-plus', 'Handle new user and termination requests', 'tech', [
      'Clients send these from the onboarding page or the portal\'s <b>Requests</b>. A new user request asks for the name, job title, start date, location, supervisor, login name and whose permissions to copy; a suspend or termination request asks when to disable the account, whether it\'s temporary, and what to do with their email, remote access, groups and files.',
      'With ' . psa_name() . ' connected, each request becomes a ticket in ' . psa_name() . ' for that client, so it lands in your normal queue. Without ' . psa_name() . ', it\'s emailed to the company email in <b>Settings → General</b>. You also get a notification.',
      'Requests from onboarding are listed on the client\'s <b>Onboarding</b> page. Admins can turn request forms off in <b>Onboarding → Welcome email &amp; guide</b>.',
  ], ['Welcome email & guide' => '/settings/onboarding']) ?>
  <?= $guide('my-emails', 'fa-bell', 'Choose which emails you get', 'viewer', [
      'Open <b>Account &amp; 2FA</b> from the menu under your name, then <b>Email notifications</b>.',
      'Turn each email on or off, and choose all clients or only the ones you\'re vCIO for.',
  ], ['Account' => '/account']) ?>

  <?php if ($isAdmin): ?>
  <h6 class="text-uppercase text-muted small fw-bold mt-4">Administration</h6>
  <?php endif; ?>
  <?= $guide('integration', 'fa-plug', 'Connect or change an integration', 'admin', [
      'Open <b>Integrations</b> and click the card (' . psa_name() . ', ' . $rmm(', ') . ', ' . $bk(', ') . ', Email, Dell, Lenovo).',
      'Follow <b>How to set it up</b> on the right, enter the details and <b>Save</b>. Keys are encrypted and never shown again; leave a key blank to keep it.',
      'Press <b>Test connection</b>, then run a sync (<b>Integrations → Sync history → Run sync now</b>). Each card shows the result of the last sync.',
  ], ['Integrations' => '/integrations']) ?>
  <?= $guide('setup', 'fa-wand-magic-sparkles', 'Use the setup wizard', 'admin', [
      'On a new install it opens after the first admin signs in (once per sign-in until it\'s finished or skipped). On any install, open it from <b>Settings → General → Open the setup wizard</b>.',
      'Go through the steps in order or jump to any of them on the left. <b>Skip this step</b> marks it as skipped; a tick means it\'s done, whether you did it here or elsewhere (for example on the Integrations page).',
      'Each step uses the normal settings, integration, email and user forms and brings you back to the wizard, so <b>Test connection</b>, error messages and the audit log work as usual.',
      '<b>Finish</b> (or <b>Skip setup for now</b>) stops it opening by itself. The dashboard\'s <b>Getting set up</b> list keeps showing what\'s left.',
  ], ['Setup wizard' => '/setup']) ?>
  <?= $guide('demo', 'fa-flask', 'Try it with demo data', 'admin', [
      'On an install with no clients yet, choose <b>Load demo data</b> on the setup wizard\'s Clients step or under <b>Settings → General → Demo data</b>.',
      'It adds four made-up clients (a dental office, an accounting firm, a veterinary clinic and a law office) with contacts, devices of every age, licenses, budget lines and contracts, roadmap projects waiting for decisions, meetings, a compliance assessment, documents, backup results and a client portal user. Dates follow today, so the plan always looks current. Service levels need a PSA, so they aren\'t included.',
      'To see the client portal, open a demo client\'s <b>Client portal</b> page and make a new invite link for its user.',
      'When you\'re ready to start for real, <b>Remove demo data</b> deletes the demo clients and everything attached to them; anything you added for a real client stays. Remove it before connecting your PSA or RMM.',
  ], ['Settings → General' => '/settings#demo-data']) ?>
  <?= $guide('email', 'fa-envelope', 'Set up email and notifications', 'admin', [
      'Open <b>Integrations → Email</b> and choose Microsoft 365, Google Workspace or an SMTP server. For Microsoft or Google, pick the connection type and follow the steps on the page.',
      'For SMTP, enter the server, port and security (STARTTLS on 587, or TLS from the start on 465), the user name and password or API key if the server needs one, and the From address. A relay on your own network that trusts this server can use port 25 with no sign-in; a password is never sent without encryption. Switch off the certificate check only for an internal relay with its own certificate.',
      'Save, then <b>Send test</b>: it goes straight away, so any error from the server shows on the page.',
      'Then open <b>Settings → Notifications</b> to choose which emails are sent, who gets them by default, when digests go out and how meeting invitations are sent.',
      'The <b>Email log</b> (on the same page) shows everything sent, queued or failed, with retry.',
  ], ['Mail connection' => '/integrations/email', 'Notifications' => '/settings/notifications']) ?>
  <?= $guide('currency', 'fa-globe', 'Set your currency, date style and timezone', 'admin', [
      'Open <b>Settings → General</b> and find <b>Currency &amp; dates</b>. Choose the currency (its symbol goes where it usually does, or pick before or after), how numbers are written (1,234.56 · 1.234,56 · 1 234,56 · 1\'234.56), the date style (Sep 29, 2026 · 29 Sep 2026 · 2026-09-29), a 12- or 24-hour clock and the first day of the week. The preview shows the result before you save.',
      'It\'s one choice for everyone, so reports and client emails look the same whoever sends them. Amounts are never converted: pick the currency you bill in.',
      'The <b>Timezone</b> is used for meetings, reminders and digest times. Set it before you start scheduling: times already saved aren\'t moved. Left on <i>As set on the server</i>, the one in <code>/etc/msp-align/config.php</code> is used.',
      'CSV exports and the API keep plain numbers and ISO dates (2026-09-29), so spreadsheets and scripts read them the same everywhere; the API also says which currency the amounts are in.',
  ], ['Settings → General' => '/settings#currency-dates']) ?>
  <?= $guide('users', 'fa-user-shield', 'Add staff and manage access', 'admin', [
      'Open <b>Users → New user</b>. Choose the role: <b>Viewer</b> reads, <b>Tech</b> edits clients and plans, <b>Admin</b> also manages settings, integrations and users.',
      'Everyone sets up two-factor sign-in at first sign-in. New phone? Use <b>Account → Replace authenticator</b>: it asks for a code from the new app and one from the old. To skip the code on your own computer for a while, tick <b>Remember this browser</b> after entering it (your password is still asked for); your Account page lists and forgets remembered browsers. Lost the phone? An admin uses <b>Remove 2FA</b> on the user list. That also gives a one-time password (pass it on): they choose their own and set up two-factor at the next sign-in. For your own account, use your Account page.',
      'Disable accounts as soon as someone leaves; the audit log keeps their history.',
  ], ['Users' => '/users']) ?>
  <?= $guide('update', 'fa-circle-arrow-up', 'Update Align', 'admin', [
      'When a new version is out you\'ll see a banner and a <b>new</b> badge on Settings.',
      'Open <b>Settings → Updates &amp; backups</b>, read what\'s new, tick the confirmation and press <b>Update</b>. It makes a safety copy first and usually takes a few minutes.',
      'A progress window shows each step (safety copy, download, install, final checks) with a progress bar and the time so far. Leave the page open; it reloads on its own when the update is done. While it runs, everyone else sees a <b>Please wait</b> page that also refreshes itself.',
      'The web server restarts near the end, so the window may briefly say <i>Restarting the web server…</i>. That\'s normal. If something fails, the window says so; the details, the job log and any safety copy are on the same page.',
      'From the server: <code>sudo msp-align-update</code> does the same. With nothing new it installs the current release again, which repairs packages, permissions and services.',
      'Since 2.0, only releases signed with the MSP Align release key are installed; the page shows the key\'s fingerprint. If it ever says a release was refused because it isn\'t signed, don\'t install it by hand: check mspalign.org first.',
      'Running in <b>Docker</b> (1.44)? The page shows what\'s new but not the Update button: download a backup, then on the Docker host run <code>docker compose pull &amp;&amp; docker compose up -d</code>. The new version updates the database as it starts.',
  ], ['Updates & backups' => '/settings/system']) ?>
  <?= $guide('backup', 'fa-download', 'Back up and restore Align', 'admin', [
      'Backups aren\'t kept on the server. Open <b>Settings → Updates &amp; backups</b> and press <b>Download backup</b>; keep the file somewhere safe.',
      'To restore: upload the file under <b>Restore</b>, paste the backup private key (<code>AGE-SECRET-KEY-1…</code>) and press <b>Test this backup</b> first. <b>Restore</b> needs your two-factor code and signs everyone out. The same progress window follows the restore and reloads when it\'s done.',
      'Keep the backup key in your password manager; <b>Check key</b> confirms it matches this server.',
  ], ['Updates & backups' => '/settings/system']) ?>
  <?= $guide('diagnostics', 'fa-stethoscope', 'Check the server\'s health', 'admin', [
      'Open <b>Settings → Diagnostics</b>. The row on top sums up the server, the app, the database, storage and the background jobs: green is healthy, yellow needs a look, red is a problem. Each line below says what it found and what to do.',
      '<b>Background jobs</b> shows whether the hourly sync, the 2-minute PSA check, email, the nightly audit log check and the update check ran when they should. One that\'s late usually means its timer stopped: running the installer again (<code>sudo msp-align-update</code>) puts the timers back.',
      'Asking for help? <b>Copy report</b> or <b>Download report</b> gives the same facts as text, without your site address or any error text, ready to paste into a support request or a GitHub issue.',
  ], ['Diagnostics' => '/settings/diagnostics']) ?>
  <?= $guide('settings', 'fa-gear', 'Change settings: planning, onboarding, branding and more', 'admin', [
      '<b>Settings → General</b>: company details on reports and sign-out timers.',
      '<b>Settings → Planning &amp; lifecycle</b>: budget year, meeting length, warning thresholds, warranty re-checks, lifespans and replacement costs. <b>OS support dates</b> has the Windows end-of-support table.',
      '<b>Settings → Notifications</b>: which emails are sent and when (see <a href="#guide-email">Set up email</a>).',
      '<b>Onboarding → Welcome email &amp; guide</b> (moved out of Settings in 2.2): the welcome email, the guides on the onboarding page, how long links last, and whether request forms are on (see <a href="#guide-onboarding">Welcome and onboard</a>).',
      '<b>Settings → Branding</b>: app name, logo, brand colour and a dark or light menu (also used on the client portal, onboarding page and reports). The preview on the right shows the app, the sign-in page and the client portal in light or dark mode before you save.',
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
        ['fa-gauge-high', 'Top', [['Dashboard', 'Needs attention (one list across every client), portfolio health tiles, the 3-year plan and the rest of your cards. Customize to reorder or hide cards.'], ['To do', 'Setup and upkeep waiting on your team: hardware to categorize, licenses to price, clients to link, hosted backups to match, client suggestions to review.']]],
        ['fa-users', 'Clients', [['Clients', 'Every client. Open one for its own menu, grouped as Their IT (contacts, devices, licensing, backups, service levels), The plan (roadmap, budget, compliance, documents) and Meetings (meetings, reports, client portal). The … menu has Welcome & onboarding and the PSA link.'], ['Contacts', 'Everyone at every client, searchable.'], ['Devices & assets', 'Every client\'s devices in one list, searchable by name, serial or user, with the same views as a client\'s page. Unassigned hardware is a button on it.']]],
        ['fa-diagram-project', 'Planning', [['Projects', 'All projects across clients by quarter.'], ['Budgets', 'Every client\'s technology budget side by side.'], ['Licensing', 'Every license, with prices and contract dates.'], ['Renewals', 'Licenses and contracts ending or up for renegotiation.']]],
        ['fa-handshake', 'Meetings & reports', [['Meetings', 'Upcoming and past meetings, with the calendar as a second tab.'], ['Reports', 'Pick a client and open any report: QBR pack, assets, roadmap, budget, backups, service levels, compliance. All-clients reports: portfolio, backups, service levels, renewals.']]],
        ['fa-clipboard-check', 'Compliance', [['Compliance', 'Scores for every client and framework (admins also see the Frameworks tab and crosswalk tags).'], ['Documents', 'Internal and client documents, policies and templates (security policies, WISP, CMMC, HIPAA and privacy).']]],
        ['fa-user-shield', 'Admin', [['Integrations', 'Tabs: Connections (admins: connect and check ' . psa_name() . ', ' . $rmm(', ') . ', ' . $bk(', ') . ', email and warranty lookups), Client mapping (which ' . \Align\Providers\Providers::rmmNames() . ' organization and ' . $bk() . ' company belongs to each client), Hosted backups and Sync history (with Run sync now).'], ['People', 'Tabs: Staff (accounts and roles, admins) and Client portal users (everyone with a portal sign-in).'], ['Settings', 'General, planning & lifecycle, OS support dates, notifications, onboarding, branding, API, updates & backups.'], ['Audit log', 'Who did what, and who viewed which client records.']]],
    ] as [$icon, $title, $items]): ?>
      <div class="col-md-6 col-xl-4 d-flex">
        <div class="card flex-fill">
          <div class="card-header py-2"><h3 class="card-title"><i class="fas <?= $icon ?> fa-fw text-secondary me-2"></i><?= e($title) ?></h3></div>
          <div class="card-body small"><dl class="mb-0"><?php foreach ($items as [$k, $v]): ?><dt><?= e($k) ?></dt><dd class="text-muted"><?= e($v) ?></dd><?php endforeach; ?></dl></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="small text-muted">Top bar: search clients, devices (name, serial, user), contacts and licenses from any page, <b>+</b> to schedule a meeting or add a client or device, <b>?</b> for this help, and your name for your account, two-factor and email choices.</p>
</div>

<div class="tab-pane fade" id="tab-data" role="tabpanel">
  <div class="row">
    <div class="col-xl-7">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-arrows-rotate me-2"></i>Where data comes from</h3></div>
        <div class="card-body small">
          <table class="table table-sm mb-0">
            <tr><th>Clients &amp; address</th><td><?= e(psa_name()) ?> (read-only in Align)</td></tr>
            <tr><th>Contacts</th><td><?= e(psa_name()) ?>, <b>two-way</b>: contacts added in Align or on the onboarding page are created there, detail edits go back, and archiving or restoring in Align does the same there (1.44.1). Each client's Contacts page says which way it syncs</td></tr>
            <tr><th>Computers &amp; servers</th><td><?= e(\Align\Providers\Providers::rmmNames()) ?></td></tr>
            <tr><th>Network gear, printers, UPS…</th><td><?= e(psa_name()) ?> assets, <b>two-way</b>: edits in either place sync</td></tr>
            <tr><th>Licenses</th><td><?= e(psa_name()) ?> (read-only); prices and contracts in Align</td></tr>
            <tr><th>Managed services estimate</th><td><?= e(psa_name()) ?> recurring invoices, each by its own frequency (monthly, yearly); without them, all invoices of the last 3 months</td></tr>
            <tr><th>Warranties</th><td>Dell / Lenovo lookups, or the <?= e(psa_name()) ?> warranty date</td></tr>
            <tr><th>Service levels (SLA)</th><td><?= e(psa_name()) ?> tickets and SLA results, hourly; only numbers, subjects, priorities and times are copied</td></tr>
            <tr><th>New user &amp; termination requests</th><td>Sent from onboarding or the portal; become <?= e(psa_name()) ?> tickets (or an email without <?= e(psa_name()) ?>)</td></tr>
            <tr><th>Backups</th><td><?= e($bk(', ')) ?> (read-only), hourly: servers, VMs, agents and Microsoft 365</td></tr>
            <tr><th>Email &amp; invitations</th><td>Microsoft 365 or Google Workspace over OAuth, or any SMTP server, sent every minute</td></tr>
            <tr><th>Updates &amp; app backups</th><td>GitHub checked every 6 hours; backups downloaded from Settings → Updates &amp; backups</td></tr>
            <tr><th>Projects, budget lines, compliance, documents, meetings, onboarding progress, dashboard layout</th><td>Align</td></tr>
          </table>
          <p class="mt-2 mb-0 text-muted">Fields marked <span class="badge text-bg-light border"><?= e(psa_name()) ?></span> are managed in <?= e(psa_name()) ?>; change them there and they update here within minutes.</p>
        </div>
      </div>
    </div>
    <div class="col-xl-5">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-book me-2"></i>Terms</h3></div>
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
          <h3 class="h6"><i class="fas fa-user-check fa-fw text-secondary me-1"></i>Terms of use</h3>
          <p class="small text-muted">The rules for staff accounts: keeping sign-ins private, client confidentiality, keeping sensitive personal data out, monitoring, and that planning figures are estimates.</p>
          <a class="btn btn-sm btn-default" href="/terms">Read the terms</a>
        </div>
      </div>
    </div>
    <div class="col-md-4 d-flex">
      <div class="card flex-fill">
        <div class="card-body">
          <h3 class="h6"><i class="fas fa-door-open fa-fw text-secondary me-1"></i>Client portal terms</h3>
          <p class="small text-muted">What clients agree to when they sign in to the portal: their own account, confidentiality, what an approval means, and that prices are estimates. Linked from the portal sign-in page and footer.</p>
          <a class="btn btn-sm btn-default" href="/portal/terms">Read the portal terms</a>
        </div>
      </div>
    </div>
    <div class="col-md-4 d-flex">
      <div class="card flex-fill">
        <div class="card-body">
          <h3 class="h6"><i class="fas fa-scale-balanced fa-fw text-secondary me-1"></i>License</h3>
          <p class="small text-muted">MSP Align is free software under the GNU Affero General Public License v3. Anyone who runs a changed version for others must share its source. Includes the third-party components and their licenses.</p>
          <a class="btn btn-sm btn-default me-1" href="/license">License &amp; credits</a><a class="btn btn-sm btn-default" href="<?= e(\Align\Controllers\LegalController::sourceUrl()) ?>" target="_blank" rel="noopener">Source code</a>
        </div>
      </div>
    </div>
  </div>
  <p class="small text-muted">Terms last updated <?= e(fmt_date(\Align\Controllers\LegalController::TERMS_UPDATED)) ?>.<?= $isAdmin ? ' The terms use the company name, email and phone from Settings → General.' : '' ?></p>
</div>
</div>

<?php echo str_replace(['Align keeps', 'Align syncs', 'Align doesn\'t', 'here in Align', 'in Align', '>Align<', 'Align emails', 'Update Align', 'restore Align', 'Align makes'], [e(\Align\Branding::name()) . ' keeps', 'it syncs', 'the app doesn\'t', 'here', 'in ' . e(\Align\Branding::name()), '>' . e(\Align\Branding::name()) . '<', e(\Align\Branding::name()) . ' emails', 'Update ' . e(\Align\Branding::name()), 'restore ' . e(\Align\Branding::name()), e(\Align\Branding::name()) . ' makes'], (string) ob_get_clean()); ?>

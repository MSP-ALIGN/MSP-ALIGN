<?php ob_start(); ?>
<?php
$step = function (int $n, string $icon, string $title, string $body, array $links) {
    $l = implode(' ', array_map(fn($k, $v) => '<a class="btn btn-xs btn-default mr-1 mb-1" href="' . e($v) . '">' . e($k) . '</a>', array_keys($links), $links));
    return '<div class="help-step d-flex mb-3"><div class="help-num mr-3">' . $n . '</div><div class="flex-grow-1"><h5 class="mb-1"><i class="fas ' . $icon . ' text-secondary mr-2"></i>' . e($title) . '</h5>'
        . '<p class="mb-1 text-muted">' . $body . '</p><div>' . $l . '</div></div></div>';
};
?>
<h1 class="h3 mb-1">How to use <?= e(\Align\Branding::name()) ?></h1>
<p class="text-muted">Align keeps each client's technology picture in one place: what they have, what's changing, what it costs and what's coming up. Most of it fills itself in from ITFlow and NinjaOne; you add the planning.</p>

<div class="row">
  <div class="col-xl-8">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-route mr-2"></i>The workflow</h3></div>
      <div class="card-body">
        <h6 class="text-uppercase text-muted small font-weight-bold">Once, when you set up</h6>
        <?= $step(1, 'fa-plug', 'Connect ITFlow and NinjaOne', 'Add the API keys under Settings and press <b>Test</b>, then run a sync. After that Align syncs hourly, and checks ITFlow for asset, contact and license changes every 2 minutes.', ['Settings' => '/settings', 'Sync' => '/sync']) ?>
        <?= $step(2, 'fa-link', 'Link clients', 'Clients with the same name in both systems link automatically. Link the rest on Client mapping. Clients you don\'t plan for (break-fix, vendors) can be removed from planning.', ['Client mapping' => '/mapping', 'Clients' => '/clients']) ?>
        <?= $step(3, 'fa-circle-question', 'Sort out unassigned hardware', 'ITFlow assets with a type Align doesn\'t recognize land in Unassigned hardware. Give them a type and ITFlow is updated to match.', ['Unassigned hardware' => '/devices/unassigned']) ?>
        <h6 class="text-uppercase text-muted small font-weight-bold mt-4">For each client (the overview's Planning checklist tracks this)</h6>
        <?= $step(4, 'fa-address-book', 'Contacts', 'Contacts come from ITFlow. Mark the <b>decision maker</b> and the people you <b>invite to reviews</b>. They can then be added to a meeting in one click.', ['Contacts' => '/contacts']) ?>
        <?= $step(5, 'fa-desktop', 'Devices & lifecycle', 'Check devices without an in-service date (they can\'t be planned) and set replacement costs where the policy default is wrong. Edits go to ITFlow automatically.', []) ?>
        <?= $step(6, 'fa-key', 'Licensing & contracts', 'Licenses sync from ITFlow\'s Software section without prices. Add the price and billing cycle, plus contract term, end and renegotiate-by dates.', ['Licenses needing a price' => '/licenses?filter=unpriced', 'Renewals' => '/renewals']) ?>
        <?= $step(7, 'fa-clipboard-check', 'Compliance & documents', 'Assign the frameworks the client must meet (including WISP / FTC Safeguards), work the checklist, and link written policies from Documents as evidence.', ['Compliance' => '/compliance', 'Documents' => '/documents']) ?>
        <?= $step(8, 'fa-road', 'Roadmap & projects', 'Add projects with a target quarter, budget and description. Hardware reaching end of life, OS end of support and warranty dates appear on the roadmap automatically.', ['Projects' => '/projects']) ?>
        <?= $step(9, 'fa-coins', 'Budget', 'The budget builds itself from licensing, hardware, projects and managed services. Add a <b>Managed services</b> line with your agreement amount, plus internet, phones, cloud and other costs.', ['Budgets' => '/budget']) ?>
        <?= $step(10, 'fa-door-open', 'Client portal (optional)', 'Invite the owner or office manager from the client\'s <b>Client portal</b> page. Choose what they can see (roadmap, budget, devices, documents) and do (approve projects, update contacts). They sign in at <b>/portal</b> and only ever see their own company. Their approvals show on the roadmap and the dashboard.', ['Client portal users' => '/portal-users']) ?>
        <?= $step(11, 'fa-handshake', 'Meet and report', 'Schedule the review; the meeting page lists talking points and links the Assets, Roadmap and Budget reports (Print → Save as PDF).', ['Meetings' => '/meetings', 'Reports' => '/reports']) ?>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
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
          <tr><th>Warranties</th><td>Dell / Lenovo lookups</td></tr>
          <tr><th>Projects, budget lines, compliance, documents, meetings</th><td>Align</td></tr>
        </table>
        <p class="mt-2 mb-0 text-muted">Fields marked <span class="badge badge-light border">ITFlow</span> are managed in ITFlow; change them there and they update here within minutes.</p>
      </div>
    </div>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-book mr-2"></i>Terms</h3></div>
      <div class="card-body small">
        <dl class="mb-0">
          <dt>3-year plan (hardware &amp; projects)</dt><dd>One-time spending: replacements in the quarter each device reaches end of life, plus project budgets.</dd>
          <dt>Technology budget</dt><dd>Everything: the 3-year plan plus licensing, managed services and running costs, by quarter.</dd>
          <dt>Planning checklist</dt><dd>On each client's overview: what's done and what's next before their plan and budget are complete.</dd>
          <dt>Unassigned</dt><dd>An ITFlow asset whose type needs choosing.</dd>
          <dt>Retired / archived</dt><dd>Hidden but kept. Sync never permanently deletes anything.</dd>
          <dt>Roles</dt><dd><b>Viewer</b> reads, <b>Tech</b> edits clients and plans and manages client portal access, <b>Admin</b> also manages settings and users.</dd>
          <dt>Client portal user</dt><dd>A sign-in for someone at a client. Separate from staff accounts; limited to one client and the sections you tick.</dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<?php echo str_replace(['Align keeps', 'Align syncs', 'Align doesn\'t', 'here in Align', 'in Align', '>Align<'], [e(\Align\Branding::name()) . ' keeps', 'it syncs', 'the portal doesn\'t', 'here', 'in ' . e(\Align\Branding::name()), '>' . e(\Align\Branding::name()) . '<'], (string) ob_get_clean()); ?>

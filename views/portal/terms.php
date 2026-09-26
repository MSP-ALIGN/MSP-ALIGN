<?php
/** @var array $company; string $updated */
$c = e($company['name']);
$contact = $company['email'] !== '' ? '<a href="mailto:' . e($company['email']) . '">' . e($company['email']) . '</a>' : 'your IT team';
?>
<div class="card legal">
  <div class="card-body">
    <h1 class="h3 mb-1">Client portal terms of use</h1>
    <p class="text-muted small mb-4">Last updated <?= e(fmt_date($updated)) ?></p>

    <p>The client portal is provided by <?= $c ?> ("we", "us") so the people we work with can see their organization's technology plan and take part in it. By signing in you agree to these terms. They add to the service agreement between your organization and us (the "Agreement"); if they ever conflict, the Agreement wins.</p>

    <h2 class="h5 mt-4">1. Your account</h2>
    <ul>
      <li>Accounts are by invitation, for named people at our clients. Your account is for you alone: don't share it or your sign-in codes.</li>
      <li>Two-factor sign-in is required. Keep your password private and tell us straight away if you think someone else has used your account.</li>
      <li>Let us know when someone leaves your organization so we can close their access. We may suspend or remove access at any time, for example when asked by your organization.</li>
    </ul>

    <h2 class="h5 mt-4">2. What you can see</h2>
    <p>You only see information about your own organization, and only the sections your organization asked us to give you. That information is confidential: use it for your organization's business and don't share it more widely than needed.</p>

    <h2 class="h5 mt-4">3. Decisions and changes you make</h2>
    <ul>
      <li>When you approve or decline a proposed project, we record it as your organization's decision and may rely on it to plan and schedule work. Only approve things you're authorized to approve.</li>
      <li>Prices, budgets and dates in the portal are estimates for planning. Final pricing and terms come from our quotes, invoices and the Agreement.</li>
      <li>Contact details you add or change may be copied into the systems we use to support you.</li>
    </ul>

    <h2 class="h5 mt-4">4. Information may not be complete</h2>
    <p>Device ages, warranty and support dates, backup status and compliance scores come from monitoring and documentation tools and can be out of date. Ask us before making important decisions based on them. A compliance score is a progress guide, not a certification or legal advice.</p>

    <h2 class="h5 mt-4">5. Fair use</h2>
    <p>Don't try to reach information that isn't yours, get around sign-in or security, use scripts to download data, or interfere with the portal. If you notice a security problem, tell us at <?= $contact ?>.</p>

    <h2 class="h5 mt-4">6. Records and privacy</h2>
    <p>For security, we record sign-ins and what is viewed and changed in the portal, with the time and network address, and keep those records for six years. We use your name, email and activity only to run the portal and support your organization, as described in the Agreement. We don't sell your information.</p>

    <h2 class="h5 mt-4">7. Availability</h2>
    <p>We aim to keep the portal available but it may be down for maintenance or other reasons. It is not a way to report emergencies or urgent problems; contact us directly for those.</p>

    <h2 class="h5 mt-4">8. Responsibility</h2>
    <p>The portal is provided as a convenience, as is. To the extent the law allows, our responsibility is as set out in the Agreement. These terms are governed by the law that governs the Agreement or, if there is none, the laws of the State of California.</p>

    <h2 class="h5 mt-4">9. Changes and questions</h2>
    <p class="mb-0">We may update these terms; the date at the top shows the current version and continuing to use the portal means you accept it. Questions: <?= $contact ?><?= $company['phone'] !== '' ? ' or ' . e($company['phone']) : '' ?>.</p>
  </div>
</div>

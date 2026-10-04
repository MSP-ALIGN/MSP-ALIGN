<?php
/**
 * Staff terms of use (public). @var array $company; string $updated, $source
 * Security: the company's name, email and phone come from Settings and are escaped once here ($c, $contact) and
 * printed as built; the email only ever goes after "mailto:", so it can't become another kind of link.
 */
$c = e($company['name']);
$contact = $company['email'] !== '' ? '<a href="mailto:' . e($company['email']) . '">' . e($company['email']) . '</a>' : 'your administrator';
?>
<div class="card legal">
  <div class="card-body">
    <h1 class="h3 mb-1">Terms of use</h1>
    <p class="text-muted small mb-4"><?= e(\Align\Branding::name()) ?> · Last updated <?= e(fmt_date($updated)) ?> · For clients using the client portal, see the <a href="/portal/terms">client portal terms</a>.</p>

    <p><?= e(\Align\Branding::name()) ?> ("the app") is operated by <?= $c ?> ("we", "us") to plan and manage technology for our clients. These terms apply to everyone with a staff account. By signing in you agree to them.</p>

    <h2 class="h5 mt-4">1. Who may use the app</h2>
    <p>Only people given an account by one of our administrators, for work on behalf of <?= $c ?>. Accounts are personal. Your role (viewer, tech or admin) decides what you can see and change; don't try to go beyond it.</p>

    <h2 class="h5 mt-4">2. Your account and sign-in</h2>
    <ul>
      <li>Keep your password to yourself and don't reuse it elsewhere. Two-factor sign-in is required for every account.</li>
      <li>Never share your account or sign in for someone else. Sign out on shared computers; the app also signs you out when idle.</li>
      <li>Tell <?= $contact ?> straight away if you think your password, authenticator or device has been compromised.</li>
    </ul>

    <h2 class="h5 mt-4">3. Client information is confidential</h2>
    <p>The app holds confidential information about our clients: their people, systems, budgets, security posture and plans. Look at and change only what you need for your work. Don't copy, export, print or share it outside <?= $c ?> except as your job requires and our agreements with clients allow. Reports and exports you create are covered by the same rules.</p>

    <h2 class="h5 mt-4">4. Keep sensitive personal data out</h2>
    <p>The app is not designed to store patient records or other highly sensitive personal data. Don't put protected health information (PHI), Social Security or other government ID numbers, payment card or bank details, or passwords and other secrets into notes, documents, meetings or any other free-text field. Store credentials in the tools meant for them. If sensitive data is entered by mistake, remove it and tell an administrator.</p>

    <h2 class="h5 mt-4">5. Acceptable use</h2>
    <p>Don't use the app to: access accounts or data you aren't authorized for; test, probe or bypass its security; upload malware or harmful content; scrape or bulk-download data with scripts; harass anyone; or do anything unlawful. Report security problems to <?= $contact ?> instead of testing them yourself.</p>

    <h2 class="h5 mt-4">6. Monitoring and records</h2>
    <p>For security and compliance, the app records sign-ins, changes, exports and which client records are viewed, with the time and network address. These records are tamper-evident, kept for six years, and reviewed by administrators. Don't expect privacy in your use of the app.</p>

    <h2 class="h5 mt-4">7. Connected services</h2>
    <p>The app connects to other services (for example ITFlow, NinjaOne, Veeam, Microsoft 365, Google Workspace, Dell and Lenovo). Your use of those services stays subject to their own terms. Administrators set up those connections with the least access needed and keep the keys secret.</p>

    <h2 class="h5 mt-4">8. Estimates, not guarantees</h2>
    <p>Lifecycle dates, replacement costs, budgets, backup status and compliance scores are worked out from synced data and settings, and can be incomplete or out of date. Check important figures before relying on them or sharing them with a client. A compliance score is a planning aid, not a certification or legal advice.</p>

    <h2 class="h5 mt-4">9. Changes and suspension</h2>
    <p>Administrators may change your access or disable your account at any time, for example when your role changes or you leave. We may update these terms; the date at the top shows the latest version, and continuing to use the app means you accept it.</p>

    <h2 class="h5 mt-4">10. The software and its license</h2>
    <p>The app's software is free software licensed under the <a href="/license">GNU Affero General Public License, version 3</a>. Nothing in these terms limits the rights that license gives you. These terms cover how you use this installation of the app. The software comes <b>without any warranty</b>, to the extent the law allows (see sections 15 and 16 of the license).</p>

    <h2 class="h5 mt-4">11. Questions</h2>
    <p class="mb-0">Contact <?= $contact ?><?= $company['phone'] !== '' ? ' or call ' . e($company['phone']) : '' ?>.</p>
  </div>
</div>

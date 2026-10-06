<?php
use Align\Controllers\LegalController;

/**
 * License page (public). @var array $company; string $source (an http(s) URL, see LegalController::sourceUrl)
 * Security: the third-party list is a constant in code; every value is escaped anyway.
 */
?>
<div class="card legal">
  <div class="card-body">
    <h1 class="h3 mb-1">License</h1>
    <p class="text-muted small mb-4"><?= e(\Align\Branding::name()) ?> <?= e(APP_VERSION) ?></p>

    <p><b>MSP Align</b>. Copyright © 2026 Mountaineer IT Inc. and MSP Align contributors.</p>
    <p>This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.</p>
    <p>This program is distributed in the hope that it will be useful, but <b>without any warranty</b>; without even the implied warranty of merchantability or fitness for a particular purpose. See the GNU Affero General Public License for more details.</p>
    <p>
      <a class="btn btn-sm btn-default me-1 mb-1" href="/license/full" target="_blank" rel="noopener"><i class="fas fa-file-lines me-1"></i>Full license text (AGPL-3.0)</a>
      <a class="btn btn-sm btn-default me-1 mb-1" href="<?= e($source) ?>" target="_blank" rel="noopener"><i class="fab fa-github me-1"></i>Source code</a>
    </p>

    <h2 class="h5 mt-4">What this means in plain words</h2>
    <ul>
      <li>You may use, study, change and share the software, including commercially.</li>
      <li>If you share it, or let people use a <b>changed</b> version over a network (for example hosting it for others), you must offer them the source code of your version under the same license. That's why every page links to the source.</li>
      <li>Keep the copyright and license notices. You can't add restrictions of your own.</li>
      <li>It comes as is, with no warranty. This summary isn't legal advice; the license text is what counts.</li>
    </ul>

    <h2 class="h5 mt-4">Third-party software included</h2>
    <p class="small text-muted">These parts keep their own licenses, all of which allow use in this program.</p>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>Component</th><th>Version</th><th>License</th><th></th></tr></thead>
        <tbody>
          <?php foreach (LegalController::THIRD_PARTY as [$name, $ver, $lic, $url]): ?>
            <tr><td><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($name) ?></a></td><td><?= e($ver) ?></td><td><?= e($lic) ?></td>
              <td class="text-end"><a class="small" href="/license/third-party/<?= e(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name))) ?>" target="_blank" rel="noopener">License text</a></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <h2 class="h5 mt-4">Trademarks</h2>
    <p class="small">The license covers the code, not the MSP Align name and logo. Running the app for your business, rebranding it under Settings → Branding and sharing unchanged copies are all fine; a changed version shared with others needs its own name. See the <a href="https://github.com/MSP-ALIGN/MSP-ALIGN/blob/main/TRADEMARKS.md" target="_blank" rel="noopener">trademark policy</a>.</p>
    <p class="small">Other product and standard names (ITFlow, NinjaOne, Veeam, Microsoft, Google, Dell, Lenovo, CIS Controls®, ISO/IEC 27001, PCI DSS, SOC 2 and others) are trademarks of their owners and appear only to say what the app works with; MSP Align isn't affiliated with or endorsed by them. The compliance checklists cite official control numbers, but their titles and guidance are MSP Align's own summaries, not the text of the standards.</p>
    <p class="small text-muted mt-3 mb-0">The app also uses services you connect to it (ITFlow, NinjaOne, Veeam, Microsoft, Google, Dell, Lenovo); those are covered by their providers' own terms. Using this app is also subject to the <a href="/terms">terms of use</a>.</p>
  </div>
</div>

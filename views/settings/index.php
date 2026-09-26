<?php
use Align\Lifecycle\Lifecycle;

$num = fn(string $name, string $label, string $prefix = '', string $suffix = '') => '<div class="form-group"><label class="small">' . e($label) . '</label><div class="input-group input-group-sm">'
    . ($prefix ? '<div class="input-group-prepend"><span class="input-group-text">' . e($prefix) . '</span></div>' : '')
    . '<input type="number" step="any" name="' . e($name) . '" class="form-control" value="' . e($v[$name] ?? '') . '">'
    . ($suffix ? '<div class="input-group-append"><span class="input-group-text">' . e($suffix) . '</span></div>' : '') . '</div></div>';
?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'general']) ?>
<form method="post" action="/settings">
  <?= csrf_field() ?>
  <input type="hidden" name="_tab" value="general">
  <div class="row">
    <div class="col-lg-6">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-building mr-2"></i>Company details on reports</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group col-md-6"><label>Company name</label><input name="company_name" class="form-control" value="<?= e($v['company_name']) ?>"></div>
            <div class="form-group col-md-6"><label>Phone</label><input name="company_phone" class="form-control" value="<?= e($v['company_phone']) ?>"></div>
            <div class="form-group col-md-6"><label>Email</label><input name="company_email" class="form-control" value="<?= e($v['company_email']) ?>"></div>
            <div class="form-group col-md-6"><label>Website</label><input name="company_website" class="form-control" value="<?= e($v['company_website']) ?>"></div>
          </div>
          <div class="form-group mb-0"><label>Report footer</label><textarea name="report_footer" class="form-control" rows="2" placeholder="Shown at the bottom of printed reports"><?= e($v['report_footer']) ?></textarea></div>
        </div>
      </div>
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-scale-balanced mr-2"></i>Terms &amp; license</h3></div>
        <div class="card-body">
          <div class="form-group"><label>Source code link</label><input type="url" name="source_url" class="form-control" value="<?= e($v['source_url'] ?? '') ?>" placeholder="<?= e(\Align\Controllers\LegalController::DEFAULT_SOURCE) ?>">
            <small class="text-muted">Shown as "Source" in the footer and on the License page. The software is licensed under the AGPL-3.0: if you run a changed version for other people, this must lead to your version's source code.</small></div>
          <p class="small mb-0"><a href="/terms">Terms of use</a> (staff) · <a href="/portal/terms">Client portal terms</a> · <a href="/license">License</a>. The terms use the company name, email and phone above.</p>
        </div>
      </div>
      <p class="small text-muted">Logo, colours and the app name are under <a href="/settings/branding">Branding</a>.</p>
    </div>
    <div class="col-lg-6">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-lock mr-2"></i>Security</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="col-md-6"><?= $num('session_idle_minutes', 'Sign out after inactivity (5–60)', '', 'min') ?></div>
            <div class="col-md-6"><?= $num('session_max_hours', 'Sign out after, however active (1–24)', '', 'hours') ?></div>
          </div>
          <p class="small text-muted mb-0">Defaults: <?= \Align\Security::IDLE_DEFAULT_MIN ?> minutes and <?= \Align\Security::MAX_DEFAULT_HOURS ?> hours. Applies to staff and client portal users. Two-factor sign-in is always required for both, sign-ins lock for 15 minutes after 5 failures, and the <a href="/audit">audit log</a> is hash-chained and kept <?= \Align\AuditChain::RETENTION_YEARS ?> years.</p>
        </div>
      </div>
    </div>
  </div>
  <button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save</button>
</form>

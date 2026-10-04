<?php
/**
 * Settings → General (admins). @var array $v saved values (plain settings only; no secrets).
 * Security: every value is escaped; the confirm rules go into a data attribute as escaped JSON.
 */
use Align\Lifecycle\Lifecycle;

$num = fn(string $name, string $label, string $prefix = '', string $suffix = '') => '<div class="mb-3"><label class="small">' . e($label) . '</label><div class="input-group input-group-sm">'
    . ($prefix ? '<span class="input-group-text">' . e($prefix) . '</span>' : '')
    . '<input type="number" step="any" name="' . e($name) . '" class="form-control" value="' . e($v[$name] ?? '') . '">'
    . ($suffix ? '<span class="input-group-text">' . e($suffix) . '</span>' : '') . '</div></div>';
?>
<?= \Align\View::fetch('settings/_tabs', ['tab' => 'general']) ?>
<form method="post" action="/settings" data-unsaved data-confirm-rules="<?= e(json_encode([
    ['changed' => 'remember_2fa_days', 'atmost' => ['remember_2fa_days' => 0], 'title' => 'Stop remembering browsers?', 'ok' => 'Save', 'danger' => true,
     'text' => 'Every remembered browser is forgotten, for all staff and client portal users: everyone enters a two-factor code at their next sign-in.'],
])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="_tab" value="general">
  <div class="row">
    <div class="col-lg-6">
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-building me-2"></i>Company details on reports</h3></div>
        <div class="card-body">
          <div class="row g-2">
            <div class="mb-3 col-md-6"><label>Company name</label><input name="company_name" class="form-control" value="<?= e($v['company_name']) ?>"></div>
            <div class="mb-3 col-md-6"><label>Phone</label><input name="company_phone" class="form-control" value="<?= e($v['company_phone']) ?>"></div>
            <div class="mb-3 col-md-6"><label>Email</label><input name="company_email" class="form-control" value="<?= e($v['company_email']) ?>"></div>
            <div class="mb-3 col-md-6"><label>Website</label><input name="company_website" class="form-control" value="<?= e($v['company_website']) ?>"></div>
          </div>
          <div class="mb-3 mb-0"><label>Report footer</label><textarea name="report_footer" class="form-control" rows="2" placeholder="Shown at the bottom of printed reports"><?= e($v['report_footer']) ?></textarea></div>
        </div>
      </div>
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-scale-balanced me-2"></i>Terms &amp; license</h3></div>
        <div class="card-body">
          <div class="mb-3"><label>Source code link</label><input type="url" name="source_url" class="form-control" value="<?= e($v['source_url'] ?? '') ?>" placeholder="<?= e(\Align\Controllers\LegalController::DEFAULT_SOURCE) ?>">
            <small class="text-muted">Shown as "Source" in the footer and on the License page. The software is licensed under the AGPL-3.0: if you run a changed version for other people, this must lead to your version's source code.</small></div>
          <p class="small mb-0"><a href="/terms">Terms of use</a> (staff) · <a href="/portal/terms">Client portal terms</a> · <a href="/license">License</a>. The terms use the company name, email and phone above.</p>
        </div>
      </div>
      <p class="small text-muted">Logo, colours and the app name are under <a href="/settings/branding">Branding</a>. <a href="/setup"><i class="fas fa-wand-magic-sparkles me-1"></i>Open the setup wizard</a> to go through the main settings step by step.</p>
    </div>
    <div class="col-lg-6">
      <?= \Align\View::fetch('settings/_locale', ['v' => $v]) ?>
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-lock me-2"></i>Security</h3></div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-md-6"><?= $num('session_idle_minutes', 'Sign out after inactivity (5–60)', '', 'min') ?></div>
            <div class="col-md-6"><?= $num('session_max_hours', 'Sign out after, however active (1–24)', '', 'hours') ?></div>
            <div class="col-md-6"><?= $num('remember_2fa_days', 'Remember a browser (0–' . \Align\Remember::MAX_DAYS . ', 0 = off)', '', 'days') ?></div>
          </div>
          <p class="small text-muted">After the code, people can tick <b>Remember this browser</b>: on it only the password is asked for, until the days run out, they change their password or authenticator, or sign out everywhere.</p>
          <p class="small text-muted mb-0">Defaults: <?= \Align\Security::IDLE_DEFAULT_MIN ?> minutes and <?= \Align\Security::MAX_DEFAULT_HOURS ?> hours. Applies to staff and client portal users. Two-factor sign-in is always required for both, sign-ins lock for 15 minutes after 5 failures, and the <a href="/audit">audit log</a> is hash-chained and kept <?= \Align\AuditChain::RETENTION_YEARS ?> years.</p>
        </div>
      </div>
    </div>
  </div>
  <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
</form>

<?php $demo = \Align\Demo\Demo::loaded(); $demoBlocked = \Align\Demo\Demo::blocked(); ?>
<div class="card card-outline card-<?= $demo ? 'warning' : 'secondary' ?> mt-3" id="demo-data">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-flask me-2"></i>Demo data</h3></div>
  <div class="card-body d-flex flex-wrap align-items-center">
    <div class="me-auto small pe-3">
      <?php if ($demo): ?>
        <b>Demo data is loaded</b>: <?= count(\Align\Demo\Demo::clientIds()) ?> made-up clients. Remove it before you add real clients or connect your PSA or RMM; everything attached to the demo clients goes with them.
      <?php elseif ($demoBlocked): ?>
        <span class="text-muted">Four made-up clients to try every page with. It can only be added while there are no clients, so it never mixes with real data.</span>
      <?php else: ?>
        Try MSP-ALIGN with four made-up clients: devices of every age, licenses, budgets, projects, meetings, compliance, documents, backups and a client portal user. Remove it with one click when you're ready to start for real.
      <?php endif; ?>
    </div>
    <?php if ($demo): ?>
      <form method="post" action="/demo/remove" class="mt-2 mt-md-0"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" data-confirm="Remove the demo clients and everything attached to them? This can't be undone."><i class="fas fa-trash me-1"></i>Remove demo data</button></form>
    <?php elseif (!$demoBlocked): ?>
      <form method="post" action="/demo/load" class="mt-2 mt-md-0"><?= csrf_field() ?><button class="btn btn-sm btn-default"><i class="fas fa-flask me-1"></i>Load demo data</button></form>
    <?php endif; ?>
  </div>
</div>

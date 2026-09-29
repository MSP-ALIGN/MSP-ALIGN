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
      <?php
      $F = \Align\Fmt::class;
      $sel = fn(string $name, array $opts, string $cur) => '<select name="' . e($name) . '" id="' . e($name) . '" class="custom-select" data-locale>'
          . implode('', array_map(fn($k, $l) => '<option value="' . e((string) $k) . '"' . ((string) $k === $cur ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($opts), $opts)) . '</select>';
      $c = $F::cfg();
      $tzNow = (string) ($v['timezone'] ?: \Align\Config::get('timezone', 'America/Los_Angeles'));
      ?>
      <div class="card card-dark" id="currency-dates">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-globe mr-2"></i>Currency &amp; dates</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group col-md-7"><label for="locale_currency">Currency</label>
              <?= $sel('locale_currency', array_combine(array_keys($F::CURRENCIES), array_map(fn($x, $k) => "$k · {$x[1]} · {$x[0]}", $F::CURRENCIES, array_keys($F::CURRENCIES))), $c['currency']) ?></div>
            <div class="form-group col-md-5"><label for="locale_currency_position">Symbol</label>
              <?= $sel('locale_currency_position', ['' => 'Usual', 'before' => 'Before', 'after' => 'After'], (string) ($v['locale_currency_position'] ?? '')) ?></div>
            <div class="form-group col-md-6"><label for="locale_number">Numbers</label><?= $sel('locale_number', array_map(fn($x) => $x[0], $F::NUMBERS), $c['number']) ?></div>
            <div class="form-group col-md-6"><label for="locale_date">Dates</label><?= $sel('locale_date', $F::DATES, $c['date']) ?></div>
            <div class="form-group col-md-6"><label for="locale_time">Time</label><?= $sel('locale_time', ['12' => '12-hour (2:30 pm)', '24' => '24-hour (14:30)'], $c['time']) ?></div>
            <div class="form-group col-md-6"><label for="locale_week_start">Weeks start on</label><?= $sel('locale_week_start', $F::WEEK, $c['week']) ?></div>
          </div>
          <div class="form-group"><label for="timezone">Timezone</label>
            <select name="timezone" id="timezone" class="custom-select">
              <option value=""<?= $v['timezone'] ? '' : ' selected' ?>>As set on the server (<?= e((string) \Align\Config::get('timezone', 'America/Los_Angeles')) ?>)</option>
              <?php foreach ($F::zones() as $region => $zones): ?><optgroup label="<?= e($region) ?>"><?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= $v['timezone'] === $z ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $z)) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
            </select>
            <small class="text-muted">Now <?= e($F::dateTime(time())) ?> (<?= e($tzNow) ?>). Meetings, digests and reminders use it. Times already saved aren't moved (meetings, sign-in history, the email queue), so set it right after installing, before you start scheduling.</small></div>
          <div class="border rounded bg-light px-3 py-2 small" aria-live="polite">Preview: <b data-locale-preview data-currencies="<?= e(json_encode(array_map(fn($x) => [$x[1], $x[2], $x[3]], $F::CURRENCIES), JSON_UNESCAPED_UNICODE)) ?>"><?= e($F::money(1234.5, true) . ' · ' . $F::money(-980) . ' · ' . $F::date(strtotime('2026-09-29 14:30'), 'day') . ' · ' . $F::time(strtotime('2026-09-29 14:30'))) ?></b>
            <div class="text-muted mt-1">Used on every page, report and email, and in the client portal. Amounts aren't converted, and CSV files and the API keep plain numbers and dates.</div></div>
        </div>
      </div>
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

<?php
/**
 * Currency & dates card (Settings → General and the setup wizard). @var array $v saved values: timezone, locale_currency_position
 * Security: options come from Fmt's fixed lists and PHP's timezone list; every value is escaped.
 */
?>
      <?php
      $F = \Align\Fmt::class;
      $sel = fn(string $name, array $opts, string $cur) => '<select name="' . e($name) . '" id="' . e($name) . '" class="form-select" data-locale>'
          . implode('', array_map(fn($k, $l) => '<option value="' . e((string) $k) . '"' . ((string) $k === $cur ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($opts), $opts)) . '</select>';
      $c = $F::cfg();
      $tzNow = (string) ($v['timezone'] ?: \Align\Config::get('timezone', 'America/Los_Angeles'));
      ?>
      <div class="card card-dark" id="currency-dates">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-globe me-2"></i>Currency &amp; dates</h3></div>
        <div class="card-body">
          <div class="row g-2">
            <div class="mb-3 col-md-7"><label for="locale_currency">Currency</label>
              <?= $sel('locale_currency', array_combine(array_keys($F::CURRENCIES), array_map(fn($x, $k) => "$k · {$x[1]} · {$x[0]}", $F::CURRENCIES, array_keys($F::CURRENCIES))), $c['currency']) ?></div>
            <div class="mb-3 col-md-5"><label for="locale_currency_position">Symbol</label>
              <?= $sel('locale_currency_position', ['' => 'Usual', 'before' => 'Before', 'after' => 'After'], (string) ($v['locale_currency_position'] ?? '')) ?></div>
            <div class="mb-3 col-md-6"><label for="locale_number">Numbers</label><?= $sel('locale_number', array_map(fn($x) => $x[0], $F::NUMBERS), $c['number']) ?></div>
            <div class="mb-3 col-md-6"><label for="locale_date">Dates</label><?= $sel('locale_date', $F::DATES, $c['date']) ?></div>
            <div class="mb-3 col-md-6"><label for="locale_time">Time</label><?= $sel('locale_time', ['12' => '12-hour (2:30 pm)', '24' => '24-hour (14:30)'], $c['time']) ?></div>
            <div class="mb-3 col-md-6"><label for="locale_week_start">Weeks start on</label><?= $sel('locale_week_start', $F::WEEK, $c['week']) ?></div>
          </div>
          <div class="mb-3"><label for="timezone">Timezone</label>
            <select name="timezone" id="timezone" class="form-select">
              <option value=""<?= $v['timezone'] ? '' : ' selected' ?>>As set on the server (<?= e((string) \Align\Config::get('timezone', 'America/Los_Angeles')) ?>)</option>
              <?php foreach ($F::zones() as $region => $zones): ?><optgroup label="<?= e($region) ?>"><?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= $v['timezone'] === $z ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $z)) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
            </select>
            <small class="text-muted">Now <?= e($F::dateTime(time())) ?> (<?= e($tzNow) ?>). Meetings, digests and reminders use it. Times already saved aren't moved (meetings, sign-in history, the email queue), so set it right after installing, before you start scheduling.</small></div>
          <div class="border rounded bg-light px-3 py-2 small" aria-live="polite">Preview: <b data-locale-preview data-currencies="<?= e(json_encode(array_map(fn($x) => [$x[1], $x[2], $x[3]], $F::CURRENCIES), JSON_UNESCAPED_UNICODE)) ?>"><?= e($F::money(1234.5, true) . ' · ' . $F::money(-980) . ' · ' . $F::date(strtotime('2026-09-29 14:30'), 'day') . ' · ' . $F::time(strtotime('2026-09-29 14:30'))) ?></b>
            <div class="text-muted mt-1">Used on every page, report and email, and in the client portal. Amounts aren't converted, and CSV files and the API keep plain numbers and dates.</div></div>
        </div>
      </div>

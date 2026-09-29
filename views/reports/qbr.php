<?php
use Align\Reports\Ui;
use Align\View;

/**
 * Business review pack, in the order the meeting runs:
 *   1 Executive summary        where things stand, highlights, what's coming
 *   2 Service levels           how we did (look back)
 *   3 Assets & lifecycle       what you have: servers with hosts and VMs, network, computers
 *   4 Software & licensing     what you have: subscriptions and renewals
 *   5 Backup & recovery        is it protected
 *   6 Compliance               is it secure and compliant
 *   7 Roadmap & projects       where we're going
 *   8 Technology budget        what it costs (this year, three years, dates to act on)
 *   9 Decisions & next steps   what we need from you, who to call, next meeting
 *   A Full inventory           appendix
 * @var array $client, $brand, $quarter, $opt, $provider, $people, $highlights
 * @var ?array $a, $r, $bd, $comp, $lic, $bk, $sla
 * @var callable $on  fn(section key): bool
 */
$costs = (bool) $opt['costs'];
$users = (bool) ($opt['users'] ?? true);
$clientLogo = client_logo_url($client);
$sections = [];
if (!empty($sla)) $sections['sla'] = 'Service levels';
if ($on('s_assets') && $a) $sections['assets'] = 'Assets & lifecycle';
if ($on('s_licensing') && $lic && $costs) $sections['licensing'] = 'Software & licensing'; // budget and licensing are all prices: left out when costs are off
if ($bk) $sections['backup'] = 'Backup & recovery';
if ($on('s_compliance') && $comp && $comp['frameworks']) $sections['compliance'] = 'Compliance';
if ($on('s_roadmap') && $r) $sections['roadmap'] = 'Roadmap & projects';
if ($on('s_budget') && $bd && $costs) $sections['budget'] = 'Technology budget';
$sections['people'] = !empty($r['pending']) ? 'Decisions & next steps' : 'Your team & next steps';
$n = 1;
$numOf = [];
foreach ($sections as $k => $_) {
    $numOf[$k] = sprintf('%02d', ++$n);
}
?>
<!-- Cover -->
<div class="cover">
  <div class="cover-top">
    <?php if (\Align\Branding::hasLogo()): ?><img src="<?= e(\Align\Branding::logoUrl()) ?>" alt="<?= e($brand['company']) ?>"><?php else: ?><b><?= e($brand['company']) ?></b><?php endif; ?>
    <span class="muted"><?= e(\Align\Fmt::date(time(), 'long')) ?></span>
  </div>
  <div class="cover-body">
    <?php if ($clientLogo): ?><img src="<?= e($clientLogo) ?>" alt="" class="cover-client-logo"><?php endif; ?>
    <div class="cover-kicker">Business review · <?= e($quarter['label']) ?></div>
    <h1>Technology Review<br>&amp; Plan</h1>
    <div class="cover-client"><?= e($client['name']) ?></div>
    <div class="cover-bar"></div>
    <div class="cover-meta">
      <div><span>Prepared for</span><?= e($client['contact_name'] ?: $client['name']) ?></div>
      <div><span>Prepared by</span><?= e($brand['preparedBy'] ?: $brand['company']) ?></div>
      <div><span>Period</span><?= e($quarter['label']) ?> (<?= e($quarter['months']) ?>)</div>
    </div>
    <div class="toc">
      <div><span><b>01</b>Executive summary</span></div>
      <?php foreach ($sections as $k => $label): ?><div><span><b><?= $numOf[$k] ?></b><?= e($label) ?></span></div><?php endforeach; ?>
      <?php if (isset($sections['assets']) && $opt['inventory']): ?><div><span><b>A</b>Appendix: full inventory</span></div><?php endif; ?>
    </div>
  </div>
  <div class="cover-foot">
    <span><?= e($brand['company']) ?><?= $brand['phone'] ? ' · ' . e($brand['phone']) : '' ?><?= $brand['email'] ? ' · ' . e($brand['email']) : '' ?></span>
    <span>Confidential · prepared for <?= e($client['name']) ?></span>
  </div>
</div>

<!-- Executive summary -->
<section class="rsection page-break">
  <?= Ui::head('Executive summary', '01', $client['name']) ?>
  <p class="lede">Where your technology stands today, what's coming up, and the decisions we recommend for the next few quarters.</p>
  <div class="kpi-row">
    <?php if ($a): $s = $a['summary']; $hp = $s['total'] ? (int) round($a['healthy'] / $s['total'] * 100) : 0; ?>
      <?= Ui::kpi($hp . '%', 'Devices healthy', $a['healthy'] . ' of ' . $s['total'] . ' within policy', $hp >= 80 ? 'ok' : ($hp >= 50 ? 'warn' : 'bad')) ?>
      <?= Ui::kpi((string) $a['actNow'], 'Need action now', $s['replace'] . ' past end of life · ' . $s['os_eos'] . ' old OS', $a['actNow'] ? 'bad' : 'ok') ?>
    <?php endif; ?>
    <?php if ($bd && $costs): ?><?= Ui::kpi(money($bd['yr']['total']), $bd['yr']['label'] . ' budget', money($bd['b']['runRate']) . '/mo recurring today', 'muted') ?><?php endif; ?>
    <?php if ($comp && $comp['avg'] !== null): ?><?= Ui::kpi($comp['avg'] . '%', 'Compliance', count($comp['frameworks']) . ' framework' . (count($comp['frameworks']) == 1 ? '' : 's'), $comp['avg'] >= 80 ? 'ok' : ($comp['avg'] >= 50 ? 'warn' : 'bad')) ?><?php endif; ?>
    <?php if ($r && (!$bd || !$costs || !$comp || $comp['avg'] === null)): ?><?= Ui::kpi((string) count($r['active']), 'Active projects', count($r['pending']) . ' awaiting a decision', 'muted') ?><?php endif; ?>
  </div>

  <h3>Highlights</h3>
  <div class="callouts">
    <?php foreach (array_slice($highlights, 0, 6) as $h): ?>
      <div class="callout t-<?= e($h['tone']) ?>"><b><?= e($h['title']) ?></b><span><?= e($h['text']) ?></span></div>
    <?php endforeach; ?>
  </div>

  <?php if ($r):
      $next = array_slice($r['plan']['quarters'], $r['currentIndex'], 2);
      $rows = [];
      foreach ($next as $q) {
          foreach ($q['items'] as $it) if ($it['status'] !== 'declined') $rows[] = [$q['label'], 'project', $it['title'] . ($it['status'] === 'proposed' ? ' (proposed)' : ''), (float) $it['cost']];
          if ($q['hardware']) $rows[] = [$q['label'], 'hardware', 'Replace ' . count($q['hardware']) . ' device' . (count($q['hardware']) > 1 ? 's' : ''), $q['hw_cost']];
          foreach ($q['os'] as $g) $rows[] = [$q['label'], 'os', $g['label'] . ' support ends (' . count($g['devices']) . ' devices)', 0];
          if ($q['warranty']) $rows[] = [$q['label'], 'warranty', count($q['warranty']) . ' warrant' . (count($q['warranty']) > 1 ? 'ies' : 'y') . ' end', 0];
      }
      if ($rows): ?>
    <h3>Coming up in the next six months</h3>
    <table class="rtable compact">
      <thead><tr><th>When</th><th>What</th><?php if ($costs): ?><th class="num">Est. cost</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach (array_slice($rows, 0, 8) as [$when, $kind, $text, $cost]): ?>
        <tr><td class="nowrap" style="width:14%"><?= e($when) ?></td><td><span class="tl-item" style="margin:0"><span class="dot dot-<?= $kind ?>"></span><span class="tl-t"><?= e($text) ?></span></span></td><?php if ($costs): ?><td class="num"><?= $cost ? money($cost) : '—' ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; endif; ?>

</section>

<?php if (isset($sections['sla'])): ?>
<div class="page-break"></div>
<?= View::fetch('reports/sections/sla', ['s' => $sla, 'num' => $numOf['sla'], 'missed' => !empty($opt['missed'])]) ?>
<?php endif; ?>

<?php if (isset($sections['assets'])): ?>
<div class="page-break"></div>
<?= View::fetch('reports/sections/assets_overview', ['a' => $a, 'costs' => $costs, 'users' => $users, 'num' => $numOf['assets']]) ?>
<?= View::fetch('reports/sections/assets_plan', ['a' => $a, 'costs' => $costs, 'planChart' => !isset($sections['roadmap'])]) ?>
<?= View::fetch('reports/sections/assets_attention', ['a' => $a, 'costs' => $costs, 'users' => $users, 'limit' => 12, 'moreNote' => $opt['inventory'] ? 'Every device is listed in the inventory at the end.' : 'Ask us for the full asset report.']) ?>
<?php endif; ?>

<?php if (isset($sections['licensing'])): ?>
<div class="page-break"></div>
<?= View::fetch('reports/sections/licensing', ['l' => $lic, 'num' => $numOf['licensing']]) ?>
<?php endif; ?>

<?php if (isset($sections['backup'])): ?>
<div class="page-break"></div>
<?= View::fetch('reports/sections/backup', ['b' => $bk, 'num' => $numOf['backup'], 'details' => true, 'machines' => true, 'limit' => 15]) ?>
<?php endif; ?>

<?php if (isset($sections['compliance'])): ?>
<div class="page-break"></div>
<?= View::fetch('reports/sections/compliance', ['c' => $comp, 'num' => $numOf['compliance']]) ?>
<?php endif; ?>

<?php if (isset($sections['roadmap'])): ?>
<div class="page-break"></div>
<?= View::fetch('reports/sections/roadmap_overview', ['r' => $r, 'costs' => $costs, 'num' => $numOf['roadmap']]) ?>
<?= View::fetch('reports/sections/roadmap_timeline', ['r' => $r, 'costs' => $costs]) ?>
<?= View::fetch('reports/sections/roadmap_projects', ['r' => $r, 'costs' => $costs, 'notes' => (bool) $opt['notes']]) ?>
<?php endif; ?>

<?php if (isset($sections['budget'])): ?>
<div class="page-break"></div>
<?= View::fetch('reports/sections/budget_overview', ['bd' => $bd, 'num' => $numOf['budget']]) ?>
<?= View::fetch('reports/sections/budget_outlook', ['bd' => $bd, 'notes' => (bool) $opt['notes']]) ?>
<?= View::fetch('reports/sections/budget_contracts', ['bd' => $bd]) ?>
<?php endif; ?>

<div class="page-break"></div>
<?= View::fetch('reports/sections/people', ['p' => $people, 'provider' => $provider, 'num' => $numOf['people'], 'pending' => $r['pending'] ?? [], 'costs' => $costs, 'notes' => (bool) $opt['notes']]) ?>

<?php if (isset($sections['assets']) && $opt['inventory']): ?>
<?= View::fetch('reports/sections/assets_inventory', ['a' => $a, 'costs' => $costs, 'users' => $users, 'num' => 'A']) ?>
<?php endif; ?>

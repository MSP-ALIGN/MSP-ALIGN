<?php
use Align\Reports\Ui;

/**
 * 2.4.0 the QBR section "Since our last review": a few headline numbers, the highlights, then and now side by side,
 * the projects finished, the devices replaced, added and removed, what ran out, and approved projects that slipped.
 * Client-facing: only the parts the caller allowed are in $ch (the portal leaves out alignment and what its user may
 * not see); money only with $costs. Every name is escaped.
 * @var array $ch (Changes::compare), $client; bool $costs; ?string $num
 */
$b = $ch['base'];
$d = $ch['devices'] ?? null;
$p = $ch['projects'] ?? null;
$sp = $ch['spend'] ?? null;
$al = $ch['alignment'] ?? null;
$co = $ch['compliance'] ?? null;
$li = $ch['licenses'] ?? null;
$bk = $ch['backup'] ?? null;
$tk = $ch['tickets'] ?? null;
// Helpers: a percentage or a dash; a list of names, the first $max and "and N more"
$pct = fn($v) => $v === null ? '—' : (int) $v . '%';
$list = function (array $rows, string $key = 'name', int $max = 8): string {
    $names = array_map(fn($r) => e((string) $r[$key]), array_slice($rows, 0, $max));
    return implode(', ', $names) . (count($rows) > $max ? ' and ' . (count($rows) - $max) . ' more' : '');
};
$noThen = !$b['exact']; // no snapshot: then-figures were worked out from dates

// Headline numbers: the four most telling ones this client has
$kpis = [];
if ($p) $kpis[] = [(string) $p['counts']['done'], 'Projects finished', $costs && $sp && $sp['done_cost'] ? money($sp['done_cost']) . ' invested' : ($p['counts']['started'] ? $p['counts']['started'] . ' under way' : ''), $p['counts']['done'] ? 'ok' : 'muted'];
if ($d) $kpis[] = [(string) $d['counts']['replaced'], 'Devices replaced', $d['counts']['added'] . ' added · ' . max(0, $d['counts']['removed'] - $d['counts']['replaced']) . ' removed', $d['counts']['replaced'] ? 'ok' : 'muted'];
if ($al && $al['new_review'] && $al['change'] !== null) $kpis[] = [$pct($al['now']['score']), 'Alignment', 'was ' . $pct($al['then']['score']), $al['change'] >= 0 ? 'ok' : 'warn'];
if ($d) {
    $ran = $d['counts']['became_due'] + $d['counts']['os_ended'] + $d['counts']['warranty_expired'];
    $kpis[] = [(string) $ran, 'Ran out since', $d['counts']['became_due'] . ' end of life · ' . $d['counts']['warranty_expired'] . ' warrant' . ($d['counts']['warranty_expired'] === 1 ? 'y' : 'ies'), $d['counts']['became_due'] + $d['counts']['os_ended'] ? 'bad' : ($ran ? 'warn' : 'ok')];
}
if ($tk) $kpis[] = [(string) $tk['opened'], 'Tickets opened', $tk['closed'] . ' closed', 'muted'];
$kpis = array_slice($kpis, 0, 4);

// Then and now: figures with an earlier value, or computed now
$rows = [];
if ($d) {
    $t = $d['then'];
    $rows[] = ['Devices', $t ? (string) $t['total'] : null, (string) $d['now']['total']];
    $rows[] = ['Past end of life', $t ? (string) $t['replace'] : null, (string) $d['now']['replace']];
    $rows[] = ['Unsupported operating system', $t ? (string) $t['os_eos'] : null, (string) $d['now']['os_eos']];
    $rows[] = ['Out of warranty', $t ? (string) $t['warranty_expired'] : null, (string) $d['now']['warranty_expired']];
}
if ($al) $rows[] = ['Alignment score', $al['then'] ? $pct($al['then']['score']) : null, $pct($al['now']['score'])];
foreach ($co['frameworks'] ?? [] as $f) $rows[] = [$f['name'], $f['then'] !== null ? $pct($f['then']) : null, $pct($f['now'])];
if ($li && $costs) $rows[] = ['Licensing per year', $li['then'] ? money((float) $li['then']['annual']) : null, money((float) $li['now']['annual'])];
if ($bk) $rows[] = ['Backup success (30 days)', $bk['then'] && $bk['then']['rate'] !== null ? $pct($bk['then']['rate']) : null, $pct($bk['now']['rate'])];
$anyThen = (bool) array_filter($rows, fn($r) => $r[1] !== null);
?>
<section class="rsection">
  <?= Ui::head('Since our last review', $num ?? null, (string) $b['label']) ?>
  <p class="lede">What has changed for <?= e($client['name']) ?> since <?= $b['meeting_id'] ? 'our review on ' : '' ?><?= e(fmt_date($b['date'])) ?>: work finished, devices replaced, and what has come due in the <?= (int) $b['days'] ?> days since.</p>
  <?php if ($kpis): ?>
    <div class="kpi-row<?= count($kpis) === 3 ? ' cols-3' : (count($kpis) === 2 ? ' cols-2' : '') ?>">
      <?php foreach ($kpis as [$v, $l, $s, $t]): ?><?= Ui::kpi($v, $l, $s, $t) ?><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php // Highlights: the same lines as the app's What changed card, as callouts ?>
  <?php if ($ch['headline']): ?>
    <div class="callouts">
      <?php foreach (array_slice($ch['headline'], 0, 6) as $h): ?>
        <div class="callout t-<?= e($h['tone']) ?>"><b><?= e($h['title']) ?></b><span><?= e($h['text']) ?></span></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p>Nothing has changed that needs your attention since then.</p>
  <?php endif; ?>

  <?php // Then and now: only when there's something to set side by side ?>
  <?php if ($rows && ($anyThen || count($rows) > 1)): ?>
    <h3>Then and now</h3>
    <table class="rtable compact">
      <thead><tr><th></th><th class="num" style="width:20%"><?= e(fmt_date($b['date'])) ?></th><th class="num" style="width:20%">Today</th></tr></thead>
      <tbody>
      <?php foreach ($rows as [$label, $then, $now]): ?>
        <tr><td class="name"><?= e($label) ?></td><td class="num"><?= $then !== null ? e($then) : '<span class="muted">—</span>' ?></td><td class="num"><b><?= e($now) ?></b></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($noThen): ?><p class="muted">Devices and licenses then are worked out from their dates<?= in_array(null, array_column($rows, 1), true) ? '; — means no earlier figure was kept. From now on the figures are saved at each review' : '' ?>.</p>
    <?php elseif (in_array(null, array_column($rows, 1), true)): ?><p class="muted">— no earlier figure saved for that line.</p><?php endif; ?>
  <?php endif; ?>

  <?php // The detail: projects finished, devices, alignment gaps, slipped projects, tickets ?>
  <?php if ($p && $p['done']): ?>
    <h3>Projects finished</h3>
    <table class="rtable compact">
      <thead><tr><th>Project</th><th style="width:16%">Finished</th><?php if ($costs): ?><th class="num" style="width:16%">Cost</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach (array_slice($p['done'], 0, 12) as $r): ?>
        <tr><td class="name"><?= e($r['title']) ?></td><td class="nowrap"><?= $r['date'] ? e(fmt_date($r['date'])) : '' ?></td><?php if ($costs): ?><td class="num"><?= $r['cost'] ? money($r['cost']) : '—' ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($d && ($d['replaced'] || $d['added'] || $d['removed'] || $d['became_due'] || $d['os_ended'] || $d['warranty_expired'])): ?>
    <h3>Devices</h3>
    <table class="rtable compact">
      <tbody>
      <?php foreach ([['replaced', 'Replaced'], ['added', 'Added'], ['removed', 'Removed'], ['became_due', 'Reached end of life'], ['os_ended', 'Operating system support ended'], ['warranty_expired', 'Warranty ran out']] as [$k, $l]): if (!$d[$k] || ($k === 'removed' && $d['counts']['removed'] === $d['counts']['replaced'])) continue; ?>
        <tr><td class="nowrap" style="width:26%"><b><?= e($l) ?></b> (<?= (int) $d['counts'][$k] ?>)</td><td><?= $list($k === 'removed' ? array_values(array_filter($d['removed'], fn($x) => empty($x['project']))) : $d[$k]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($al && $al['new_review'] && ($al['closed'] || $al['opened'])): ?>
    <h3>Alignment with our standards</h3>
    <table class="rtable compact">
      <tbody>
        <?php if ($al['closed']): ?><tr><td class="nowrap" style="width:26%"><b>Gaps closed</b> (<?= (int) $al['counts']['closed'] ?>)</td><td><?= $list($al['closed'], 'title') ?></td></tr><?php endif; ?>
        <?php if ($al['opened']): ?><tr><td class="nowrap"><b>New gaps</b> (<?= (int) $al['counts']['opened'] ?>)</td><td><?= $list($al['opened'], 'title') ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($sp && $sp['slipped']): ?>
    <h3>Approved but not done yet</h3>
    <table class="rtable compact">
      <thead><tr><th>Project</th><th style="width:16%">Planned for</th><?php if ($costs): ?><th class="num" style="width:16%">Budget</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach (array_slice($sp['slipped'], 0, 10) as $r): ?>
        <tr><td class="name"><?= e($r['title']) ?></td><td><?= e($r['quarter']) ?></td><?php if ($costs): ?><td class="num"><?= $r['cost'] ? money($r['cost']) : '—' ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($tk && $tk['opened'] && $tk['categories']): ?>
    <p class="muted">Tickets since then: <?= (int) $tk['opened'] ?> opened, <?= (int) $tk['closed'] ?> closed. Most common: <?= implode(', ', array_map(fn($c) => e($c['name']) . ' (' . (int) $c['count'] . ')', array_slice($tk['categories'], 0, 3))) ?>.</p>
  <?php endif; ?>
</section>

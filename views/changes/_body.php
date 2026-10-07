<?php
/**
 * 2.4.0 what changed since a business review, as cards: the headline, then and now, projects, devices, alignment,
 * compliance, licenses, tickets. Shared by the staff Since last QBR tab and the client portal page; only the parts
 * the caller put in $ch show. Every name and title is escaped; tones and icons come from fixed lists.
 * @var array $ch (Changes::compare); bool $costs; bool $staff (links to staff pages, alignment); int $cid
 */
$cid = (int) ($cid ?? 0);
$b = $ch['base'];
$d = $ch['devices'] ?? null;
$p = $ch['projects'] ?? null;
$sp = $ch['spend'] ?? null;
$al = $ch['alignment'] ?? null;
$co = $ch['compliance'] ?? null;
$li = $ch['licenses'] ?? null;
$bk = $ch['backup'] ?? null;
$tk = $ch['tickets'] ?? null;
// Headline tones (Changes::headline) to Bootstrap colours and icons
$tone = ['ok' => 'success', 'warn' => 'warning', 'bad' => 'danger', 'muted' => 'secondary'];
$icon = ['ok' => 'fa-circle-check', 'warn' => 'fa-triangle-exclamation', 'bad' => 'fa-circle-exclamation', 'muted' => 'fa-circle-info'];
// A percentage or a dash; a change as a green or red arrow ($upIsGood: is a higher number better?)
$pct = fn($v) => $v === null ? '—' : (int) $v . '%';
$delta = function (?int $c, bool $upIsGood = true): string {
    if ($c === null || $c === 0) {
        return '';
    }
    $good = $upIsGood ? $c > 0 : $c < 0;
    return ' <span class="small text-' . ($good ? 'success' : 'danger') . '"><i class="fas fa-arrow-' . ($c > 0 ? 'up' : 'down') . '"></i> ' . abs($c) . '</span>';
};
$names = function (array $rows, string $key = 'name', int $max = 12): string { // names with their dates, the rest counted
    $out = array_map(fn($r) => e((string) $r[$key]) . (!empty($r['date']) ? ' <span class="text-muted">(' . e(fmt_date($r['date'])) . ')</span>' : ''), array_slice($rows, 0, $max));
    return implode(', ', $out) . (count($rows) > $max ? ' <span class="text-muted">and ' . (count($rows) - $max) . ' more</span>' : '');
};
// Staff get links to the device pages; the portal gets plain names
$devLink = fn(array $r) => $staff ? '<a href="/devices/' . (int) $r['id'] . '">' . e($r['name']) . '</a>' : e($r['name']);
?>
<div class="row g-3">
  <?php // What changed: the headline lines, most useful first ?>
  <div class="col-xl-7 ch-minw">
    <div class="card card-dark h-100">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check me-2"></i>What changed</h3>
        <div class="card-tools small text-muted"><?= (int) $b['days'] ?> days</div></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($ch['headline'] as $h): ?>
          <li class="list-group-item d-flex gap-2"><i class="fas fa-fw <?= $icon[$h['tone']] ?? 'fa-circle-info' ?> text-<?= $tone[$h['tone']] ?? 'secondary' ?> mt-1"></i>
            <div class="ch-minw"><div class="fw-semibold"><?= e($h['title']) ?></div><div class="small text-muted"><?= e($h['text']) ?></div></div></li>
        <?php endforeach; ?>
        <?php if (!$ch['headline']): ?><li class="list-group-item text-muted">Nothing that needs attention has changed since then.</li><?php endif; ?>
      </ul>
    </div>
  </div>
  <?php // Then and now: one row per figure; [label, then, now, higher is better (null: neither), kind (true count, false %, 'money')] ?>
  <div class="col-xl-5 ch-minw">
    <div class="card card-dark h-100">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-scale-balanced me-2"></i>Then and now</h3></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th></th><th class="text-end"><?= e(fmt_date($b['date'])) ?></th><th class="text-end">Today</th></tr></thead>
          <tbody>
          <?php $rows = [];
          if ($d) {
              $t = $d['then'];
              $rows[] = ['Devices', $t ? (int) $t['total'] : null, (int) $d['now']['total'], null, true];
              $rows[] = ['Past end of life', $t ? (int) $t['replace'] : null, (int) $d['now']['replace'], false, true];
              $rows[] = ['Unsupported OS', $t ? (int) $t['os_eos'] : null, (int) $d['now']['os_eos'], false, true];
              $rows[] = ['Out of warranty', $t ? (int) $t['warranty_expired'] : null, (int) $d['now']['warranty_expired'], false, true];
          }
          if ($al && $staff) $rows[] = ['Alignment', $al['then']['score'] ?? null, $al['now']['score'], true, false];
          foreach ($co['frameworks'] ?? [] as $f) $rows[] = [$f['name'], $f['then'], $f['now'], true, false];
          if ($bk) $rows[] = ['Backup success', $bk['then']['rate'] ?? null, $bk['now']['rate'], true, false];
          if ($li) $rows[] = ['Licenses', $li['then']['count'] ?? null, (int) $li['now']['count'], null, true];
          if ($li && $costs) $rows[] = ['Licensing / year', isset($li['then']['annual']) ? (float) $li['then']['annual'] : null, (float) $li['now']['annual'], null, 'money'];
          foreach ($rows as [$label, $then, $now, $upGood, $kind]):
              $fmt = fn($v) => $v === null ? '<span class="text-muted">—</span>' : ($kind === 'money' ? e(money((float) $v)) : ($kind === true ? (int) $v : $pct($v)));
              $chg = $then !== null && $now !== null && $upGood !== null && $kind !== 'money' ? (int) round($now - $then) : null; ?>
            <tr><td><?= e($label) ?></td><td class="text-end text-muted"><?= $fmt($then) ?></td><td class="text-end fw-semibold"><?= $fmt($now) ?><?= $delta($chg, (bool) $upGood) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!$b['exact']): ?><div class="card-footer small text-muted"><?= $staff ? 'No figures were saved ' . ($b['meeting_id'] ? 'at this review (from 2.3.0 they are saved when a review is marked completed)' : 'for a date') . ': devices' : 'Devices' ?> and licenses then are worked out from their dates; — means no earlier figure was kept.</div><?php endif; ?>
    </div>
  </div>

  <?php // Projects: finished (with cost and total), then started, approved, added, declined and slipped ?>
  <?php if ($p): ?>
  <div class="col-lg-6 ch-minw">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-road me-2 text-secondary"></i>Projects</h3>
        <div class="card-tools small text-muted"><?= (int) $p['counts']['done'] ?> finished · <?= (int) $p['counts']['added'] ?> added<?= $p['counts']['waiting'] ? ' · ' . (int) $p['counts']['waiting'] . ' waiting for a decision' : '' ?></div></div>
      <?php if ($p['done']): ?>
        <table class="table table-sm mb-0">
          <thead><tr><th>Finished</th><th class="text-nowrap">Date</th><?php if ($costs): ?><th class="text-end">Cost</th><?php endif; ?></tr></thead>
          <tbody><?php foreach ($p['done'] as $r): ?>
            <tr><td><?= e($r['title']) ?></td><td class="text-nowrap"><?= $r['date'] ? e(fmt_date($r['date'])) : '' ?></td><?php if ($costs): ?><td class="text-end"><?= $r['cost'] ? e(money($r['cost'])) : '—' ?></td><?php endif; ?></tr>
          <?php endforeach; ?></tbody>
          <?php if ($costs && $sp && $sp['done_cost']): ?><tfoot><tr><th colspan="2">Total</th><th class="text-end"><?= e(money($sp['done_cost'])) ?><?= $sp['done_monthly'] ? '<div class="small text-muted fw-normal">+' . e(money($sp['done_monthly'])) . '/mo</div>' : '' ?></th></tr></tfoot><?php endif; ?>
        </table>
      <?php endif; ?>
      <div class="card-body small">
        <?php if (!$p['done']): ?><p class="text-muted">No projects finished since then.</p><?php endif; ?>
        <?php foreach ([['started', 'Started'], ['approved', 'Approved'], ['added', 'Added'], ['declined', 'Declined']] as [$k, $l]): if (!$p[$k]) continue; ?>
          <div class="mb-1"><b><?= e($l) ?> (<?= (int) $p['counts'][$k] ?>):</b> <?= $names($p[$k], 'title') ?></div>
        <?php endforeach; ?>
        <?php if ($sp && $sp['slipped']): ?>
          <div class="mt-2 text-warning-emphasis"><i class="fas fa-triangle-exclamation me-1"></i><b>Approved but not done on time (<?= count($sp['slipped']) ?>):</b>
            <?= implode(', ', array_map(fn($r) => e($r['title']) . ' <span class="text-muted">(' . e($r['quarter']) . ')</span>', $sp['slipped'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php // Devices: replaced and what ran out first; "removed" leaves out the replaced ones already listed ?>
  <?php if ($d): ?>
  <div class="col-lg-6 ch-minw">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-desktop me-2 text-secondary"></i>Devices</h3>
        <div class="card-tools small text-muted"><?= (int) $d['counts']['replaced'] ?> replaced · <?= (int) $d['counts']['added'] ?> added · <?= max(0, (int) $d['counts']['removed'] - (int) $d['counts']['replaced']) ?> removed</div></div>
      <div class="card-body small">
        <?php $any = false; foreach ([['replaced', 'Replaced', 'success'], ['became_due', 'Reached end of life', 'danger'], ['os_ended', 'OS support ended', 'danger'], ['warranty_expired', 'Warranty ran out', 'warning'], ['added', 'Added', 'secondary'], ['removed', 'Removed', 'secondary']] as [$k, $l, $t]):
            $rows = $k === 'removed' ? array_values(array_filter($d['removed'], fn($x) => empty($x['project']))) : $d[$k];
            if (!$rows) continue; $any = true; ?>
          <div class="mb-2"><span class="badge text-bg-<?= $t ?> me-1"><?= count($rows) ?></span><b><?= e($l) ?>:</b>
            <?= implode(', ', array_map(fn($r) => $devLink($r) . (!empty($r['project']) ? ' <span class="text-muted">(' . e($r['project']) . ')</span>' : (!empty($r['date']) ? ' <span class="text-muted">(' . e(fmt_date($r['date'])) . ')</span>' : '')), array_slice($rows, 0, 15))) ?>
            <?= count($rows) > 15 ? '<span class="text-muted">and ' . (count($rows) - 15) . ' more</span>' : '' ?></div>
        <?php endforeach; ?>
        <?php if (!$any): ?><p class="text-muted mb-0">No device changes since then.</p><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php // Alignment: staff only (the portal never gets this part) ?>
  <?php if ($al && $staff): ?>
  <div class="col-lg-6 ch-minw">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-bullseye me-2 text-secondary"></i>Alignment</h3>
        <div class="card-tools small"><a href="/clients/<?= $cid ?>/alignment">Open</a></div></div>
      <div class="card-body small">
        <?php if (!$al['new_review']): ?>
          <p class="mb-0 text-muted">No review finished since then. The last one (<?= e(fmt_date($al['now']['finished_at'])) ?>) scored <?= $pct($al['now']['score']) ?>.</p>
        <?php else: ?>
          <p><?= $al['then'] ? 'From <b>' . $pct($al['then']['score']) . '</b> (' . e(fmt_date($al['then']['finished_at'])) . ') to ' : 'First review: ' ?><b><?= $pct($al['now']['score']) ?></b> (<?= e(fmt_date($al['now']['finished_at'])) ?>)<?= $delta($al['change']) ?>.</p>
          <?php if ($al['closed']): ?><div class="mb-1"><b class="text-success">Gaps closed (<?= (int) $al['counts']['closed'] ?>):</b> <?= $names($al['closed'], 'title') ?></div><?php endif; ?>
          <?php if ($al['opened']): ?><div><b class="text-danger">New gaps (<?= (int) $al['counts']['opened'] ?>):</b> <?= $names($al['opened'], 'title') ?></div><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php // Compliance, licenses and tickets: the smaller parts share one card ?>
  <?php if ($co || $li || $tk): ?>
  <div class="col-lg-6 ch-minw">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-check me-2 text-secondary"></i>Compliance, licenses and tickets</h3></div>
      <div class="card-body small">
        <?php foreach ($co['frameworks'] ?? [] as $f): ?>
          <div class="mb-1"><b><?= e($f['name']) ?>:</b> <?= $pct($f['now']) ?><?= $f['then'] !== null ? ' (was ' . $pct($f['then']) . ')' : '' ?><?= $f['updated'] ? ' · ' . (int) $f['updated'] . ' control' . ($f['updated'] === 1 ? '' : 's') . ' updated, ' . (int) $f['updated_met'] . ' now met' : ' · no controls updated' ?></div>
        <?php endforeach; ?>
        <?php if ($li): ?>
          <?php if ($li['added']): ?><div class="mb-1"><b>Licenses added (<?= count($li['added']) ?>):</b> <?= $names($li['added']) ?></div><?php endif; ?>
          <?php if ($li['retired']): ?><div class="mb-1"><b>Licenses retired (<?= count($li['retired']) ?>):</b> <?= $names($li['retired']) ?></div><?php endif; ?>
          <?php if (!$li['added'] && !$li['retired']): ?><div class="mb-1 text-muted">No licenses added or retired.</div><?php endif; ?>
        <?php endif; ?>
        <?php if ($tk): ?>
          <div><b>Tickets:</b> <?= (int) $tk['opened'] ?> opened, <?= (int) $tk['closed'] ?> closed<?= $tk['still_open'] ? ', ' . (int) $tk['still_open'] . ' of the new ones still open' : '' ?>
            <?= $tk['categories'] ? '<div class="text-muted">Most common: ' . implode(', ', array_map(fn($c) => e($c['name']) . ' (' . (int) $c['count'] . ')', $tk['categories'])) . '</div>' : '' ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

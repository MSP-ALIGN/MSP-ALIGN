<?php
use Align\Backup\Backup;
use Align\Reports\Ui;

/** @var array $rows [client, b], $shown, $unlinked; array $opt */
$n = count($rows);
$sum = fn(callable $f) => array_sum(array_map($f, $rows));
$failedClients = count(array_filter($rows, fn($r) => $r['b']['stats']['failed']));
$overdueClients = count(array_filter($rows, fn($r) => $r['b']['stats']['overdue'] || $r['b']['stats']['m365_overdue']));
$unprot = $sum(fn($r) => $r['b']['stats']['unprotected']);
$runs = $sum(fn($r) => $r['b']['stats']['runs']);
$okRuns = $sum(fn($r) => $r['b']['stats']['runs'] - $r['b']['stats']['run_failed']);
$rate = $runs ? (int) floor($okRuns / $runs * 100) : null;
$label = ['ok' => ['Healthy', 'ok'], 'warn' => ['Attention', 'warn'], 'bad' => ['Action needed', 'bad']];
?>
<section class="rsection">
  <?= Ui::head('Backups at a glance', null, $n . ' clients') ?>
  <div class="kpi-row cols-5">
    <?= Ui::kpi((string) $n, 'Clients on ' . \Align\Providers\Providers::backupNames(), count($unlinked) . ' not linked', 'muted') ?>
    <?= Ui::kpi($rate === null ? '—' : $rate . '%', 'Backup success', $runs . ' runs in 30 days', $rate === null ? 'muted' : ($rate >= 95 ? 'ok' : ($rate >= 80 ? 'warn' : 'bad'))) ?>
    <?= Ui::kpi((string) $failedClients, 'With failed jobs', $sum(fn($r) => $r['b']['stats']['failed']) . ' jobs failed last run', $failedClients ? 'bad' : 'ok') ?>
    <?= Ui::kpi((string) $overdueClients, 'With overdue items', 'no restore point in ' . Backup::staleHours() . ' hrs', $overdueClients ? 'warn' : 'ok') ?>
    <?= Ui::kpi((string) $unprot, 'Servers without backup', 'across all clients', $unprot ? 'bad' : 'ok') ?>
  </div>
</section>

<section class="rsection">
  <?= Ui::head('Clients', null, $opt['all'] ? 'Problems first' : 'Only clients needing attention') ?>
  <?php if (!$shown): ?><p class="muted">Every client's backups are healthy.</p><?php else: ?>
  <table class="rtable fixed compact dense">
    <?= Ui::cols(['client' => 24, 'status' => 13, 'rate' => 9, 'jobs' => 12, 'machines' => 11, 'm365' => 11, 'unprot' => 9, 'cloud' => 11]) ?>
    <thead><tr><th>Client</th><th class="status">Health</th><th class="num">Success 30d</th><th class="num">Jobs</th><th class="num">Machines current</th><th class="num">M365 users current</th><th class="num">Servers w/o backup</th><th class="num">Cloud storage</th></tr></thead>
    <tbody>
    <?php foreach ($shown as ['client' => $c, 'b' => $b]): $s = $b['stats']; $mu = $b['m365']['types']['user'] ?? null; [$ll, $lt] = $label[$s['tone']] ?? ['—', 'muted']; ?>
      <tr>
        <td><span class="name"><?= e($c['name']) ?></span><?= $b['exemptions'] ? '<div class="sub">' . count($b['exemptions']) . ' marked not required</div>' : '' ?></td>
        <td class="status"><?= Ui::pill($ll, $lt) ?></td>
        <td class="num"><?= $s['rate'] === null ? '—' : $s['rate'] . '%' ?></td>
        <td class="num"><?= (int) $s['jobs'] ?><?php if ($s['failed'] || $s['warning']): ?><div class="sub" style="color:var(--<?= $s['failed'] ? 'bad' : 'warn' ?>)"><?= e(implode(' · ', array_filter([$s['failed'] ? $s['failed'] . ' failed' : null, $s['warning'] ? $s['warning'] . ' warn' : null]))) ?></div><?php endif; ?></td>
        <td class="num"><?= $s['protected'] ? $s['ok'] . '/' . $s['protected'] : '—' ?></td>
        <td class="num"><?= $mu ? $mu['ok'] . '/' . $mu['total'] : '—' ?></td>
        <td class="num" style="<?= $s['unprotected'] ? 'color:var(--bad);font-weight:700' : '' ?>"><?= $s['unprotected'] ?: '—' ?></td>
        <td class="num"><?= $s['cloud_quota'] ? e(fmt_bytes($s['cloud_used'])) . '<div class="sub">' . (int) $s['cloud_pct'] . '% of ' . e(fmt_bytes($s['cloud_quota'])) . '</div>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<?php $problems = array_filter($shown, fn($r) => $r['b']['stats']['tone'] !== 'ok'); if ($problems): ?>
<section class="rsection">
  <?= Ui::head('What needs attention', null, count($problems) . ' clients') ?>
  <table class="rtable fixed compact">
    <?= Ui::cols(['client' => 24, 'issue' => 76]) ?>
    <thead><tr><th>Client</th><th>Issues</th></tr></thead>
    <tbody>
    <?php foreach ($problems as ['client' => $c, 'b' => $b]):
        $items = [];
        foreach ($b['jobs'] as $j) {
            if ($j['is_enabled'] && ($j['status_counted'] ?? true) && in_array($j['status'], ['failed', 'warning'], true)) {
                $items[] = [$j['status'] === 'failed' ? 'bad' : 'warn', 'Job "' . $j['name'] . '" ' . ($j['status'] === 'failed' ? 'failed' : 'warning') . ($j['failure_message'] ? ': ' . mb_strimwidth($j['failure_message'], 0, 110, '…') : '')];
            }
        }
        if ($b['unprotected']) {
            $names = array_column($b['unprotected'], 'name');
            $items[] = ['bad', count($names) . ' server' . (count($names) === 1 ? '' : 's') . ' with no backup: ' . implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? ' and ' . (count($names) - 8) . ' more' : '')];
        }
        $over = array_filter($b['workloads'], fn($w) => $w['tone'] !== 'ok');
        if ($over) {
            $items[] = ['warn', count($over) . ' machine' . (count($over) === 1 ? '' : 's') . ' overdue: ' . implode(', ', array_slice(array_column($over, 'name'), 0, 8)) . (count($over) > 8 ? '…' : '')];
        }
        if (!empty($b['m365']['overdue'])) {
            $mo = $b['m365']['overdue'];
            $items[] = ['warn', count($mo) . ' Microsoft 365 item' . (count($mo) === 1 ? '' : 's') . ' overdue: ' . implode(', ', array_slice(array_column($mo, 'name'), 0, 8)) . (count($mo) > 8 ? '…' : '')];
        }
    ?>
      <tr><td><span class="name"><?= e($c['name']) ?></span></td>
        <td><?php foreach ($items as [$tone, $text]): ?><div><span class="bk-dot <?= e($tone) ?>"></span><?= e($text) ?></div><?php endforeach; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php if ($unlinked): ?><p class="footnote">Not linked to a<?= preg_match('/^[AEIOU]/i', $bn = \Align\Providers\Providers::backupNames()) ? 'n' : '' ?> <?= e($bn) ?> company: <?= e(implode(', ', $unlinked)) ?>. Link them on Client mapping if <?= e($bn) ?> protects them. Internal: not for client distribution.</p><?php endif; ?>

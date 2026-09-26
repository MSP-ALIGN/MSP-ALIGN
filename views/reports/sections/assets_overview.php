<?php
use Align\Reports\Ui;

/** @var array $a ReportData::assets(); bool $costs; ?string $num */
$s = $a['summary'];
$total = max(1, $s['total']);
$healthyPct = (int) round($a['healthy'] / $total * 100);
?>
<section class="rsection">
  <?= Ui::head('Fleet at a glance', $num ?? null, $s['total'] . ' devices') ?>
  <div class="kpi-row cols-5">
    <?= Ui::kpi((string) $s['total'], 'Devices', ($a['withUser'] ? $a['withUser'] . ' with a known user' : 'tracked')) ?>
    <?= Ui::kpi($healthyPct . '%', 'Healthy', $a['healthy'] . ' within policy', $healthyPct >= 80 ? 'ok' : ($healthyPct >= 50 ? 'warn' : 'bad')) ?>
    <?= Ui::kpi((string) $s['replace'], 'Past end of life', $costs && $s['overdue_cost'] ? money($s['overdue_cost']) . ' to replace' : 'replace now', $s['replace'] ? 'bad' : 'ok') ?>
    <?= Ui::kpi((string) $s['os_eos'], 'Unsupported OS', 'no security updates', $s['os_eos'] ? 'bad' : 'ok') ?>
    <?= Ui::kpi((string) $s['warranty_expired'], 'Out of warranty', $s['warranty_soon'] . ' more expiring soon', $s['warranty_expired'] ? 'warn' : 'ok') ?>
  </div>
  <div class="two-col">
    <div>
      <h3>By device type</h3>
      <table class="rtable compact">
        <thead><tr><th>Type</th><th class="num">Devices</th><th style="width:34%">Health</th><th class="num">Avg age</th><?php if ($costs): ?><th class="num">Value</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($a['classes'] as $c): ?>
          <tr><td class="name"><?= e($c['label']) ?></td><td class="num"><?= (int) $c['count'] ?></td>
            <td><?= Ui::hbar([[$c['ok'], 'b-ok'], [$c['warn'], 'b-warn'], [$c['bad'], 'b-bad']]) ?></td>
            <td class="num"><?= $c['avg_age'] !== null ? e($c['avg_age']) . ' yr' : '—' ?></td>
            <?php if ($costs): ?><td class="num"><?= $c['value'] ? money($c['value']) : '—' ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($costs && $a['fleetValue']): ?><tfoot><tr><td colspan="4">Estimated replacement value</td><td class="num"><?= money($a['fleetValue']) ?></td></tr></tfoot><?php endif; ?>
      </table>
      <div class="legend"><span><i style="background:#3fb67a"></i>Healthy</span><span><i style="background:#f0b429"></i>Plan / warranty</span><span><i style="background:#e5534b"></i>Replace / unsupported</span></div>
    </div>
    <div>
      <h3>Operating systems</h3>
      <table class="rtable compact">
        <thead><tr><th>Operating system</th><th class="num">Devices</th><th>Support ends</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($a['os'], 0, 10) as $o): ?>
          <tr><td class="name"><?= e($o['label']) ?></td><td class="num"><?= (int) $o['count'] ?></td><td class="nowrap"><?= $o['eos'] ? e(fmt_date($o['eos'])) : '<span class="muted">—</span>' ?></td>
            <td class="num"><?= $o['state'] === 'bad' ? Ui::pill('Unsupported', 'bad') : ($o['state'] === 'warn' ? Ui::pill('Ending soon', 'warn') : Ui::pill('Supported', 'ok')) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$a['os']): ?><tr><td colspan="4" class="muted">No operating system data.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <?php if (count($a['os']) > 10): ?><p class="footnote"><?= count($a['os']) - 10 ?> more operating systems in the inventory.</p><?php endif; ?>
    </div>
  </div>
</section>

<?php
use Align\Reports\Ui;

/** @var array $bd; ?string $num */
$dates = $bd['dates'];
$kindTone = ['renegotiate' => 'warn', 'contract_end' => 'bad', 'expires' => 'info'];
?>
<section class="rsection avoid-break">
  <?= Ui::head('Contracts & renewals', $num ?? null, $bd['yr']['label']) ?>
  <?php if (!$dates): ?><p class="muted">No contract end, renegotiation or renewal dates in <?= e($bd['yr']['label']) ?>.</p><?php else: ?>
  <table class="rtable">
    <thead><tr><th>Date</th><th>What</th><th>Item</th><th>Term</th><th>Renewal</th><th class="num">Annual value</th></tr></thead>
    <tbody>
    <?php foreach ($dates as $d): ?>
      <tr><td class="nowrap strong"><?= e(fmt_date($d['date'])) ?></td><td><?= Ui::pill($d['label'], $kindTone[$d['kind']] ?? 'muted') ?></td><td class="name"><?= e($d['name']) ?></td>
        <td><?= e($d['term'] ?: '—') ?></td><td><?= $d['kind'] === 'renegotiate' ? '<span class="muted">—</span>' : ($d['auto_renew'] ? 'Auto-renews' : '<b>Not renewing</b>') ?></td>
        <td class="num"><?= $d['annual'] ? money($d['annual']) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="footnote">Renegotiate by = the last day to give notice or renegotiate before the contract renews.</p>
  <?php endif; ?>
</section>

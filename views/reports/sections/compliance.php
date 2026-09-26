<?php
use Align\Reports\Ui;

/** @var array $c ReportData::compliance(); ?string $num */
$st = ['not_met' => ['Not met', 'bad'], 'partial' => ['Partial', 'warn']];
?>
<section class="rsection">
  <?= Ui::head('Compliance', $num ?? null, $c['avg'] !== null ? $c['avg'] . '% average' : '') ?>
  <?php if (!$c['frameworks']): ?><p class="muted">No compliance frameworks are assigned yet.</p><?php else: ?>
  <table class="rtable">
    <thead><tr><th>Framework</th><th class="num">Score</th><th style="width:26%">Progress</th><th class="num">Met</th><th class="num">Partial</th><th class="num">Not met</th><th class="num">Assessed</th></tr></thead>
    <tbody>
    <?php foreach ($c['frameworks'] as $f): $s = $f['score']; ?>
      <tr><td class="name"><?= e($f['name']) ?></td>
        <td class="num"><span class="score-ring" style="color:<?= $s['score'] >= 80 ? 'var(--ok)' : ($s['score'] >= 50 ? 'var(--warn)' : 'var(--bad)') ?>"><?= (int) $s['score'] ?>%</span></td>
        <td><?= Ui::hbar([[$s['met'], 'b-ok'], [$s['partial'], 'b-warn'], [$s['not_met'], 'b-bad'], [$s['not_assessed'], 'b-muted']], (float) max(1, $s['applicable'])) ?></td>
        <td class="num"><?= (int) $s['met'] ?></td><td class="num"><?= (int) $s['partial'] ?></td><td class="num"><?= (int) $s['not_met'] ?></td><td class="num"><?= (int) $s['assessed'] ?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="legend"><span><i style="background:#3fb67a"></i>Met</span><span><i style="background:#f0b429"></i>Partial</span><span><i style="background:#e5534b"></i>Not met</span><span><i style="background:#c3cad3"></i>Not assessed</span><span class="muted">Score counts partial items as half.</span></div>
  <?php if ($c['open']): ?>
    <h3>Open items</h3>
    <table class="rtable compact">
      <thead><tr><th>Ref</th><th>Item</th><th>Framework</th><th>Status</th><th>Owner</th><th>Due</th></tr></thead>
      <tbody>
      <?php foreach ($c['open'] as $o): [$sl, $tone] = $st[$o['status']] ?? ['Open', 'muted']; ?>
        <tr><td class="nowrap muted"><?= e($o['ref']) ?></td><td class="name"><?= e($o['title']) ?></td><td><?= e($o['framework']) ?></td><td><?= Ui::pill($sl, $tone) ?></td><td><?= e($o['owner'] ?: '—') ?></td>
          <td class="nowrap <?= $o['due_date'] && $o['due_date'] < date('Y-m-d') ? 'strong' : '' ?>" style="<?= $o['due_date'] && $o['due_date'] < date('Y-m-d') ? 'color:var(--bad)' : '' ?>"><?= $o['due_date'] ? e(fmt_date($o['due_date'])) : '—' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php endif; ?>
</section>

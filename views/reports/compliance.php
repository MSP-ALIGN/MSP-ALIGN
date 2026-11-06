<?php
use Align\Compliance\Compliance;
use Align\Reports\Ui;

/**
 * 2.33.0 Compliance report (ReportController::renderCompliance): a cover, the summary (score, counts and a score per
 * section), the open items with owner and due date (overdue ones highlighted), the full checklist by section and a
 * sign-off for the client and the provider.
 * @var array $d ComplianceReport::data(); array $client, $brand; array $opt (open, and for staff notes and guidance);
 *   bool $portal (the portal's version: the data holds no notes, evidence, guidance or review dates, and only shared
 *   documents' titles)
 * Client-facing: everything shown is this one client's (the data is read by its id) and every value is escaped.
 */
$fw = $d['fw'];
$s = $d['score'];
$notes = !$portal && !empty($opt['notes']);
$guidance = !$portal && !empty($opt['guidance']);
$openOnly = !empty($opt['open']);
$clientLogo = client_logo_url($client);
$pill = function (string $status): string {
    [$label, $tone] = Compliance::STATUSES[$status] ?? Compliance::STATUSES['not_assessed'];
    return Ui::pill($label, $tone);
};
$scoreColor = fn(int $v) => $v >= 80 ? 'var(--ok)' : ($v >= 50 ? 'var(--warn)' : 'var(--bad)');
$bar = fn(array $c) => Ui::hbar([[$c['met'], 'b-ok'], [$c['partial'], 'b-warn'], [$c['not_met'], 'b-bad'], [$c['not_assessed'], 'b-muted']], (float) max(1, $c['applicable']));
$parts = ['Summary', 'Open items'];
if (!$openOnly) $parts[] = 'Full checklist';
$parts[] = 'Sign-off';
?>
<!-- Cover -->
<div class="cover">
  <div class="cover-top">
    <?php if (\Align\Branding::anyLogo()): ?><img src="<?= e(\Align\Branding::lightLogoUrl()) ?>" alt="<?= e($brand['company']) ?>"><?php else: ?><b><?= e($brand['company']) ?></b><?php endif; ?>
    <span class="muted"><?= e(\Align\Fmt::date(time(), 'long')) ?></span>
  </div>
  <div class="cover-body">
    <?php if ($clientLogo): ?><img src="<?= e($clientLogo) ?>" alt="" class="cover-client-logo"><?php endif; ?>
    <div class="cover-kicker">Compliance report</div>
    <h1><?= e($fw['name']) ?></h1>
    <div class="cover-client"><?= e($client['name']) ?></div>
    <div class="cover-bar"></div>
    <div class="cover-meta">
      <div><span>Prepared for</span><?= e($client['name']) ?></div>
      <div><span>Prepared by</span><?= e($brand['company']) ?></div>
      <div><span>Date</span><?= e(\Align\Fmt::date(time(), 'long')) ?></div>
      <?php if (!$portal): // the portal's framework page doesn't show the review dates ?>
        <div><span>Last reviewed</span><?= e(fmt_date($fw['last_reviewed'])) ?></div>
        <div><span>Next review</span><?= e(fmt_date($fw['next_review'])) ?></div>
      <?php endif; ?>
    </div>
    <div class="toc">
      <?php foreach ($parts as $i => $label): ?><div><span><b><?= sprintf('%02d', $i + 1) ?></b><?= e($label) ?></span></div><?php endforeach; ?>
    </div>
  </div>
  <div class="cover-foot">
    <span><?= e($brand['company']) ?><?= $brand['phone'] ? ' · ' . e($brand['phone']) : '' ?><?= $brand['email'] ? ' · ' . e($brand['email']) : '' ?></span>
    <span>Confidential · prepared for <?= e($client['name']) ?></span>
  </div>
</div>

<!-- Summary -->
<section class="rsection">
  <?= Ui::head('Summary', '01', $s['assessed'] . '% assessed') ?>
  <?php if ($fw['description']): ?><p class="lede"><?= e($fw['description']) ?></p><?php endif; ?>
  <div class="kpi-row comp-kpis">
    <?= Ui::kpi($s['score'] . '%', 'Score', 'partial counts as half', $s['score'] >= 80 ? 'ok' : ($s['score'] >= 50 ? 'warn' : 'bad')) ?>
    <?= Ui::kpi((string) $s['met'], 'Met', '', 'ok') ?>
    <?= Ui::kpi((string) $s['partial'], 'Partial', '', $s['partial'] ? 'warn' : '') ?>
    <?= Ui::kpi((string) $s['not_met'], 'Not met', '', $s['not_met'] ? 'bad' : '') ?>
    <?= Ui::kpi((string) $s['na'], 'N/A', '', 'muted') ?>
    <?= Ui::kpi((string) $s['not_assessed'], 'Not assessed', '', 'muted') ?>
  </div>
  <?php if (!$s['total']): ?>
    <p class="muted">This framework has no controls yet.</p>
  <?php else: ?>
  <div class="comp-total-bar"><?= $bar($s) ?></div>
  <div class="legend"><span><i style="background:#3fb67a"></i>Met</span><span><i style="background:#f0b429"></i>Partial</span><span><i style="background:#e5534b"></i>Not met</span><span><i style="background:#c3cad3"></i>Not assessed</span><span class="muted">N/A items are left out of the score.</span></div>
  <h3>By section</h3>
  <table class="rtable fixed compact">
    <?= Ui::cols(['section' => 30, 'score' => 8, 'bar' => 25, 'met' => 7, 'partial' => 9, 'not' => 9, 'open' => 12]) ?>
    <thead><tr><th>Section</th><th class="num">Score</th><th>Progress</th><th class="num">Met</th><th class="num">Partial</th><th class="num">Not met</th><th class="num">Not assessed</th></tr></thead>
    <tbody>
    <?php foreach ($d['sectionScores'] as $name => $c): ?>
      <tr><td class="name"><?= e((string) $name) ?></td>
        <td class="num"><?php if ($c['applicable'] > 0): ?><span class="score-ring" style="color:<?= $scoreColor((int) $c['score']) ?>"><?= (int) $c['score'] ?>%</span><?php else: ?><span class="muted">N/A</span><?php endif; ?></td>
        <td><?= $bar($c) ?></td>
        <td class="num"><?= (int) $c['met'] ?></td><td class="num"><?= (int) $c['partial'] ?></td><td class="num"><?= (int) $c['not_met'] ?></td><td class="num"><?= (int) $c['not_assessed'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<!-- Open items -->
<section class="rsection">
  <?= Ui::head('Open items', '02', count($d['open']) . ' open' . ($d['overdue'] ? ' · ' . $d['overdue'] . ' overdue' : '')) ?>
  <?php if (!$d['open']): ?>
    <p class="muted">Nothing is open: every control is met or not applicable.</p>
  <?php else: ?>
  <table class="rtable fixed compact comp-open">
    <?= Ui::cols(['ref' => 9, 'item' => 43, 'status' => 13, 'owner' => 19, 'due' => 16]) ?>
    <thead><tr><th>Ref</th><th>Control</th><th>Status</th><th>Owner</th><th>Due</th></tr></thead>
    <tbody>
    <?php foreach ($d['open'] as $o): ?>
      <tr class="<?= $o['overdue'] ? 'overdue-row' : '' ?>"><td class="nowrap muted"><?= e((string) $o['ref']) ?></td>
        <td><span class="name"><?= e((string) $o['title']) ?></span><div class="sub"><?= e($o['section_name']) ?></div></td>
        <td class="status"><?= $pill($o['status']) ?></td>
        <td><?= $o['owner'] ? e((string) $o['owner']) : '<span class="muted">—</span>' ?></td>
        <td class="nowrap<?= $o['overdue'] ? ' overdue' : '' ?>"><?= $o['due_date'] ? e(fmt_date($o['due_date'])) : '<span class="muted">—</span>' ?><?= $o['overdue'] ? '<div class="sub">Overdue</div>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<?php if (!$openOnly && $d['sections']): ?>
<!-- Full checklist -->
<div class="page-break"></div>
<section class="rsection">
  <?= Ui::head('Full checklist', '03', $s['total'] . ' ' . ($s['total'] === 1 ? 'control' : 'controls')) ?>
  <?php foreach ($d['sections'] as $name => $controls): ?>
    <h3><?= e((string) $name) ?></h3>
    <table class="rtable fixed compact dense comp-list">
      <?= Ui::cols(['ref' => 8, 'title' => $portal ? 62 : 34, 'status' => 12, 'notes' => $notes ? 22 : null, 'evidence' => $portal ? 18 : 24]) ?>
      <thead><tr><th>Ref</th><th>Control</th><th>Status</th><?php if ($notes): ?><th>Notes</th><?php endif; ?><th><?= $portal ? 'Document' : 'Evidence' ?></th></tr></thead>
      <tbody>
      <?php foreach ($controls as $c): ?>
        <tr><td class="nowrap muted"><?= e((string) $c['ref']) ?></td>
          <td><span class="name"><?= e((string) $c['title']) ?></span><?php if ($guidance && trim((string) $c['guidance']) !== ''): ?><div class="sub comp-guidance"><?= e((string) $c['guidance']) ?></div><?php endif; ?></td>
          <td class="status"><?= $pill($c['status']) ?></td>
          <?php if ($notes): ?><td class="comp-text"><?= trim((string) $c['notes']) !== '' ? e((string) $c['notes']) : '<span class="muted">—</span>' ?></td><?php endif; ?>
          <td class="comp-text"><?php
            $ev = $portal ? '' : trim((string) $c['evidence']);
            $doc = trim((string) $c['doc_title']);
            if ($ev === '' && $doc === '') echo '<span class="muted">—</span>';
            if ($ev !== '') echo e($ev);
            if ($doc !== '') echo '<div class="' . ($ev !== '' ? 'sub ' : '') . 'comp-doc"><i class="fas fa-file-lines me-1"></i>' . e($doc) . (!$portal && ($c['doc_status'] ?? 'active') !== 'active' ? ' (' . e(mb_strtolower(\Align\Docs\Documents::STATUSES[$c['doc_status']][0] ?? (string) $c['doc_status'])) . ')' : '') /* (2.33.0) draft or archived */ . '</div>';
          ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- Sign-off -->
<section class="rsection avoid-break">
  <?= Ui::head('Sign-off', sprintf('%02d', count($parts))) ?>
  <p class="lede">We have reviewed this <?= e($fw['name']) ?> assessment and agree it reflects where <?= e($client['name']) ?> stands on <?= e(\Align\Fmt::date(time(), 'long')) ?>.</p>
  <table class="rtable fixed review-sign comp-sign">
    <?= Ui::cols(['for' => 28, 'name' => 26, 'sign' => 28, 'date' => 18]) ?>
    <thead><tr><th>For</th><th>Name</th><th>Signature</th><th>Date</th></tr></thead>
    <tbody>
      <tr><td class="name"><?= e($client['name']) ?></td><td></td><td></td><td></td></tr>
      <tr><td class="name"><?= e($brand['company']) ?></td><td></td><td></td><td></td></tr>
    </tbody>
  </table>
</section>

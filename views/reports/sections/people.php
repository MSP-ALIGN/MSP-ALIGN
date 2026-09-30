<?php
use Align\Contacts\Contacts;
use Align\Meetings\Meetings;
use Align\Reports\Ui;

/**
 * Closing section of the review: decisions we need, then who's who and the next meeting.
 * @var array $p ReportData::people(); array $provider; ?string $num; ?array $pending (projects awaiting a decision); ?bool $costs; ?bool $notes
 */
$m = $p['nextMeeting'];
$pending ??= [];
$costs ??= true;
?>
<section class="rsection">
  <?= Ui::head($pending ? 'Decisions & next steps' : 'Your team & next steps', $num ?? null) ?>
  <?php if ($pending): ?>
    <h3>Decisions needed</h3>
    <p class="muted small-note" style="margin-top:-.2rem">Approve, decline or reschedule each one. Approved projects move into the roadmap and budget.</p>
    <table class="rtable">
      <thead><tr><th>Project</th><th>When</th><th>Priority</th><?php if ($costs): ?><th class="num">One-time</th><th class="num">Monthly</th><?php endif; ?><th style="width:14%">Decision</th></tr></thead>
      <tbody>
      <?php foreach ($pending as $d): ?>
        <tr><td><span class="name"><?= e($d['title']) ?></span><?php if (!empty($notes) && $d['description']): ?><div class="sub"><?= e(mb_strimwidth($d['description'], 0, 180, '…')) ?></div><?php endif; ?></td>
          <td class="nowrap"><?= e($d['when']) ?></td><td><?= Ui::pill(\Align\Roadmap\Roadmap::PRIORITIES[$d['priority']][0], ['critical' => 'bad', 'high' => 'warn', 'medium' => 'info', 'low' => 'muted'][$d['priority']] ?? 'muted') ?></td>
          <?php if ($costs): ?><td class="num"><?= (float) $d['cost'] ? money($d['cost']) : '—' ?></td><td class="num"><?= (float) $d['recurring_monthly'] ? money($d['recurring_monthly']) : '—' ?></td><?php endif; ?>
          <td class="decide"><span class="check-box"></span> Yes <span class="check-box"></span> No</td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <div class="avoid-break">
  <div class="two-col">
    <?php if (empty($p['hidden'])): ?>
    <div class="panel">
      <h3>Key contacts</h3>
      <?php if (!$p['contacts']): ?><p class="muted">No key contacts recorded.</p><?php else: ?>
      <table class="rtable compact" style="margin:0">
        <tbody>
        <?php foreach (array_slice($p['contacts'], 0, 8) as $k): ?>
          <tr><td><span class="name"><?= e($k['name']) ?></span><div class="sub"><?= e(implode(' · ', array_filter([$k['title'], $k['email'], Contacts::phone($k)]))) ?></div></td>
            <td class="roles"><?php foreach (Contacts::ROLES as $col => [$label]): if (!empty($k[$col]) && in_array($col, ['is_primary', 'decision_maker', 'qbr', 'is_billing'], true)): ?><span class="pill pill-muted" style="margin-left:2px"><?= e($label) ?></span><?php endif; endforeach; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="panel">
      <h3>Your IT team</h3>
      <dl class="kv">
        <dt>Provider</dt><dd><?= e($provider['company']) ?></dd>
        <?php if (!empty($provider['vcio'])): ?><dt>Advisor</dt><dd><?= e($provider['vcio']) ?></dd><?php endif; ?>
        <?php if (!empty($provider['phone'])): ?><dt>Phone</dt><dd><?= e($provider['phone']) ?></dd><?php endif; ?>
        <?php if (!empty($provider['email'])): ?><dt>Email</dt><dd><?= e($provider['email']) ?></dd><?php endif; ?>
      </dl>
      <?php if (empty($p['hidden'])): ?>
      <h3 style="margin-top:.8rem">Next meeting</h3>
      <?php if ($m): ?>
        <p style="margin:0"><b><?= e(\Align\Fmt::date($m['starts_at'], 'dayfull')) ?></b> at <?= e(fmt_time($m['starts_at'])) ?></p>
        <p class="muted" style="margin:0"><?= e($m['title']) ?> · <?= e(Meetings::typeLabel($m['type'])) ?><?= $m['location'] ? ' · ' . e($m['location']) : '' ?></p>
      <?php else: ?><p class="muted" style="margin:0">Not scheduled yet.</p><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  </div>
</section>

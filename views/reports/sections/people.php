<?php
use Align\Contacts\Contacts;
use Align\Meetings\Meetings;
use Align\Reports\Ui;

/** @var array $p ReportData::people(); array $provider; ?string $num */
$m = $p['nextMeeting'];
?>
<section class="rsection avoid-break">
  <?= Ui::head('Your team & next steps', $num ?? null) ?>
  <div class="two-col">
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
    <div class="panel">
      <h3>Your IT team</h3>
      <dl class="kv">
        <dt>Provider</dt><dd><?= e($provider['company']) ?></dd>
        <?php if (!empty($provider['vcio'])): ?><dt>Advisor</dt><dd><?= e($provider['vcio']) ?></dd><?php endif; ?>
        <?php if (!empty($provider['phone'])): ?><dt>Phone</dt><dd><?= e($provider['phone']) ?></dd><?php endif; ?>
        <?php if (!empty($provider['email'])): ?><dt>Email</dt><dd><?= e($provider['email']) ?></dd><?php endif; ?>
      </dl>
      <h3 style="margin-top:.8rem">Next meeting</h3>
      <?php if ($m): ?>
        <p style="margin:0"><b><?= e(date('l, F j, Y', strtotime($m['starts_at']))) ?></b> at <?= e(fmt_time($m['starts_at'])) ?></p>
        <p class="muted" style="margin:0"><?= e($m['title']) ?> · <?= e(Meetings::typeLabel($m['type'])) ?><?= $m['location'] ? ' · ' . e($m['location']) : '' ?></p>
      <?php else: ?><p class="muted" style="margin:0">Not scheduled yet.</p><?php endif; ?>
    </div>
  </div>
</section>

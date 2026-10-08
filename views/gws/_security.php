<?php
/**
 * 2.6.3 The Google Workspace security card on a client's overview: each check (pass, fail, unknown with why), from the
 * last sync. Shown only for a client whose Google Workspace is connected.
 * @var array $client; array $sec Google\Security::stored() result (checks, at), [] when none in the last KEEP_HOURS hours
 * Security: the details are counts and fixed text built by Google\Security (no names from the domain); escaped anyway.
 */
use Align\Google\Security;

$cid = (int) $client['id'];
$icon = ['pass' => 'fa-circle-check text-success', 'fail' => 'fa-circle-xmark text-danger', 'unknown' => 'fa-circle-question text-secondary'];
$score = \Align\M365\Security::score($sec); // the same share of known checks passing
?>
<div class="card card-dark" id="gws-security">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fab fa-fw fa-google me-2"></i>Google Workspace security</h3>
    <?php if (\Align\Auth::can('tech')): // the connection's page is for techs and admins ?><div class="card-tools"><a href="/clients/<?= $cid ?>/connectors#gws" class="btn btn-tool">Connection</a></div><?php endif; ?>
  </div>
  <div class="card-body">
    <div class="small text-muted text-end mb-2"><?= $score !== null ? 'Checks passing: ' . (int) $score . '%' : 'No check could be read yet' ?> · <?= !empty($sec['at']) ? 'Checked ' . e(rel_time($sec['at'])) : 'Not checked in the last two days' ?></div>
    <ul class="list-unstyled small mb-0">
      <?php foreach (Security::CHECKS as $k => $label): $c = $sec['checks'][$k] ?? ['status' => 'unknown', 'detail' => 'Not checked yet.']; ?>
        <li class="mb-1"><i class="fas fa-fw <?= $icon[$c['status']] ?? $icon['unknown'] ?> me-1"></i><b><?= e($label) ?>:</b> <span class="text-muted"><?= e($c['detail']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="small text-muted mt-2 mb-0">Controls and standards linked to these checks get a suggested answer in Compliance and Alignment; you decide. Unknown checks don't count against the health score.</p>
  </div>
</div>

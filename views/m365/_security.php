<?php
/**
 * 2.6.1 The Microsoft 365 security card on a client's overview: Secure Score and each check (pass, fail, unknown with
 * why), from the last sync, with a prompt to approve the app's new permissions when the tenant hasn't yet. Shown only
 * for a client whose Microsoft 365 is connected.
 * @var array $client; array $sec M365\Security::stored() result (secure, checks, consent, at), [] when none in the last
 *      KEEP_HOURS hours; array $m365 the client's client_m365 row (which app it uses)
 * Security: the details are counts and fixed text built by M365\Security (no names from the tenant); escaped anyway.
 */
use Align\M365\Security;

$cid = (int) $client['id'];
$icon = ['pass' => 'fa-circle-check text-success', 'fail' => 'fa-circle-xmark text-danger', 'unknown' => 'fa-circle-question text-secondary'];
$score = Security::score($sec);
?>
<div class="card card-dark" id="m365-security">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-shield-halved me-2"></i>Microsoft 365 security</h3>
    <?php if (\Align\Auth::can('tech')): // the connection's page is for techs and admins ?><div class="card-tools"><a href="/clients/<?= $cid ?>/connectors#m365" class="btn btn-tool">Connection</a></div><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!empty($sec['consent'])): // some checks need permissions the tenant hasn't approved yet ?>
      <div class="alert alert-warning py-2 small"><i class="fas fa-key me-1"></i>Some checks need permissions the tenant hasn't granted.
        <?php if (!\Align\Auth::can('tech')): // viewers: nothing for them to do ?>A technician approves them on the client's Connectors page.
        <?php elseif (($m365['mode'] ?? '') === 'own'): // the client's own app: its permissions are added in their tenant ?>Add the read-only permissions listed on the <a href="/clients/<?= $cid ?>/connectors#m365">Connectors page</a> to the client's own app and grant admin consent.
        <?php elseif (\Align\M365\App::mode() === 'manual'): // your own registration: add them there first ?>Add the new permissions to your app registration (see <a href="/integrations/microsoft-365">Integrations → Microsoft 365 (clients)</a>), then use <b>Approve new permissions</b> on the <a href="/clients/<?= $cid ?>/connectors#m365">Connectors page</a>.
        <?php else: ?>Use <b>Approve new permissions</b> (or the link) on the <a href="/clients/<?= $cid ?>/connectors#m365">Connectors page</a>: the client's admin approves once more.<?php endif; ?></div>
    <?php endif; ?>
    <?php // Secure Score and how many checks pass ?>
    <div class="d-flex align-items-center flex-wrap gap-3 mb-2">
      <?php if (!empty($sec['secure'])): $pct = (int) round($sec['secure']['current'] / max(0.1, $sec['secure']['max']) * 100); ?>
        <div class="score-ring text-<?= $pct >= Security::SCORE_PASS ? 'success' : ($pct >= 40 ? 'warning' : 'danger') ?>"><b><?= $pct ?>%</b></div>
        <div><b>Secure Score</b><div class="small text-muted"><?= e(round($sec['secure']['current'])) ?> of <?= e(round($sec['secure']['max'])) ?> points (Microsoft's rating)</div></div>
      <?php endif; ?>
      <div class="ms-auto small text-muted text-end"><?= $score !== null ? 'Checks passing: ' . (int) $score . '%' : 'No check could be read yet' ?><br><?= !empty($sec['at']) ? 'Checked ' . e(rel_time($sec['at'])) : 'Not checked in the last two days' ?></div>
    </div>
    <?php // Each check ?>
    <ul class="list-unstyled small mb-0">
      <?php foreach (Security::CHECKS as $k => $label): $c = $sec['checks'][$k] ?? ['status' => 'unknown', 'detail' => 'Not checked yet.']; ?>
        <li class="mb-1"><i class="fas fa-fw <?= $icon[$c['status']] ?? $icon['unknown'] ?> me-1"></i><b><?= e($label) ?>:</b> <span class="text-muted"><?= e($c['detail']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="small text-muted mt-2 mb-0">Controls and standards linked to these checks get a suggested answer in Compliance and Alignment; you decide. Unknown checks don't count against the health score.</p>
  </div>
</div>

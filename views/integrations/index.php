<?php
/**
 * The Integrations page (admin only): one card per connector.
 * @var array<string, \Align\Integrations\Connector[]> $groups; ?array $lastRun; int $unmapped
 * Security: card text, including a status detail that can hold a remote error, is escaped; card links are the
 * connectors' own internal paths.
 */
$runTone = ['success' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'running' => 'info'];
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h3 mb-0 me-auto"><i class="fas fa-plug text-secondary me-2"></i>Integrations</h1>
  <a class="btn btn-sm btn-default me-2" href="/mapping"><i class="fas fa-link me-1"></i>Client mapping<?= $unmapped ? ' <span class="badge text-bg-warning">' . $unmapped . '</span>' : '' ?></a>
  <a class="btn btn-sm btn-default me-2" href="/help#guide-integration" title="How to connect an integration"><i class="fas fa-circle-question"></i></a>
  <a class="btn btn-sm btn-default" href="/sync"><i class="fas fa-rotate me-1"></i>Sync<?= $lastRun ? ' <span class="badge text-bg-' . ($runTone[$lastRun['status']] ?? 'secondary') . '">' . e(rel_time($lastRun['finished_at'] ?? $lastRun['started_at'])) . '</span>' : '' ?></a>
</div>
<p class="text-muted small">Everything Align connects to. API keys are encrypted on this server and never shown again after saving. The sync runs every hour<?= psa_on() ? ' (' . e(psa_name()) . ' asset changes every 2 minutes)' : '' ?>; results show on each card.</p>

<div class="row">
<?php foreach ($groups as $cat => $list): foreach ($list as $c): [$tone, $label, $detail] = $c->status(); ?>
  <div class="col-md-6 col-xl-4 d-flex">
    <a href="<?= e($c->url()) ?>" class="card card-integration flex-fill text-reset text-decoration-none">
      <div class="card-body">
        <div class="small text-uppercase text-muted fw-bold mb-2" style="letter-spacing:.04em"><?= e($cat) ?></div>
        <div class="d-flex align-items-start mb-2">
          <span class="integration-icon me-3"><i class="<?= e($c->icon()) ?>"></i></span>
          <div class="me-auto"><div class="fw-bold"><?= e($c->name()) ?></div><div class="small text-muted"><?= e($c->direction()) ?></div></div>
          <span class="badge text-bg-<?= e($tone) ?> px-2 py-1"><?= e($label) ?></span>
        </div>
        <p class="small mb-2"><?= e($c->summary()) ?></p>
        <?php if ($detail !== ''): ?><p class="small mb-0 <?= $tone === 'danger' ? 'text-danger' : 'text-muted' ?>"><?= e($detail) ?></p><?php endif; ?>
      </div>
      <div class="card-footer bg-transparent small py-2 text-primary"><?= $c->configured() ? 'Settings' : 'Set up' ?> <i class="fas fa-arrow-right ms-1"></i></div>
    </a>
  </div>
<?php endforeach; endforeach; ?>
</div>

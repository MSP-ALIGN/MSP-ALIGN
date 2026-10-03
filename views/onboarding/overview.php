<?php
use Align\Onboarding\Onboarding;

/** Onboarding → New clients. @var array $rows, $signed, $leads, $clients; string $show */
?>
<?= \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-mountain-sun', 'title' => 'New clients',
    'desc' => 'Every client being onboarded: who has the welcome email, who opened it and how far they\'ve got. Clients who signed a contract but haven\'t had the welcome email yet are listed first.',
    'primary' => '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-start-onb"><i class="fas fa-paper-plane me-1"></i>Start onboarding</button>',
    'help' => 'guide-onboarding',
]) ?>
<?php if ($signed || $leads): ?>
  <div class="card card-success card-outline">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-file-signature me-2"></i>Signed, ready to onboard</h3></div>
    <ul class="list-group list-group-flush">
      <?php foreach ($leads as $l): ?>
        <li class="list-group-item d-flex align-items-center">
          <div class="me-auto"><b><?= e($l['lead_company']) ?></b> <span class="badge text-bg-light border">not a client yet</span>
            <div class="small text-muted"><?= e((string) $l['signer_name']) ?> signed <a href="/contracts/<?= (int) $l['id'] ?>"><?= e($l['title']) ?></a> <?= e(rel_time($l['completed_at'])) ?></div></div>
          <form method="post" action="/contracts/<?= (int) $l['id'] ?>/client"><?= csrf_field() ?><button class="btn btn-sm btn-success" data-confirm="Add <?= e($l['lead_company']) ?> as a client in Align, with <?= e((string) $l['signer_name']) ?> as the main contact? If a client with exactly this name is already in Align, the contract is linked to it instead." data-confirm-danger="0" data-confirm-ok="Add the client"><i class="fas fa-building me-1"></i>Add as a client</button></form>
        </li>
      <?php endforeach; ?>
      <?php foreach ($signed as $s): ?>
        <li class="list-group-item d-flex align-items-center">
          <div class="me-auto"><a href="/clients/<?= (int) $s['client_id'] ?>" class="fw-bold"><?= e($s['client_name']) ?></a>
            <div class="small text-muted">Signed <a href="/contracts/<?= (int) $s['id'] ?>"><?= e($s['title']) ?></a> <?= e(rel_time($s['completed_at'])) ?></div></div>
          <a class="btn btn-sm btn-primary" href="/clients/<?= (int) $s['client_id'] ?>/onboarding">Send the welcome email</a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<div class="card">
  <div class="card-header list-toolbar d-flex align-items-center">
    <ul class="nav nav-pills me-auto">
      <li class="nav-item"><a class="nav-link py-1<?= $show === 'open' ? ' active' : '' ?>" href="/onboarding">In progress and recent</a></li>
      <li class="nav-item"><a class="nav-link py-1<?= $show === 'all' ? ' active' : '' ?>" href="/onboarding?show=all">All</a></li>
    </ul>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-striped table-borderless table-hover mb-0">
      <thead><tr><th>Client</th><th>Status</th><th>Progress</th><th>Sent</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $o): [$label, $tone] = Onboarding::STATUS_LABELS[Onboarding::status($o)]; [$done, $total] = Onboarding::progress($o); ?>
        <tr>
          <td><a href="/clients/<?= (int) $o['client_id'] ?>/onboarding" class="fw-bold"><?= e($o['client_name']) ?></a></td>
          <td><span class="badge text-bg-<?= $tone ?>"><?= e($label) ?></span></td>
          <td style="min-width:140px"><div class="progress" style="height:8px" role="progressbar" aria-label="Steps done" aria-valuenow="<?= $done ?>" aria-valuemin="0" aria-valuemax="<?= $total ?>"><div class="progress-bar bg-success" style="width:<?= round($done / max(1, $total) * 100) ?>%"></div></div>
            <div class="small text-muted"><?= $done ?> of <?= $total ?> steps</div></td>
          <td class="small"><?= $o['sent_at'] ? e(fmt_date($o['sent_at'])) . ($o['sent_by_name'] ? '<div class="text-muted">' . e($o['sent_by_name']) . '</div>' : '') : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="4" class="text-muted p-3">No onboardings <?= $show === 'open' ? 'in progress' : 'yet' ?>.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal fade" id="modal-start-onb" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header bg-dark"><h5 class="modal-title">Start onboarding</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <form method="get" action="/onboarding">
      <div class="modal-body"><label for="so-client">Client</label>
        <select id="so-client" name="client" class="form-select" required><option value="">Choose…</option>
          <?php foreach ($clients as $cl): ?><option value="<?= (int) $cl['id'] ?>"><?= e($cl['name']) ?></option><?php endforeach; ?></select>
        <div class="form-text">Opens the client's Onboarding page, where you send the welcome email.</div></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Open</button></div>
    </form>
  </div></div>
</div>

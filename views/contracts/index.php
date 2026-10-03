<?php
use Align\Auth;
use Align\Contracts\Contracts;

/** Onboarding → Contracts. @var array $rows, $counts, $templates, $clients; string $show; int $waitingOnYou */
$fmtParty = fn($r) => $r['client_id'] ? '<a href="/clients/' . (int) $r['client_id'] . '">' . e($r['client_name']) . '</a>'
    : e($r['lead_company'] ?: '—') . ' <span class="badge text-bg-light border" title="Not a client in Align yet">new</span>';
?>
<?= \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-file-signature', 'title' => 'Contracts',
    'desc' => 'Fill in a contract from your template, send it to sign online, and keep the signed copy. Upload contracts signed elsewhere to keep them all in one place.',
    'primary' => $templates ? '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-contract" data-autoopen="add"><i class="fas fa-plus me-1"></i>New contract</button>'
        : (Auth::can('admin') ? '<a class="btn btn-sm btn-primary" href="/contracts/templates"><i class="fas fa-shapes me-1"></i>Make a contract template</a>' : ''),
    'secondary' => ['<button class="btn btn-sm btn-default me-1" data-bs-toggle="modal" data-bs-target="#modal-upload-contract"><i class="fas fa-upload me-1"></i>Upload signed contract</button>'],
    'more' => ['<a class="dropdown-item" href="/contracts/verify"><i class="fas fa-fw fa-fingerprint me-1"></i>Check a signed PDF</a>'],
    'help' => 'guide-contracts',
]) ?>
<?php if ($waitingOnYou): ?>
  <div class="alert alert-warning py-2"><i class="fas fa-pen-nib me-2"></i><b><?= $waitingOnYou ?></b> signed by the client and waiting for your countersignature. <a href="/contracts?show=open" class="alert-link">Show them</a></div>
<?php endif; ?>
<?php if (!$templates && !$counts['all']): ?>
  <div class="card card-body text-center py-5">
    <i class="fas fa-file-signature fa-3x text-secondary mb-3"></i>
    <h2 class="h5">No contract templates yet</h2>
    <p class="text-muted mb-3">Upload your contract as a PDF (or write one in Align), then put the boxes on it for the details, prices and signatures.</p>
    <?php if (Auth::can('admin')): ?><div><a class="btn btn-primary" href="/contracts/templates">Set up your first template</a></div><?php else: ?><p class="small text-muted">Ask an admin to set up a contract template.</p><?php endif; ?>
  </div>
<?php endif; ?>
<div class="card">
  <div class="card-header list-toolbar d-flex flex-wrap align-items-center">
    <ul class="nav nav-pills nav-pills-sm me-auto">
      <?php foreach (\Align\Controllers\ContractController::FILTERS as $k => $label): ?>
        <li class="nav-item"><a class="nav-link py-1<?= $show === $k ? ' active' : '' ?>" href="/contracts?show=<?= $k ?>"><?= e($label) ?> <span class="badge <?= $show === $k ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) ($counts[$k] ?? 0) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
    <input type="search" class="form-control form-control-sm list-search my-1" data-filter-table="contract-table" placeholder="Filter…" aria-label="Filter contracts">
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-striped table-borderless table-hover mb-0" id="contract-table">
      <thead><tr><th>Contract</th><th>For</th><th>Status</th><th>Signer</th><th class="text-nowrap">Last activity</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): [$label, $tone] = Contracts::status($r); $last = $r['completed_at'] ?: ($r['client_signed_at'] ?: ($r['viewed_at'] ?: ($r['sent_at'] ?: $r['updated_at']))); ?>
        <tr>
          <td><a href="/contracts/<?= (int) $r['id'] ?>" class="fw-bold"><?= e($r['title']) ?></a>
            <div class="small text-muted"><?= e(Contracts::number($r)) ?><?= $r['template_name'] ? ' · ' . e($r['template_name']) : ($r['source'] === 'uploaded' ? ' · uploaded' : '') ?></div></td>
          <td><?= $fmtParty($r) ?></td>
          <td><span class="badge text-bg-<?= $tone ?>"><?= e($label) ?></span>
            <?php if ($r['ends_on']): ?><div class="small text-muted">Ends <?= e(fmt_date($r['ends_on'])) ?></div><?php endif; ?></td>
          <td class="small"><?= e($r['signer_name'] ?: '—') ?><?= $r['signer_email'] ? '<div class="text-muted">' . e($r['signer_email']) . '</div>' : '' ?></td>
          <td class="small text-nowrap"><?= e(rel_time($last)) ?><div class="text-muted"><?= e($r['created_by_name'] ?: '') ?></div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-muted p-3"><?= $show === 'open' ? 'Nothing waiting for a signature.' : 'No contracts here yet.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?= \Align\View::fetch('contracts/_new_modal', ['templates' => $templates, 'clients' => $clients]) ?>
<?= \Align\View::fetch('contracts/_upload_modal', ['clients' => $clients]) ?>

<?php
use Align\Auth;

require __DIR__ . '/../partials/client_header.php';
$canFacts = Auth::can('tech');
?>
<?php if ($overview): /* 2.41.0 Documents overview: every document the client's frameworks require, and what to do next */
  $todo = \Align\Docs\Overview::todo($overview); ?>
<div class="card card-dark" id="doc-overview">
  <div class="card-header py-2">
    <h2 class="card-title mt-2 h3"><i class="fas fa-fw fa-clipboard-check me-2"></i>Required documents <span class="badge text-bg-<?= $todo ? 'warning' : 'success' ?> ms-1" data-overview-todo><?= $todo ? $todo . ' to do' : 'all up to date' ?></span></h2>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-striped table-borderless table-hover mb-0" data-overview>
      <thead class="text-dark"><tr><th>Document</th><th>Status</th><th>Owner</th><th>Next review</th><th>Next step</th></tr></thead>
      <tbody>
      <?php foreach ($overview as $r): $d = $r['doc']; [$al, $at] = \Align\Docs\Overview::ACTIONS[$r['action']]; ?>
        <tr data-required="<?= e($r['slug']) ?>" data-action="<?= e($r['action']) ?>">
          <td><?php if ($d): ?><a class="fw-bold" href="/documents/<?= (int) $d['id'] ?>"><?= e($d['title']) ?></a><?php else: ?><span class="fw-bold"><?= e($r['name']) ?></span><?php endif; ?>
            <div class="small text-muted" title="<?= e(implode("\n", $r['notes'])) ?>">Required by <?= e(implode(', ', $r['frameworks'])) ?></div></td>
          <td><?php if ($d): [$sl, $st] = \Align\Docs\Documents::STATUSES[$d['status']] ?? [$d['status'], 'secondary']; ?><span class="badge text-bg-<?= $st ?>"><?= e($sl) ?></span>
            <?php if ($d['status'] === 'draft' && $d['blanks']): ?><div class="small text-muted"><?= (int) $d['blanks'] ?> <?= $d['blanks'] === 1 ? 'blank' : 'blanks' ?> left</div><?php endif; ?>
            <?php else: ?><span class="badge text-bg-secondary">Missing</span><?php endif; ?></td>
          <td class="small"><?php if ($d && $canFacts): ?>
              <form method="post" action="/documents/<?= (int) $d['id'] ?>/owner" class="d-flex"><?= csrf_field() ?>
                <select class="form-select form-select-sm" name="owner_id" aria-label="Owner" data-autosubmit style="min-width:9rem">
                  <option value="0">No owner</option>
                  <?php foreach ($owners as $uid => $uname): ?><option value="<?= (int) $uid ?>" <?= (int) ($d['owner_id'] ?? 0) === (int) $uid ? 'selected' : '' ?>><?= e($uname) ?></option><?php endforeach; ?>
                </select><noscript><button class="btn btn-xs btn-default ms-1">Set</button></noscript></form>
            <?php elseif ($d): ?><?= e($d['owner_name'] ?? '—') ?><?php else: ?>—<?php endif; ?></td>
          <td class="small"><?= $d && $d['review_due'] ? e(fmt_date($d['review_due'])) : '—' ?></td>
          <td><?php /* (2.41.0) the badge names the next step; left out when the button below already says it */ ?>
            <?php if ($d || !$canFacts): ?><span class="badge text-bg-<?= $at ?> me-1"><?= e($al) ?></span><?php endif; ?>
            <?php if ($canFacts): ?>
              <?php if (!$d): ?><a class="btn btn-xs btn-primary" href="/clients/<?= (int) $client['id'] ?>/document-answers?template=<?= (int) $r['id'] ?>" data-fill-form>Fill the form</a>
              <?php elseif ($r['action'] === 'update'): ?><form method="post" action="/documents/<?= (int) $d['id'] ?>/fields" class="d-inline"><?= csrf_field() ?><button class="btn btn-xs btn-warning" title="<?= e(implode('; ', array_map(fn($k, $c) => \Align\Docs\Fields::FIELDS[$k][0] . ': ' . $c[0] . ' → ' . $c[1], array_keys($d['stale']), $d['stale']))) ?>">Make the update draft</button></form>
              <?php elseif ($r['action'] === 'blanks' || $r['action'] === 'activate'): ?><a class="btn btn-xs btn-default" href="/documents/<?= (int) $d['id'] ?>">Open</a>
              <?php endif; ?>
              <?php if ($d && $d['status'] === 'active'): /* (2.41.0 review) also with an update waiting */ ?><form method="post" action="/documents/<?= (int) $d['id'] ?>/reviewed" class="d-inline"><?= csrf_field() ?><button class="btn btn-xs btn-default" data-reviewed>Reviewed, no changes</button></form><?php endif; ?>
            <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h1 class="card-title mt-2"><i class="fas fa-fw fa-file-lines me-2"></i>Documents <span class="badge text-bg-light ms-1"><?= count($docs) ?></span></h1>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm me-2 filter-input" data-filter-table="doc-table" placeholder="Filter…">
      <a class="btn btn-sm btn-default me-2" href="?status=<?= $status === 'all' ? '' : 'all' ?>"><?= $status === 'all' ? 'Hide archived' : 'Show archived' ?></a>
      <?php if (Auth::can('tech')): ?><button class="btn btn-sm btn-default me-2" data-bs-toggle="modal" data-bs-target="#modal-upload-doc"><i class="fas fa-upload me-1"></i>Upload a file</button><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-doc" data-autoopen="add"><i class="fas fa-plus me-1"></i>New document</button><?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0 table-responsive">
    <?php $showClient = false; require __DIR__ . '/_table.php'; ?>
  </div>
</div>
<?php /* 2.41.0 Answers for documents (Docs\Fields): one form per client; the 2.39.0 people details are part of it */
$canFacts = Auth::can('tech');
$ansTotal = count($answerValues);
$ansDone = count(array_filter($answerValues, fn($v) => $v['value'] !== ''));
$ansSuggested = count(array_filter($answerValues, fn($v) => $v['suggested'] !== '' && $v['source'] !== 'typed')); ?>
<div class="card card-dark" id="doc-facts">
  <div class="card-header py-2">
    <h2 class="card-title mt-2 h3"><i class="fas fa-fw fa-list-check me-2"></i>Answers for documents <span class="badge text-bg-light ms-1" data-answers-count><?= $ansDone ?> of <?= $ansTotal ?></span></h2>
    <div class="card-tools"><a class="btn btn-sm btn-primary" href="/clients/<?= (int) $client['id'] ?>/document-answers" data-open-answers><i class="fas fa-pen-to-square me-1"></i><?= $canFacts ? 'Open the form' : 'View the answers' ?></a></div>
  </div>
  <div class="card-body">
    <?php if ($factsPending): /* a person changed and Draft documents still name the old value as plain text (before 2.41.0) */ ?>
      <div class="alert alert-info" data-facts-pending>
        <p class="mb-2"><b><?= count($factsPending['drafts']) ?> draft <?= count($factsPending['drafts']) === 1 ? 'document still names' : 'documents still name' ?> the old details</b>
          (<?= e(implode(', ', array_map(fn($c, $k) => \Align\Docs\Facts::FIELDS[$k][0] . ': ' . $c[0] . ' → ' . $c[1], $factsPending['changes'], array_keys($factsPending['changes'])))) ?>):
          <?= e(implode(', ', array_map(fn($d) => $d['title'], $factsPending['drafts']))) ?>.</p>
        <p class="small mb-2">Active and archived documents are never changed: they may have been approved or signed as they are. Make a new version of those when you're ready.</p>
        <form method="post" action="/clients/<?= (int) $client['id'] ?>/document-facts/drafts" class="d-inline"><?= csrf_field() ?><input type="hidden" name="update" value="1"><button class="btn btn-sm btn-primary">Update the drafts</button></form>
        <form method="post" action="/clients/<?= (int) $client['id'] ?>/document-facts/drafts" class="d-inline ms-1"><?= csrf_field() ?><button class="btn btn-sm btn-light">Leave them</button></form>
      </div>
    <?php endif; ?>
    <p class="mb-2">Who fills each role at <?= e($client['name']) ?>, its outside contacts, its tools and practices: answered once, used by every document made from a template. Values Align knows from the client's connections (Microsoft 365, Client mapping, devices) fill in until someone types an answer. When an answer changes, Draft documents update; Active ones show <b>Update available</b>.</p>
    <?php if ($ansSuggested): ?><p class="small mb-2 text-warning-emphasis"><i class="fas fa-wand-magic-sparkles me-1"></i><?= $ansSuggested ?> AI <?= $ansSuggested === 1 ? 'suggestion waits' : 'suggestions wait' ?> to be confirmed in the form.</p><?php endif; ?>
    <div class="row g-2 small">
      <?php foreach (\Align\Docs\Fields::FIELDS as $k => [$label]): if ($answerValues[$k]['value'] === '') continue; ?>
        <div class="col-md-6 col-xl-4"><span class="text-muted"><?= e($label) ?>:</span> <?= e($answerValues[$k]['value']) ?><?= $answerValues[$k]['source'] === 'auto' ? ' <span class="badge text-bg-info">' . e($answerValues[$k]['from']) . '</span>' : '' ?></div>
      <?php endforeach; ?>
      <?php if (!$ansDone): ?><div class="col-12 text-muted">No answers yet.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php if (Auth::can('tech')): ?>
<?= \Align\View::fetch('documents/_new_modal', ['templates' => $templates, 'presetClient' => (int) $client['id']]) ?>
<?= \Align\View::fetch('documents/_upload_modal', ['presetClient' => (int) $client['id']]) // 2.20.0 ?>
<div class="card card-dark" id="contracts">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-signature me-2"></i>Contracts <span class="badge text-bg-light ms-1"><?= count($contracts) ?></span></h3>
    <div class="card-tools d-flex">
      <button class="btn btn-sm btn-default me-2" data-bs-toggle="modal" data-bs-target="#modal-upload-contract"><i class="fas fa-upload me-1"></i>Upload signed contract</button>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-contract"><i class="fas fa-plus me-1"></i>New contract</button>
    </div>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-striped table-borderless table-hover mb-0">
      <thead class="text-dark"><tr><th>Contract</th><th>Status</th><th>Signed</th><th>Ends</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($contracts as $k): [$kl, $kt] = \Align\Contracts\Contracts::status($k); ?>
        <tr><td><a href="/contracts/<?= (int) $k['id'] ?>" class="fw-bold"><?= e($k['title']) ?></a><div class="small text-muted"><?= e($k['number']) ?><?= $k['source'] === 'uploaded' ? ' · uploaded' : '' ?></div></td>
          <td><span class="badge text-bg-<?= $kt ?>"><?= e($kl) ?></span></td>
          <td class="small"><?= $k['signed_on'] ? e(fmt_date($k['signed_on'])) : '—' ?></td>
          <td class="small"><?= $k['ends_on'] ? e(fmt_date($k['ends_on'])) : '—' ?></td>
          <td class="text-end"><?php if (str_starts_with((string) $k['pdf_file'], 'contract-')): ?><a class="btn btn-xs btn-default" href="/contracts/<?= (int) $k['id'] ?>/pdf" target="_blank" rel="noopener"><i class="fas fa-file-pdf me-1"></i>PDF</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$contracts): ?><tr><td colspan="5" class="empty-state">No contracts yet. Make one from a template, or upload one signed elsewhere.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?= \Align\View::fetch('contracts/_new_modal', ['templates' => $contractTemplates, 'clients' => [], 'presetClient' => (int) $client['id']]) ?>
<?= \Align\View::fetch('contracts/_upload_modal', ['clients' => [], 'presetClient' => (int) $client['id'], 'back' => '/clients/' . (int) $client['id'] . '/documents#contracts']) ?>
<?php endif; ?>

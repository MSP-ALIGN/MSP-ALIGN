<?php
/**
 * A client's compliance summary. @var array $client, $assigned (frameworks with score and gaps), $available, $indicators.
 * The add-framework form shows for techs only.
 */
use Align\Auth;
use Align\Compliance\Compliance;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
?>
<div class="row">
  <div class="col-lg-8">
    <?php foreach ($assigned as $fw): $s = $fw['score']; ?>
      <div class="card card-dark">
        <div class="card-header py-2">
          <h3 class="card-title mt-2"><i class="fas fa-fw fa-clipboard-check me-2"></i><?= e($fw['name']) ?></h3>
          <div class="card-tools">
            <a href="/clients/<?= $cid ?>/compliance/<?= (int) $fw['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-list-check me-1"></i>Open checklist</a>
          </div>
        </div>
        <div class="card-body">
          <div class="d-flex align-items-center mb-2">
            <div class="score-ring me-3 text-<?= $s['tone'] ?>"><b><?= $s['score'] ?>%</b></div>
            <div class="flex-grow-1">
              <div class="progress mb-1">
                <?php foreach (['met' => 'success', 'partial' => 'warning', 'not_met' => 'danger', 'na' => 'light', 'not_assessed' => 'secondary'] as $k => $bg): if (!$s[$k]) continue; ?>
                  <div class="progress-bar bg-<?= $bg ?>" style="width: <?= round($s[$k] / max(1, $s['total']) * 100, 2) ?>%" title="<?= e(Compliance::STATUSES[$k][0]) ?>: <?= $s[$k] ?>"></div>
                <?php endforeach; ?>
              </div>
              <div class="small text-muted">
                <span class="text-success"><?= $s['met'] ?> met</span> · <span class="text-warning"><?= $s['partial'] ?> partial</span> · <span class="text-danger"><?= $s['not_met'] ?> not met</span> · <?= $s['na'] ?> N/A · <?= $s['not_assessed'] ?> not assessed
                <span class="float-end">
                  <?= $fw['last_reviewed'] ? 'Reviewed ' . e(fmt_date($fw['last_reviewed'])) : 'Never reviewed' ?>
                  <?php if ($fw['next_review']): ?> · next <?= $fw['next_review'] < date('Y-m-d') ? '<span class="badge text-bg-warning">' . e(fmt_date($fw['next_review'])) . '</span>' : e(fmt_date($fw['next_review'])) ?><?php endif; ?>
                </span>
              </div>
            </div>
          </div>
          <?php if ($fw['gaps']): ?>
            <h6 class="text-uppercase text-muted small mt-3">Open gaps</h6>
            <ul class="list-unstyled mb-0 small">
              <?php foreach ($fw['gaps'] as $g): $st = Compliance::STATUSES[$g['status']]; ?>
                <li class="mb-1"><i class="fas fa-fw <?= $st[2] ?> text-<?= $st[1] ?> me-1"></i><span class="text-muted"><?= e($g['ref']) ?></span> <?= e($g['title']) ?>
                  <?php if ($g['owner'] || $g['due_date']): ?><span class="text-muted">— <?= e($g['owner'] ?? '') ?><?= $g['due_date'] ? ' by ' . e(fmt_date($g['due_date'])) : '' ?></span><?php endif; ?></li>
              <?php endforeach; ?>
            </ul>
          <?php elseif ($s['assessed'] < 100): ?>
            <p class="small text-muted mb-0">No gaps recorded yet. <?= $s['not_assessed'] ?> control(s) still need an answer.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!$assigned): ?>
      <div class="callout callout-info">
        <h5>No frameworks yet</h5>
        <p class="mb-0">Add one below to start tracking. Most clients start with the <b>MSP Security Baseline</b>; add <b>HIPAA</b> for healthcare, dental and vet practices, and <b>Cyber Insurance Readiness</b> before a policy renewal.</p>
      </div>
    <?php endif; ?>

    <?php if (Auth::can('tech') && $available): ?>
      <form method="post" action="/clients/<?= $cid ?>/compliance" class="d-flex flex-wrap align-items-center">
        <?= csrf_field() ?>
        <select name="framework_id" class="form-select form-select-sm me-2">
          <?php foreach ($available as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i>Add framework</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-robot me-2"></i>From device data</h3></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($indicators as $ind): ?>
          <li class="list-group-item py-2">
            <i class="fas fa-fw <?= $ind['unknown'] ? 'fa-circle-question text-secondary' : ($ind['ok'] ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger') ?> me-1"></i>
            <b><?= e($ind['label']) ?></b><div class="small text-muted ms-4"><?= e($ind['text']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="card-footer small text-muted">Shown next to matching controls in each checklist as a suggestion. You still set the answer.</div>
    </div>
  </div>
</div>

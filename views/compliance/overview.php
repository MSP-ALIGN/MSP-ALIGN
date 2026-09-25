<?php
$rows = array_filter($clients, fn($c) => !empty($assigned[$c['id']]));
$unassigned = array_filter($clients, fn($c) => empty($assigned[$c['id']]));
?>
<div class="row">
  <?php foreach ($frameworks as $fw):
      $vals = [];
      foreach ($scores as $cid => $fws) {
          if (isset($fws[$fw['id']])) {
              $vals[] = $fws[$fw['id']]['score'];
          }
      }
      $avg = $vals ? (int) round(array_sum($vals) / count($vals)) : null;
      ?>
    <div class="col-lg-3 col-md-6">
      <div class="info-box">
        <span class="info-box-icon bg-<?= $avg === null ? 'secondary' : ($avg >= 80 ? 'success' : ($avg >= 50 ? 'warning' : 'danger')) ?>"><i class="fas fa-clipboard-check"></i></span>
        <div class="info-box-content">
          <span class="info-box-text"><?= e($fw['name']) ?></span>
          <span class="info-box-number"><?= $avg === null ? '—' : $avg . '%' ?> <small class="text-muted font-weight-normal">avg across <?= (int) $fw['clients'] ?> client<?= $fw['clients'] == 1 ? '' : 's' ?></small></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-table-cells mr-2"></i>Client compliance</h3>
    <div class="card-tools d-flex"><input type="search" class="form-control form-control-sm filter-input" data-filter-table="compliance-table" placeholder="Filter…"></div>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-striped table-borderless table-hover mb-0" id="compliance-table">
      <thead class="text-dark"><tr><th>Client</th><th>Industry</th>
        <?php foreach ($frameworks as $fw): ?><th class="text-center"><?= e($fw['name']) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td><a href="/clients/<?= (int) $c['id'] ?>/compliance" class="font-weight-bold"><?= e($c['name']) ?></a></td>
          <td class="small"><?= e($c['industry'] ?? '') ?></td>
          <?php foreach ($frameworks as $fw): $s = $scores[$c['id']][$fw['id']] ?? null; $a = $assigned[$c['id']][$fw['id']] ?? null; ?>
            <td class="text-center compliance-cell">
              <?php if ($a && $s): ?>
                <a href="/clients/<?= (int) $c['id'] ?>/compliance/<?= (int) $fw['id'] ?>" class="d-block text-dark">
                  <div class="progress progress-xs mb-1"><div class="progress-bar bg-<?= $s['tone'] ?>" style="width: <?= $s['score'] ?>%"></div></div>
                  <span class="small"><b><?= $s['score'] ?>%</b> · <?= $s['assessed'] ?>% assessed</span>
                  <?php if ($a['next_review'] && $a['next_review'] < date('Y-m-d')): ?><span class="badge badge-warning ml-1">review due</span><?php endif; ?>
                </a>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?= 2 + count($frameworks) ?>" class="text-muted p-3">No frameworks assigned yet. Open a client → Compliance to add one.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($unassigned): ?>
    <div class="card-footer small text-muted"><?= count($unassigned) ?> client(s) have no framework:
      <?= implode(', ', array_map(fn($c) => '<a href="/clients/' . (int) $c['id'] . '/compliance">' . e($c['name']) . '</a>', array_slice(array_values($unassigned), 0, 25))) ?><?= count($unassigned) > 25 ? '…' : '' ?>
    </div>
  <?php endif; ?>
</div>

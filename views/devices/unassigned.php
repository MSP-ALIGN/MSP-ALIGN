<?php
use Align\Auth;
use Align\Lifecycle\Lifecycle;

$canEdit = Auth::can('tech');
$twoWay = \Align\Sync\PsaAssetSync::twoWay();
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-circle-question text-warning me-2"></i>Unassigned hardware <span class="badge text-bg-warning align-middle"><?= count($rows) ?></span></h1>
</div>
<p class="text-muted small"><?= e(psa_name()) ?> assets whose type doesn't match an Align category (<?= e(psa_name()) ?>'s "Other", Display, Tablet, custom types…) land here.
  Pick the right type and they move into the correct category for lifecycle, budgets and reports<?= $twoWay ? ', and the ' . psa_name() . ' asset type is updated to match' : '' ?>.</p>

<?php if (!$rows): ?>
  <div class="card card-body text-center text-muted py-5"><i class="fas fa-check-circle fa-2x text-success mb-2"></i>Nothing to categorize. Everything from <?= e(psa_name()) ?> has a type.</div>
<?php else: ?>
<form method="post" action="/devices/bulk-type" id="bulk-type-form"
  data-confirm-rules="<?= e(json_encode([['count' => '[name="ids[]"]:checked', 'title' => 'Change the type of {n} devices?', 'text' => 'They move into the matching category for lifecycle, budgets and reports' . ($twoWay ? ', and their ' . psa_name() . ' asset type is changed to match.' : '.'), 'ok' => 'Apply']])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="/devices/unassigned">
  <div class="card card-dark">
    <div class="card-header py-2">
      <h3 class="card-title mt-1">Needs a category</h3>
      <?php if ($canEdit): ?>
      <div class="card-tools d-flex flex-wrap align-items-center">
        <select name="device_type" class="form-select form-select-sm me-2" required aria-label="Type to apply">
          <option value="">Set selected to…</option>
          <?php foreach (Lifecycle::TYPES as $t => [$class, , $virt]): if ($t === Lifecycle::UNASSIGNED) continue; ?>
            <option value="<?= e($t) ?>"><?= e($t) ?> — <?= e(Lifecycle::CLASSES[$class]) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-warning" id="bulk-apply" disabled><i class="fas fa-check me-1"></i>Apply</button>
      </div>
      <?php endif; ?>
    </div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead><tr>
          <?php if ($canEdit): ?><th class="w-1"><input type="checkbox" id="select-all" aria-label="Select all"></th><?php endif; ?>
          <th>Name</th><th>Client</th><th><?= e(psa_name()) ?> type</th><th>Make / model</th><th>Serial</th><th>Location</th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $d): ?>
            <tr>
              <?php if ($canEdit): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>" class="row-check" aria-label="Select <?= e($d['name']) ?>"></td><?php endif; ?>
              <td><a href="/devices/<?= (int) $d['id'] ?>" class="fw-bold"><?= e($d['name']) ?></a></td>
              <td><?= $d['client_id'] ? '<a href="/clients/' . (int) $d['client_id'] . '/devices">' . e($d['client_name']) . '</a>' : '—' ?></td>
              <td><span class="badge text-bg-light border"><?= e($psaTypes[(string) $d['psa_asset_id']] ?? '—') ?></span></td>
              <td><?= e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) ?: '—' ?></td>
              <td class="font-monospace small"><?= e($d['serial'] ?? '—') ?></td>
              <td class="small"><?= e($d['location'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
<?php endif; ?>

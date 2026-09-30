<?php
/**
 * CSV import of clients or contacts: the upload form, then the check of what would change.
 * @var string $kind; ?array $plan; string $token, $fileName; array $used, $unused
 */
use Align\Import\CsvImport;

$labels = ['clients' => 'Clients', 'contacts' => 'Contacts'];
$badge = ['add' => ['success', 'Add'], 'update' => ['info', 'Update'], 'skip' => ['light border', 'Skip'], 'error' => ['danger', 'Can\'t import']];
$cols = $kind === 'clients' ? CsvImport::CLIENT_COLUMNS : CsvImport::CONTACT_COLUMNS;
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-file-import text-secondary me-2"></i>Import <?= e(strtolower($labels[$kind])) ?></h1>
  <a class="btn btn-sm btn-default" href="/<?= $kind === 'clients' ? 'clients' : 'contacts' ?>"><i class="fas fa-arrow-left me-1"></i>Back to <?= e(strtolower($labels[$kind])) ?></a>
</div>

<?php if ($plan === null): ?>
<ul class="nav nav-tabs mb-3">
  <?php foreach ($labels as $k => $l): ?><li class="nav-item"><a class="nav-link<?= $k === $kind ? ' active' : '' ?>" href="/clients/import?kind=<?= $k ?>"><?= e($l) ?></a></li><?php endforeach; ?>
</ul>
<div class="row">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-body">
        <form method="post" action="/clients/import" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>">
          <div class="mb-3">
            <label for="import-file">CSV file</label>
            <input type="file" class="form-control" id="import-file" name="file" accept=".csv,text/csv" required>
            <small class="form-text text-muted">Up to 5 MB and <?= num(CsvImport::MAX_ROWS) ?> rows. The first row names the columns. Nothing is saved until you check the result and press Import.</small>
          </div>
          <button class="btn btn-primary"><i class="fas fa-magnifying-glass me-1"></i>Check file</button>
          <a class="btn btn-default ms-1" href="/clients/import/template/<?= e($kind) ?>"><i class="fas fa-download me-1"></i>Template</a>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-body small">
      <?php if ($kind === 'clients'): ?>
        <p class="mb-2">Each row is a client. A client with the same name that's already here gets only the columns your file fills in; nothing is cleared.<?= \Align\Providers\Providers::psaConfigured() ? ' Clients from ' . e(psa_name()) . ' keep their details from there (only industry and notes change).' : '' ?></p>
      <?php else: ?>
        <p class="mb-2">Each row is a contact, with the client's name in a <b>Client</b> column. Import clients first. A contact already at that client (same email, or same name when there's no email) is updated.<?= \Align\Providers\Providers::psaConfigured() ? ' Contacts that come from ' . e(psa_name()) . ' are left alone.' : '' ?> Yes / No columns take yes, y, true, 1 or x.</p>
      <?php endif; ?>
      <p class="mb-1 text-muted">Columns it understands (other names for them work too):</p>
      <div><?php foreach ($cols as [$label]): ?><span class="badge text-bg-light border me-1 mb-1"><?= e($label) ?></span><?php endforeach; ?></div>
    </div></div>
  </div>
</div>
<?php else:
  $n = array_count_values(array_column($plan, 'action'));
  $todo = ($n['add'] ?? 0) + ($n['update'] ?? 0);
?>
<div class="card">
  <div class="card-body py-2 d-flex flex-wrap align-items-center">
    <div class="me-auto">
      <b><?= e($fileName) ?></b>: <?= count($plan) ?> rows.
      <span class="badge text-bg-success"><?= (int) ($n['add'] ?? 0) ?> to add</span>
      <span class="badge text-bg-info"><?= (int) ($n['update'] ?? 0) ?> to update</span>
      <?php if ($n['skip'] ?? 0): ?><span class="badge text-bg-light border"><?= (int) $n['skip'] ?> skipped</span><?php endif; ?>
      <?php if ($n['error'] ?? 0): ?><span class="badge text-bg-danger"><?= (int) $n['error'] ?> can't be imported</span><?php endif; ?>
      <div class="small text-muted mt-1">Columns used: <?= e(implode(', ', $used)) ?><?= $unused ? '. Not used: ' . e(implode(', ', $unused)) : '' ?>.</div>
    </div>
    <a class="btn btn-sm btn-default me-2" href="/clients/import?kind=<?= e($kind) ?>">Choose another file</a>
    <form method="post" action="/clients/import/run"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <button class="btn btn-sm btn-primary" <?= $todo ? '' : 'disabled' ?>><i class="fas fa-file-import me-1"></i>Import <?= $todo ?> <?= $kind === 'clients' ? 'client' : 'contact' ?><?= $todo === 1 ? '' : 's' ?></button></form>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-striped mb-0">
      <thead><tr><th class="text-end">Row</th><th>Result</th><?= $kind === 'contacts' ? '<th>Client</th>' : '' ?><th>Name</th><th>Details</th><th>Note</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($plan, 0, 500) as $p): [$cls, $txt] = $badge[$p['action']]; ?>
        <tr>
          <td class="text-end text-muted small"><?= (int) $p['row'] ?></td>
          <td><span class="badge text-bg-<?= $cls ?>"><?= e($txt) ?></span></td>
          <?php if ($kind === 'contacts'): ?><td><?= e($p['client'] ?? '') ?></td><?php endif; ?>
          <td><?= e($p['name']) ?></td>
          <td class="small"><?= e(implode(' · ', array_map(fn($f, $v) => ($cols[$f][0] ?? $f) . ': ' . (is_int($v) ? ($v ? 'yes' : 'no') : mb_strimwidth(str_replace("\n", ', ', (string) $v), 0, 60, '…')), array_keys(array_diff_key($p['values'], ['name' => 1])), array_diff_key($p['values'], ['name' => 1])))) ?></td>
          <td class="small text-muted"><?= e($p['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($plan) > 500): ?><div class="card-footer small text-muted">Showing the first 500 rows; all <?= count($plan) ?> are imported.</div><?php endif; ?>
</div>
<?php endif; ?>

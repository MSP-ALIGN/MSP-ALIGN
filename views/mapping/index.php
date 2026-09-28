<?php
/**
 * Client mapping: one column group per connector that links clients (each RMM, each backup product, ...).
 * @var array $clients, $providers (key => name, noun, icon, count_label, configured, backup, connector_name, records, unlinked, links, summary, linked);
 *      int $total, $missing; string $show; bool $anyBackupCompanies
 */
$how = function (?array $link): string {
    if (!$link) {
        return '';
    }
    return match (true) {
        $link['external_id'] === null => '<span class="text-muted" title="Set to not linked by hand; sync won\'t link it by name">kept unlinked</span>',
        $link['match_method'] === 'auto' => '<span class="text-muted" title="Linked on sync because the names match">by name</span>',
        default => '<span class="text-muted" title="Chosen here by hand">by hand</span>',
    };
};
$plural = fn(string $noun, int $n) => $n === 1 ? $noun : ($noun === 'company' ? 'companies' : $noun . 's');
$cols = 1 + 3 * count($providers);
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 mr-auto"><i class="fas fa-link text-secondary mr-2"></i>Client mapping</h1>
  <?php if ($anyBackupCompanies): ?><a class="btn btn-sm btn-default mr-1" href="/mapping/backups" title="Backups of hosted clients that run on your own backup server"><i class="fas fa-building mr-1"></i>Hosted backups</a><?php endif; ?>
  <a class="btn btn-sm btn-default" href="/help#guide-mapping"><i class="fas fa-circle-question mr-1"></i>How it works</a>
</div>
<p class="text-muted small mb-3" style="max-width:900px">Link each client to its record in every connected tool<?= $providers ? ' (' . e(implode(', ', array_map(fn($p) => $p['name'] . ' ' . $p['noun'], $providers))) . ')' : '' ?>. Matching names link automatically on sync; anything you set here is kept, including <b>Not linked</b>. Clients added by hand can be linked too.</p>

<?php if (!$providers): ?>
  <div class="card card-body text-muted">No connected tool links clients yet. Connect an RMM or a backup product under Integrations.</div>
<?php else: ?>
<div class="row">
  <?php foreach ($providers as $key => $p): $nUn = count($p['unlinked']); ?>
    <div class="col-xl-<?= count($providers) >= 3 ? 4 : 6 ?> col-12">
      <div class="card mb-3">
        <div class="card-body py-2">
          <div class="d-flex align-items-center">
            <i class="<?= e($p['icon']) ?> fa-fw text-secondary mr-2"></i><b class="mr-auto"><?= e($p['name']) ?></b>
            <?php if ($p['records']): ?><span class="small"><b><?= (int) $p['linked'] ?></b> of <?= (int) $total ?> clients linked</span><?php endif; ?>
          </div>
          <?php if (!$p['records']): ?>
            <div class="small text-muted mt-1"><?= $p['configured'] ? e($p['name']) . ' is connected. Run a sync (Integrations → Sync) to load its ' . e($plural($p['noun'], 2)) . ' here.'
              : 'To link ' . e($p['name']) . ' ' . e($plural($p['noun'], 2)) . ', connect the ' . e($p['connector_name']) . ' under ' . (\Align\Auth::can('admin') ? '<a href="/integrations/' . e($key) . '">Integrations</a>' : 'Integrations') . ' and run a sync.' ?></div>
          <?php elseif ($nUn): ?>
            <div class="small text-muted mt-1"><i class="fas fa-circle-info text-primary mr-1"></i><?= $nUn ?> <?= e($plural($p['noun'], $nUn)) ?> not linked to any client:
              <?= e(implode(', ', array_map(fn($r) => $r['name'] . ' (' . $r['count'] . ')', $p['unlinked']))) ?><?php if ($p['backup']): ?>. If one is your own backup server, its machines are sorted into clients under <a href="/mapping/backups">Hosted backups</a><?php endif; ?>.</div>
          <?php else: ?>
            <div class="small text-muted mt-1"><i class="fas fa-circle-check text-success mr-1"></i>Every <?= e($p['noun']) ?> is linked to a client.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<form method="post" action="/mapping">
  <?= csrf_field() ?>
  <input type="hidden" name="show" value="<?= e($show) ?>">
  <div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center flex-wrap">
      <ul class="nav nav-pills nav-pills-dark mr-auto">
        <li class="nav-item"><a class="nav-link py-1 px-2 small<?= $show === 'all' ? ' active' : ' text-light' ?>" href="/mapping"><i class="fas fa-list mr-1"></i>All clients <span class="badge badge-<?= $show === 'all' ? 'light' : 'secondary' ?>"><?= (int) $total ?></span></a></li>
        <li class="nav-item"><a class="nav-link py-1 px-2 small<?= $show === 'missing' ? ' active' : ' text-light' ?>" href="/mapping?show=missing"><i class="fas fa-link-slash mr-1"></i>Missing a link <span class="badge badge-<?= $show === 'missing' ? 'light' : ($missing ? 'warning' : 'secondary') ?>"><?= (int) $missing ?></span></a></li>
      </ul>
      <div class="card-tools d-flex">
        <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="map-table" placeholder="Filter…" aria-label="Filter clients">
        <?php if ($clients): ?><button class="btn btn-sm btn-primary text-nowrap"><i class="fas fa-check mr-1"></i>Save mapping</button><?php endif; ?>
      </div>
    </div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-striped table-borderless table-hover mb-0" id="map-table">
        <thead class="text-dark">
          <tr><th rowspan="2" class="align-bottom">Client</th><?php foreach ($providers as $p): ?><th colspan="3" scope="colgroup" class="border-left text-nowrap"><i class="<?= e($p['icon']) ?> fa-fw text-muted mr-1"></i><?= e($p['name']) ?></th><?php endforeach; ?></tr>
          <tr class="small"><?php foreach ($providers as $p): ?><th class="border-left font-weight-normal text-muted"><?= e(ucfirst($p['noun'])) ?></th><th class="font-weight-normal text-muted">How</th><th class="text-right font-weight-normal text-muted"><?= e(ucfirst($p['count_label'])) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($clients as $c): $cid = (int) $c['id']; ?>
          <tr>
            <td class="align-middle"><?= e($c['name']) ?><?= $c['source'] === 'manual' ? ' <span class="badge badge-light border">manual</span>' : '' ?></td>
            <?php foreach ($providers as $key => $p): $link = $p['links'][$cid] ?? null; $sum = $p['summary'][$cid] ?? null; ?>
            <td class="border-left" style="min-width:200px">
              <?php if ($p['records']): ?>
              <select name="link[<?= e($key) ?>][<?= $cid ?>]" class="custom-select custom-select-sm" aria-label="<?= e($p['name'] . ' ' . $p['noun']) ?> for <?= e($c['name']) ?>">
                <option value="">— Not linked —</option>
                <?php foreach ($p['records'] as $r): ?>
                  <option value="<?= e($r['id']) ?>" <?= (string) ($link['external_id'] ?? '') === $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?><?= $r['client_id'] !== null && $r['client_id'] !== $cid ? ' (linked elsewhere)' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <select class="custom-select custom-select-sm" disabled aria-label="<?= e($p['name'] . ' ' . $p['noun']) ?> for <?= e($c['name']) ?>"><option><?= $p['configured'] ? 'Run a sync to load ' . e($p['name']) . ' ' . e($plural($p['noun'], 2)) : e($p['name']) . ' not connected' ?></option></select>
              <?php endif; ?>
            </td>
            <td class="small align-middle text-nowrap"><?= $how($link) ?></td>
            <td class="text-right align-middle small text-nowrap"><?= $sum ? $sum['html'] : '<span class="text-muted">—</span>' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$clients): ?><tr><td colspan="<?= $cols ?>" class="text-muted p-3"><?= $show === 'missing' && $total ? 'Every client is linked in every connected tool.' : 'No clients yet.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($clients): ?><div class="card-footer py-2 d-flex align-items-center"><span class="small text-muted mr-auto">Pick <b>— Not linked —</b> to keep a client unlinked; sync won't link it by name later.</span><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save mapping</button></div><?php endif; ?>
  </div>
</form>
<?php endif; ?>

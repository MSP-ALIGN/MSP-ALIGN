<?php
/**
 * Client mapping. 2.38.0 One tool at a time: a tab per connector that links clients (each RMM, each backup product,
 * ...) with how many clients are missing a link there, then the chosen tool's card and its client list.
 * @var array $clients (the rows for the chosen tool and list), $providers (key => name, noun, icon, count_label,
 *      configured, backup, creates, creatable, connector_name, records, unlinked, links, summary, linked, missing),
 *      $hidden (key => name: tools not connected, with nothing stored); int $total; string $show ('missing'|'all'),
 *      $tool (the chosen key, '' when there is none); bool $autoCreate
 * SECURITY: record and client names come from the RMM, the backup product or the PSA: every one goes through e().
 * $p['summary'][...]['html'] is built by the connector from numbers and fixed text (LinksClients::linkClientSummary
 * promises it is already escaped). Both forms carry the CSRF field; MappingController checks roles and ids.
 */
/** The "How" cell: no backups needed (2.26.0) / kept unlinked / by name / by hand (fixed HTML, nothing from the link itself). */
$how = function (?array $link): string {
    if (!$link) {
        return '';
    }
    return match (true) {
        $link['external_id'] === null && $link['match_method'] === 'not_needed' => '<span class="text-muted" title="Set by hand: this client doesn\'t need backups, so nothing counts it as missing one">no backups needed</span>', // 2.26.0
        $link['external_id'] === null => '<span class="text-muted" title="Set to not linked by hand; sync won\'t link it by name">kept unlinked</span>',
        $link['match_method'] === 'auto' => '<span class="text-muted" title="Linked on sync because the names match">by name</span>',
        default => '<span class="text-muted" title="Chosen here by hand">by hand</span>',
    };
};
/** "organization" / "organizations", "company" / "companies". */
$plural = fn(string $noun, int $n) => $n === 1 ? $noun : ($noun === 'company' ? 'companies' : $noun . 's');
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-link text-secondary me-2"></i>Client mapping</h1>
  <a class="btn btn-sm btn-default" href="/help#guide-mapping"><i class="fas fa-circle-question me-1"></i>How it works</a>
</div>
<p class="text-muted small mb-3" style="max-width:900px">Link each client to its record in every connected tool, one tool at a time. Matching names link automatically on sync; anything you set here is kept, including <b>Not linked</b>. Clients added by hand can be linked too.</p>

<?php if (!$providers): ?>
  <div class="card"><?= \Align\View::fetch('partials/empty', ['icon' => 'fa-link', 'text' => 'No connected tool links clients yet.', 'more' => 'Connect an RMM or a backup product under Integrations.', 'action' => \Align\Auth::can('admin') ? '<a class="btn btn-sm btn-primary" href="/integrations">Integrations</a>' : '']) ?></div>
<?php else: $p = $providers[$tool]; $key = $tool; $nUn = count($p['unlinked']); $nMiss = count($p['missing']); $q = fn(string $show) => '/mapping?' . http_build_query(['tool' => $key, 'show' => $show]); ?>
<?php // 2.38.0 One tab per tool; the badge is how many clients are missing a link there (warning when any) ?>
<nav aria-label="Tools"><ul class="nav nav-tabs mb-3 flex-nowrap map-tools" data-scroll-active>
  <?php foreach ($providers as $k => $t): $n = count($t['missing']); $on = $k === $tool; ?>
    <li class="nav-item"><a class="nav-link text-nowrap<?= $on ? ' active' : ' text-body' ?>" href="/mapping?tool=<?= e(rawurlencode($k)) ?>"<?= $on ? ' aria-current="page"' : '' ?>><i class="<?= e($t['icon']) ?> fa-fw me-1 text-secondary"></i><?= e($t['name']) ?>
      <?php if ($n): ?><span class="badge text-bg-warning ms-1" title="<?= $n ?> client<?= $n === 1 ? '' : 's' ?> missing a link"><?= $n ?><span class="visually-hidden"> client<?= $n === 1 ? '' : 's' ?> missing a link</span></span><?php elseif ($t['records']): ?><i class="fas fa-circle-check text-success ms-1" title="Every client is linked or set" aria-hidden="true"></i><span class="visually-hidden"> (all linked)</span><?php endif; ?></a></li>
  <?php endforeach; ?>
</ul></nav>

<?php // The chosen tool's summary: how many clients are linked, its records no client has, and (no PSA) adding clients from it ?>
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex align-items-center">
      <i class="<?= e($p['icon']) ?> fa-fw text-secondary me-2"></i><b class="me-auto"><?= e($p['name']) ?></b>
      <?php if ($p['records']): ?><span class="small"><b><?= (int) $p['linked'] ?></b> of <?= (int) $total ?> clients linked</span><?php endif; ?>
    </div>
    <?php if (!$p['records']): ?>
      <div class="small text-muted mt-1"><?= e($p['name']) ?> is connected. Run a sync (Integrations → Sync) to load its <?= e($plural($p['noun'], 2)) ?> here.</div>
    <?php elseif ($nUn): ?>
      <div class="small text-muted mt-1"><i class="fas fa-circle-info text-primary me-1"></i><?= $nUn ?> <?= e($plural($p['noun'], $nUn)) ?> not linked to any client:
        <?= e(implode(', ', array_map(fn($r) => $r['name'] . ' (' . $r['count'] . ')', $p['unlinked']))) ?><?php if ($p['backup']): ?>. If one is your own backup server, its machines are sorted into clients under <a href="/mapping/backups">Hosted backups</a><?php endif; ?>.</div>
    <?php else: ?>
      <div class="small text-muted mt-1"><i class="fas fa-circle-check text-success me-1"></i>Every <?= e($p['noun']) ?> is linked to a client.</div>
    <?php endif; ?>
    <?php if (!empty($p['creates']) && $p['records']): $creatable = (int) ($p['creatable'] ?? 0); $autoCreate = !empty($autoCreate); ?>
      <div class="d-flex flex-wrap align-items-center mt-2">
        <?php if ($creatable): ?>
        <form method="post" action="/mapping/create-clients" class="me-3 mb-1"><?= csrf_field() ?><input type="hidden" name="provider" value="<?= e($key) ?>">
          <button class="btn btn-xs btn-primary" data-confirm="Add a client for each of the <?= $creatable ?> <?= e($plural($p['noun'], $creatable)) ?> not linked yet?"><i class="fas fa-user-plus me-1"></i>Add <?= $creatable ?> client<?= $creatable === 1 ? '' : 's' ?> from <?= e($plural($p['noun'], $creatable)) ?></button></form>
        <?php endif; ?>
        <?php if (\Align\Auth::can('admin')): ?>
        <form method="post" action="/mapping/create-clients" class="mb-1"><?= csrf_field() ?><input type="hidden" name="provider" value="<?= e($key) ?>"><input type="hidden" name="action" value="auto"><input type="hidden" name="auto" value="<?= $autoCreate ? '0' : '1' ?>">
          <button class="btn btn-xs btn-default" <?= $autoCreate ? '' : 'data-confirm="' . e('Add a client for every new ' . $p['noun'] . ' on each sync? New ' . $plural($p['noun'], 2) . ' become clients without asking.') . '" data-confirm-danger="0" data-confirm-ok="Turn on"' ?>><i class="fas fa-toggle-<?= $autoCreate ? 'on text-success' : 'off' ?> me-1"></i><?= $autoCreate ? 'New ' . e($plural($p['noun'], 2)) . ' become clients on each sync' : 'Also add clients for new ' . e($plural($p['noun'], 2)) . ' on each sync' ?></button></form>
        <?php endif; ?>
      </div>
      <div class="small text-muted">No PSA is connected, so clients can come from here. Each <?= e($p['noun']) ?> becomes a client once; one you delete isn't added again.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($p['records']): ?>
<form method="post" action="/mapping" data-unsaved data-confirm-rules="<?= e(json_encode([['changes' => true, 'title' => 'Save {n} mapping changes?', 'ok' => 'Save mapping',
    'text' => 'Devices, backups and service data follow the links you changed, so they can move to a different client.']])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="tool" value="<?= e($key) ?>">
  <input type="hidden" name="show" value="<?= e($show) ?>">
  <div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center flex-wrap">
      <?php // Needs a link (this tool only) / every client ?>
      <ul class="nav nav-pills me-auto">
        <li class="nav-item"><a class="nav-link py-1 px-2 small<?= $show === 'missing' ? ' active' : ' text-body' ?>" href="<?= e($q('missing')) ?>"><i class="fas fa-link-slash me-1"></i>Missing a link <span class="badge text-bg-<?= $show === 'missing' ? 'light' : ($nMiss ? 'warning' : 'secondary') ?>"><?= $nMiss ?></span></a></li>
        <li class="nav-item"><a class="nav-link py-1 px-2 small<?= $show === 'all' ? ' active' : ' text-body' ?>" href="<?= e($q('all')) ?>"><i class="fas fa-list me-1"></i>All clients <span class="badge text-bg-<?= $show === 'all' ? 'light' : 'secondary' ?>"><?= (int) $total ?></span></a></li>
      </ul>
      <div class="card-tools d-flex">
        <input type="search" class="form-control form-control-sm me-2 filter-input" data-filter-table="map-table" data-enter-nosubmit placeholder="Filter…" aria-label="Filter clients">
        <?php if ($clients): ?><button class="btn btn-sm btn-primary text-nowrap"><i class="fas fa-check me-1"></i>Save mapping</button><?php endif; ?>
      </div>
    </div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-striped table-borderless table-hover mb-0" id="map-table">
        <thead class="text-dark">
          <tr class="small"><th class="fw-normal text-muted">Client</th><th class="fw-normal text-muted"><?= e(ucfirst($p['noun'])) ?> in <?= e($p['name']) ?></th><th class="fw-normal text-muted">How</th><th class="text-end fw-normal text-muted"><?= e(ucfirst($p['count_label'])) ?></th></tr>
        </thead>
        <tbody>
        <?php $byId = array_column($p['records'], null, 'id'); ?>
        <?php foreach ($clients as $c): $cid = (int) $c['id']; $link = $p['links'][$cid] ?? null; $sum = $p['summary'][$cid] ?? null; ?>
          <tr>
            <td class="align-middle"><?= e($c['name']) ?><?= $c['source'] === 'manual' ? ' <span class="badge text-bg-light border">manual</span>' : '' ?></td>
            <td style="min-width:220px;max-width:420px">
              <?php // Only the current choice is written here; the full list is filled in from one copy when the select is used (app.js)
              $cur = (string) ($link['external_id'] ?? ''); $r = $cur !== '' ? ($byId[$cur] ?? null) : null; ?>
              <select name="link[<?= e($key) ?>][<?= $cid ?>]" class="form-select form-select-sm" data-options="map-opts-<?= e($key) ?>" data-client="<?= $cid ?>" aria-label="<?= e($p['name'] . ' ' . $p['noun']) ?> for <?= e($c['name']) ?>">
                <option value="">— Not linked —</option>
                <?php if ($p['backup']): $nn = ($link['match_method'] ?? null) === 'not_needed' && $cur === ''; // 2.26.0 ?>
                  <option value="<?= e(\Align\Controllers\MappingController::NOT_NEEDED) ?>" data-keep="1"<?= $nn ? ' selected' : '' ?>>— Doesn't need backups —</option>
                <?php endif; ?>
                <?php if ($r): ?><option value="<?= e($r['id']) ?>" selected><?= e($r['name']) ?><?= $r['client_id'] !== null && $r['client_id'] !== $cid ? ' (linked elsewhere)' : '' ?></option><?php endif; ?>
              </select>
            </td>
            <td class="small align-middle text-nowrap"><?= $how($link) ?: '<span class="text-warning-emphasis" title="No link yet and no choice made">not linked yet</span>' ?></td>
            <td class="text-end align-middle small text-nowrap"><?= $sum ? $sum['html'] : '<span class="text-muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$clients): ?><tr><td colspan="4" class="text-muted p-3"><?= $show === 'missing' && $total ? 'Every client is linked in ' . e($p['name']) . ' (or set to Not linked' . ($p['backup'] ? ' or Doesn\'t need backups' : '') . '). <a href="' . e($q('all')) . '">Show all clients</a>' : 'No clients yet.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
      <template id="map-opts-<?= e($key) ?>"><?php foreach ($p['records'] as $r): ?><option value="<?= e($r['id']) ?>" data-client="<?= $r['client_id'] === null ? '' : (int) $r['client_id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></template>
    </div>
    <?php if ($clients): ?><div class="card-footer py-2 d-flex align-items-center"><span class="small text-muted me-auto">Pick <b>— Not linked —</b> to keep a client unlinked; sync won't link it by name later.<?= $p['backup'] ? ' For a client with no backups to manage, pick <b>— Doesn\'t need backups —</b>: it\'s then never counted as missing one.' : '' ?> One client's links in every tool are also on its own page (Connectors).</span><button class="btn btn-sm btn-primary"><i class="fas fa-check me-1"></i>Save mapping</button></div><?php endif; ?>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>
<?php // 2.38.0 Tools that could link clients but aren't connected: named here instead of taking a tab ?>
<?php if ($hidden): ?>
  <p class="small text-muted mt-3 mb-0"><i class="fas fa-plug me-1"></i>Also links clients when connected: <?= e(implode(', ', $hidden)) ?><?= \Align\Auth::can('admin') ? ' (<a href="/integrations">Integrations</a>)' : '' ?>.</p>
<?php endif; ?>

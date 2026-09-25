<header class="page-head">
  <h1>Clients</h1>
  <div class="row">
    <input type="search" class="filter-input" data-filter-table="clients-table" placeholder="Filter clients…" value="<?= e($q) ?>">
    <a class="btn" href="/clients<?= $showArchived ? '' : '?archived=1' ?>"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a>
  </div>
</header>

<div class="card flush">
<table class="table" id="clients-table">
  <thead><tr>
    <th>Client</th><th>NinjaOne organization</th><th class="num">Devices</th>
    <th class="num">Critical</th><th class="num">Warnings</th><th class="num">Replacement, next 12 mo</th>
  </tr></thead>
  <tbody>
  <?php foreach ($clients as $c): $s = $stats[$c['id']] ?? ['total' => 0, 'bad' => 0, 'warn' => 0, 'cost12' => 0]; ?>
    <tr>
      <td><a href="/clients/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a><?= $c['is_archived'] ? ' <span class="badge tone-muted">archived</span>' : '' ?></td>
      <td><?= $c['org_name'] ? e($c['org_name']) : '<span class="muted">Not linked</span>' ?></td>
      <td class="num"><?= (int) $s['total'] ?></td>
      <td class="num"><?= $s['bad'] ? '<span class="badge tone-bad">' . (int) $s['bad'] . '</span>' : '<span class="muted">0</span>' ?></td>
      <td class="num"><?= $s['warn'] ? '<span class="badge tone-warn">' . (int) $s['warn'] . '</span>' : '<span class="muted">0</span>' ?></td>
      <td class="num"><?= $s['cost12'] ? money($s['cost12']) : '<span class="muted">—</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$clients): ?>
    <tr><td colspan="6" class="muted">No clients yet. Configure ITFlow in Settings and run a sync.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

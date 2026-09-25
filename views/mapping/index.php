<header class="page-head">
  <div>
    <h1>Client mapping</h1>
    <p class="muted">Link each ITFlow client to its NinjaOne organization. Matching names link automatically on sync; anything you set here is kept.</p>
  </div>
  <input type="search" class="filter-input" data-filter-table="map-table" placeholder="Filter…">
</header>

<?php if ($unmappedOrgs): ?>
  <div class="flash flash-info"><?= count($unmappedOrgs) ?> NinjaOne organization(s) not linked to any client:
    <?= e(implode(', ', array_map(fn($o) => $o['name'] . ' (' . $o['device_count'] . ')', $unmappedOrgs))) ?>
  </div>
<?php endif; ?>

<form method="post" action="/mapping">
  <?= csrf_field() ?>
  <div class="card flush">
    <table class="table" id="map-table">
      <thead><tr><th>ITFlow client</th><th>NinjaOne organization</th><th>How</th><th class="num">Devices</th></tr></thead>
      <tbody>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td>
            <select name="org[<?= (int) $c['id'] ?>]">
              <option value="0">— Not linked —</option>
              <?php foreach ($orgs as $o): ?>
                <option value="<?= (int) $o['id'] ?>" <?= (int) $c['ninja_org_id'] === (int) $o['id'] ? 'selected' : '' ?>>
                  <?= e($o['name']) ?><?= $o['client_id'] && (int) $o['client_id'] !== (int) $c['id'] ? ' (linked elsewhere)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="muted small"><?= e($c['match_method'] ?? '') ?></td>
          <td class="num"><?= (int) $c['device_count'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$clients): ?><tr><td colspan="4" class="muted">No clients yet — run a sync first.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($clients): ?><p><button class="btn primary">Save mapping</button></p><?php endif; ?>
</form>

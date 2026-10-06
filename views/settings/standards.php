<?php
/**
 * 2.3.0 Settings → Standards: the library alignment reviews measure clients against (admins).
 * @var array $categories, $standards (Standards::all, active first), $helps (Standards::helpsAll); int $reviews
 * Every title and text is escaped (data-* values too); classes come from fixed lists. The edit and category windows
 * are filled from the button's data-f-* values by app.js (values only, never markup).
 */
use Align\Alignment\Alignment;
use Align\Roadmap\Roadmap;

$tab = 'standards';
require __DIR__ . '/_tabs.php';
$active = array_values(array_filter($standards, fn($s) => $s['is_active']));
$off = array_values(array_filter($standards, fn($s) => !$s['is_active']));
$byCat = [];
foreach ($active as $s) {
    $byCat[(int) $s['category_id']][] = $s;
}
$pill = fn(string $p) => '<span class="badge rounded-pill text-bg-' . (Alignment::PRIORITIES[$p][1] ?? 'secondary') . ' al-prio">' . e(Alignment::PRIORITIES[$p][0] ?? $p) . '</span>';
$checks = Alignment::checks();
$lastSection = null;
// The values the edit window is filled with
$fill = fn(array $s) => 'data-bs-toggle="modal" data-bs-target="#std-edit" data-fill data-f-id="' . (int) $s['id'] . '" data-f-title="' . e($s['title']) . '" data-f-category_id="' . (int) $s['category_id']
    . '" data-f-priority="' . e($s['priority']) . '" data-f-why="' . e((string) $s['why']) . '" data-f-how="' . e((string) $s['how']) . '" data-f-auto_check="' . e((string) $s['auto_check'])
    . '" data-f-tags="' . e(str_replace(',', ', ', (string) $s['tags'])) . '" data-f-fix_title="' . e((string) $s['fix_title']) . '" data-f-fix_category="' . e((string) $s['fix_category'])
    . '" data-f-fix_cost="' . ($s['fix_cost'] !== null ? e((string) (float) $s['fix_cost']) : '') . '" data-f-heading="Edit standard"';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <p class="text-muted mb-0 me-auto al-measure">Your standards: how you think every client should be set up. An alignment review (on each client's Alignment page)
    marks each one aligned, misaligned or not applicable, and the gaps become roadmap projects.</p>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-sm btn-default" href="/settings/standards/export"><i class="fas fa-file-export me-1"></i>Export</a>
    <button type="button" class="btn btn-sm btn-default" data-bs-toggle="modal" data-bs-target="#std-import"><i class="fas fa-file-import me-1"></i>Import</button>
    <button type="button" class="btn btn-sm btn-default" data-bs-toggle="modal" data-bs-target="#std-cat" data-fill data-f-action="add" data-f-id="" data-f-name="" data-f-section="" data-f-heading="Add a category"><i class="fas fa-folder-plus me-1"></i>Category</button>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#std-edit" data-fill data-f-id="" data-f-title="" data-f-priority="medium" data-f-why="" data-f-how=""
      data-f-auto_check="" data-f-tags="" data-f-fix_title="" data-f-fix_category="" data-f-fix_cost="" data-f-heading="Add a standard"><i class="fas fa-plus me-1"></i>Standard</button>
  </div>
</div>

<div class="row g-2 mb-3">
  <?php foreach ([['Standards', count($active)], ['Categories', count($categories)], ['Automatic checks', count(array_filter($active, fn($s) => $s['auto_check']))],
      ['Linked to compliance', count($helps)], ['Finished reviews', $reviews]] as [$l, $v]): ?>
    <div class="col-6 col-md"><div class="card card-body py-2 mb-0"><div class="small text-muted"><?= e($l) ?></div><b class="fs-5"><?= (int) $v ?></b></div></div>
  <?php endforeach; ?>
</div>

<?php if (!$active): ?>
  <div class="callout callout-info d-flex flex-wrap align-items-center gap-2"><span class="me-auto">The library is empty. Add your own standards, import a file, or start from the starter set.</span>
    <form method="post" action="/settings/standards/starter"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Add the starter set</button></form></div>
<?php endif; ?>

<?php foreach ($categories as $i => $c): $rows = $byCat[(int) $c['id']] ?? []; ?>
  <?php if ($c['section'] && $c['section'] !== $lastSection): $lastSection = $c['section']; ?><h2 class="h6 text-uppercase text-muted mt-3"><?= e($c['section']) ?></h2><?php endif; ?>
  <div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
      <h3 class="card-title mt-1 me-auto"><?= e($c['name']) ?> <span class="badge text-bg-secondary ms-1"><?= count($rows) ?></span></h3>
      <div class="card-tools d-flex gap-1">
        <form method="post" action="/settings/standards/categories" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button class="btn btn-tool" name="action" value="up" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>><i class="fas fa-arrow-up"></i><span class="visually-hidden">Move up</span></button>
          <button class="btn btn-tool" name="action" value="down" title="Move down" <?= $i === count($categories) - 1 ? 'disabled' : '' ?>><i class="fas fa-arrow-down"></i><span class="visually-hidden">Move down</span></button></form>
        <button type="button" class="btn btn-tool" title="Rename" data-bs-toggle="modal" data-bs-target="#std-cat" data-fill data-f-action="rename" data-f-id="<?= (int) $c['id'] ?>" data-f-name="<?= e($c['name']) ?>"
          data-f-section="<?= e((string) $c['section']) ?>" data-f-heading="Rename the category"><i class="fas fa-pen"></i><span class="visually-hidden">Rename</span></button>
        <?php if (!$rows): ?>
          <form method="post" action="/settings/standards/categories" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-tool" name="action" value="delete" title="Delete" data-confirm="Delete the empty category <?= e($c['name']) ?>?"><i class="fas fa-trash"></i><span class="visually-hidden">Delete</span></button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$rows): ?><div class="card-body small text-muted">No standards in this category.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0 al-lib">
      <thead><tr><th>Standard</th><th>Priority</th><th>Check</th><th class="d-none d-lg-table-cell">Helps with</th><th class="d-none d-md-table-cell">Suggested fix</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): $h = $helps[(int) $s['id']] ?? null; ?>
        <tr>
          <td class="al-minw"><button type="button" class="btn btn-link p-0 text-start fw-semibold text-body text-decoration-none" <?= $fill($s) ?>><?= e($s['title']) ?></button>
            <?php if ($s['why']): ?><div class="small text-muted"><?= e($s['why']) ?></div><?php endif; ?></td>
          <td><?= $pill($s['priority']) ?></td>
          <td class="small"><?= $s['auto_check'] ? '<span class="badge text-bg-light border"><i class="fas fa-robot me-1"></i>' . e($checks[$s['auto_check']] ?? $s['auto_check']) . '</span>' : '<span class="text-muted">Manual</span>' ?></td>
          <td class="small d-none d-lg-table-cell"><?php if ($h): ?><span class="al-helps"><?php foreach (array_slice($h['refs'], 0, 2) as $r): ?><span class="al-chip"><?= e($r) ?></span><?php endforeach; ?>
            <span class="text-muted"><?= (int) $h['count'] ?> control<?= $h['count'] == 1 ? '' : 's' ?> in <?= (int) $h['frameworks'] ?> framework<?= $h['frameworks'] == 1 ? '' : 's' ?></span></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <td class="small d-none d-md-table-cell"><?= e((string) $s['fix_title']) ?><?= $s['fix_cost'] !== null ? ' · ' . e(money($s['fix_cost'])) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($off): ?>
  <div class="card">
    <div class="card-header py-2"><h3 class="card-title mt-1 text-muted"><i class="fas fa-fw fa-power-off me-2"></i>Switched off <span class="badge text-bg-secondary ms-1"><?= count($off) ?></span></h3></div>
    <div class="card-body small text-muted pb-0">Removed standards that past reviews used. They stay for those reviews but leave new ones.</div>
    <ul class="list-group list-group-flush">
      <?php foreach ($off as $s): ?>
        <li class="list-group-item d-flex align-items-center gap-2"><span class="me-auto"><?= e($s['title']) ?> <span class="small text-muted">· <?= e($s['category']) ?></span></span>
          <form method="post" action="/settings/standards"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-xs btn-default" name="action" value="restore">Switch back on</button></form></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="/settings/standards/starter" class="small text-muted"><?= csrf_field() ?><i class="fas fa-circle-info me-1"></i>The starter set was built from the MSP Security
  Baseline compliance framework plus continuity, documentation and Microsoft 365 standards.
  <button class="btn btn-link btn-sm p-0 align-baseline">Add back any starter standards you removed</button>.</form>

<!-- Add / edit a standard -->
<div class="modal fade" id="std-edit" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="post" action="/settings/standards" data-unsaved>
    <?= csrf_field() ?><input type="hidden" name="id" value="">
    <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-bullseye me-2"></i><span data-fill-text="heading">Standard</span></h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div class="row g-2">
        <div class="col-md-8 mb-2"><label for="se-title">Standard</label><input id="se-title" name="title" class="form-control" maxlength="255" required placeholder="e.g. MFA on Microsoft 365 for every user"></div>
        <div class="col-md-4 mb-2"><label for="se-prio">Priority</label><select id="se-prio" name="priority" class="form-select">
          <?php foreach (Alignment::PRIORITIES as $k => [$l, , $w]): ?><option value="<?= $k ?>"><?= e($l) ?> (counts <?= $w ?>×)</option><?php endforeach; ?></select></div>
        <div class="col-md-6 mb-2"><label for="se-cat">Category</label><select id="se-cat" name="category_id" class="form-select">
          <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?><option value="0">New category…</option></select></div>
        <div class="col-md-6 mb-2"><label for="se-newcat">New category <small class="text-muted">(when "New category…" is picked)</small></label><input id="se-newcat" name="new_category" class="form-control" maxlength="120"></div>
      </div>
      <label for="se-why">Why it matters <small class="text-muted">(in the client's words: shown on reports)</small></label>
      <textarea id="se-why" name="why" class="form-control mb-2" rows="2" maxlength="2000"></textarea>
      <label for="se-how">How to check <small class="text-muted">(notes for whoever does the review; staff only)</small></label>
      <textarea id="se-how" name="how" class="form-control mb-2" rows="2" maxlength="5000"></textarea>
      <div class="row g-2">
        <div class="col-md-6 mb-2"><label for="se-auto">Automatic check</label><select id="se-auto" name="auto_check" class="form-select">
          <option value="">None: answered by hand</option><?php foreach ($checks as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
          <div class="form-text">Suggests an answer from Align's own data; the reviewer confirms it.</div></div>
        <div class="col-md-6 mb-2"><label for="se-tags">Compliance tags</label><input id="se-tags" name="tags" class="form-control" maxlength="500" list="se-tag-list" placeholder="iam_mfa, cloud_config">
          <datalist id="se-tag-list"><?php foreach (\Align\Compliance\Compliance::allTags() as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
          <div class="form-text">The same tags as compliance controls: matching answers are shared both ways. The first tag counts most.</div></div>
      </div>
      <h6 class="text-uppercase text-muted small mt-2">Suggested fix <span class="text-lowercase fw-normal">(fills "Make project" on a gap)</span></h6>
      <div class="row g-2">
        <div class="col-md-6 mb-2"><label for="se-fix">Project title</label><input id="se-fix" name="fix_title" class="form-control" maxlength="255"></div>
        <div class="col-md-3 mb-2"><label for="se-fcat">Project category</label><select id="se-fcat" name="fix_category" class="form-select"><option value="">Project</option>
          <?php foreach (Roadmap::CATEGORIES as $k => [$l]): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 mb-2"><label for="se-fcost">Typical cost</label><input id="se-fcost" name="fix_cost" class="form-control" inputmode="decimal"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-danger me-auto" name="action" value="delete" formnovalidate data-confirm="Remove this standard? If past reviews used it, it's switched off instead, so they keep it." data-confirm-ok="Remove">Remove</button>
      <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i>Save</button>
    </div>
  </form>
</div></div></div>

<!-- Add / rename a category -->
<div class="modal fade" id="std-cat" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
  <form method="post" action="/settings/standards/categories">
    <?= csrf_field() ?><input type="hidden" name="id" value=""><input type="hidden" name="action" value="add">
    <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-folder me-2"></i><span data-fill-text="heading">Category</span></h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <label for="sc-name">Name</label><input id="sc-name" name="name" class="form-control mb-2" maxlength="120" required>
      <label for="sc-section">Section <small class="text-muted">(optional: groups categories under a heading, e.g. Security)</small></label><input id="sc-section" name="section" class="form-control" maxlength="120">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
  </form>
</div></div></div>

<!-- Import -->
<div class="modal fade" id="std-import" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
  <form method="post" action="/settings/standards/import" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-file-import me-2"></i>Import standards</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <p class="small text-muted">A file from Export, on this or another MSP Align install. Standards whose name is already in the library are skipped; nothing is changed or removed.</p>
      <input type="file" name="file" class="form-control" accept=".json,application/json" required aria-label="Standards file">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Import</button></div>
  </form>
</div></div></div>

<header class="page-head">
  <div>
    <div class="crumbs"><a href="/settings">Settings</a></div>
    <h1>OS support dates</h1>
    <p class="muted">A device matches a row when its OS name contains the text and its build number is equal. The longest matching text wins, so edition-specific rows (e.g. "Windows 11 Enterprise") beat general ones. Check Microsoft's lifecycle pages when new releases ship.</p>
  </div>
</header>
<form method="post" action="/settings/os">
  <?= csrf_field() ?>
  <div class="card flush">
    <table class="table">
      <thead><tr><th>Label</th><th>OS name contains</th><th>Build</th><th>Support ends</th><th>Delete</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
        <tr>
          <td><input name="rows[<?= $id ?>][label]" value="<?= e($r['label']) ?>"></td>
          <td><input name="rows[<?= $id ?>][name_contains]" value="<?= e($r['name_contains']) ?>"></td>
          <td><input name="rows[<?= $id ?>][build]" value="<?= e($r['build']) ?>" class="narrow"></td>
          <td><input type="date" name="rows[<?= $id ?>][eos_date]" value="<?= e($r['eos_date']) ?>"></td>
          <td><input type="checkbox" name="rows[<?= $id ?>][delete]" value="1" aria-label="Delete"></td>
        </tr>
      <?php endforeach; ?>
        <tr class="new-row">
          <td><input name="new[label]" placeholder="New: e.g. Windows 11 26H2"></td>
          <td><input name="new[name_contains]" placeholder="Windows 11"></td>
          <td><input name="new[build]" placeholder="26300" class="narrow"></td>
          <td><input type="date" name="new[eos_date]"></td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>
  <p><button class="btn primary">Save</button></p>
</form>

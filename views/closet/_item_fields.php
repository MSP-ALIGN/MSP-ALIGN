<?php
/**
 * 2.15.0 The fields of a closet item (add and edit forms). @var ?array $i the item (null for a new one); bool $msp
 * (the MSP's own stock: show the office); array $offices Closet::offices(); ?int $officeId (preselected office for a
 * new item); string $pid unique id prefix. The quantity is only asked for a new item (later changes go through
 * "Change quantity", so the history shows them). Every value is escaped.
 */
use Align\Closet\Closet;

$i = $i ?? null;
$v = fn(string $k) => e((string) ($i[$k] ?? ''));
$kind = $i['kind'] ?? 'stock';
?>
<div class="row g-2">
  <div class="col-md-8"><label for="<?= $pid ?>-name">Name</label><input id="<?= $pid ?>-name" name="name" class="form-control" maxlength="190" required value="<?= $v('name') ?>" placeholder="e.g. USB-C to HDMI adapter, 24&quot; monitor"></div>
  <div class="col-md-4"><label for="<?= $pid ?>-category">Category</label><select id="<?= $pid ?>-category" name="category" class="form-select">
    <?php foreach (Closet::CATEGORIES as $k => [$label]): ?><option value="<?= e($k) ?>"<?= ($i['category'] ?? 'other') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
  <div class="col-12">
    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="kind" value="stock" id="<?= $pid ?>-k-stock"<?= $kind === 'stock' ? ' checked' : '' ?>><label class="form-check-label fw-normal" for="<?= $pid ?>-k-stock">Counted stock <span class="text-muted small">(cables, mice: how many are on the shelf)</span></label></div>
    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="kind" value="tracked" id="<?= $pid ?>-k-tracked"<?= $kind === 'tracked' ? ' checked' : '' ?>><label class="form-check-label fw-normal" for="<?= $pid ?>-k-tracked">One item <span class="text-muted small">(with a serial number)</span></label></div>
  </div>
  <?php if (!$i): ?><div class="col-md-4"><label for="<?= $pid ?>-qty">On the shelf</label><input id="<?= $pid ?>-qty" name="quantity" type="number" min="0" max="<?= Closet::MAX_QTY ?>" class="form-control" value="1"><div class="form-text">For one item: always 1.</div></div><?php endif; ?>
  <div class="col-md-4"><label for="<?= $pid ?>-min">Warn below <span class="text-muted small">(counted stock)</span></label><input id="<?= $pid ?>-min" name="min_quantity" type="number" min="0" max="<?= Closet::MAX_QTY ?>" class="form-control" value="<?= $v('min_quantity') ?>" placeholder="no minimum"></div>
  <div class="col-md-4"><label for="<?= $pid ?>-loc">Where <span class="text-muted small">(shelf, bin)</span></label><input id="<?= $pid ?>-loc" name="location" class="form-control" maxlength="190" value="<?= $v('location') ?>"></div>
  <?php if ($msp): ?>
    <div class="col-md-4"><label for="<?= $pid ?>-office">Office</label><select id="<?= $pid ?>-office" name="office_id" class="form-select">
      <option value="">No office</option>
      <?php foreach ($offices as $o): if ($o['retired_at'] && (int) $o['id'] !== (int) ($i['office_id'] ?? 0)) continue; ?>
        <option value="<?= (int) $o['id'] ?>"<?= (int) ($i['office_id'] ?? ($officeId ?? 0)) === (int) $o['id'] ? ' selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="col-md-4"><label for="<?= $pid ?>-make">Manufacturer</label><input id="<?= $pid ?>-make" name="manufacturer" class="form-control" maxlength="190" value="<?= $v('manufacturer') ?>"></div>
  <div class="col-md-4"><label for="<?= $pid ?>-model">Model</label><input id="<?= $pid ?>-model" name="model" class="form-control" maxlength="190" value="<?= $v('model') ?>"></div>
  <div class="col-md-4"><label for="<?= $pid ?>-serial">Serial <span class="text-muted small">(one item)</span></label><input id="<?= $pid ?>-serial" name="serial" class="form-control" maxlength="190" value="<?= $v('serial') ?>"></div>
  <div class="col-12"><label for="<?= $pid ?>-notes">Notes <span class="text-muted small">(staff only)</span></label><textarea id="<?= $pid ?>-notes" name="notes" rows="2" class="form-control" maxlength="5000"><?= $v('notes') ?></textarea></div>
</div>

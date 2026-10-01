<?php
/** @var \Align\Integrations\Connector $c; array $values, $status */
[$tone, $label, $detail] = $status;
$field = fn(array $f): string => \Align\View::fetch('integrations/_field', ['f' => $f, 'values' => $values]);
// Settings that start writing into the PSA ask first (2.0.1)
$names = array_column($c->fields(), 'name');
$pn = $c->name();
$rules = array_values(array_filter([
    in_array('psa_two_way', $names, true) ? ['changed' => 'psa_two_way', 'is' => ['psa_two_way' => '1'], 'title' => "Write changes to $pn?", 'ok' => 'Save',
        'text' => "Two-way sync: edits made in Align go to $pn straight away."] : null,
    in_array('psa_create_assets', $names, true) ? ['changed' => 'psa_create_assets', 'is' => ['psa_create_assets' => '1'], 'title' => "Write changes to $pn?", 'ok' => 'Save',
        'text' => "Devices added in Align are created as $pn assets."] : null,
    in_array('psa_writeback', $names, true) ? ['changed' => 'psa_writeback', 'is' => ['psa_writeback' => 'overwrite'], 'title' => "Write changes to $pn?", 'ok' => 'Save', 'danger' => true,
        'text' => "Warranty dates from Align overwrite the dates in $pn for every linked asset."] : null,
    in_array('psa_writeback', $names, true) ? ['changed' => 'psa_writeback', 'is' => ['psa_writeback' => 'fill_empty'], 'title' => "Write changes to $pn?", 'ok' => 'Save',
        'text' => "Warranty dates from Align fill in empty warranty fields in $pn."] : null,
]));
?>
<div class="small mb-1"><a href="/integrations">Integrations</a> /</div>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h3 mb-0 me-3"><i class="<?= e($c->icon()) ?> text-secondary me-2"></i><?= e($c->name()) ?></h1>
  <span class="badge text-bg-<?= e($tone) ?> px-2 py-1 me-auto"><?= e($label) ?></span>
  <?php if ($c->hasTest()): ?>
    <form method="post" action="/integrations/<?= e($c->key()) ?>/test"><?= csrf_field() ?><button class="btn btn-sm btn-default" <?= $c->configured() ? '' : 'disabled title="Save the settings first"' ?>><i class="fas fa-vial me-1"></i>Test connection</button></form>
  <?php endif; ?>
</div>
<?php if ($detail !== ''): ?><p class="small <?= $tone === 'danger' ? 'text-danger' : 'text-muted' ?>"><?= e($detail) ?></p><?php endif; ?>

<div class="row">
  <div class="col-lg-7">
    <form method="post" action="/integrations/<?= e($c->key()) ?>" class="card card-dark" data-unsaved<?= $rules ? ' data-confirm-rules="' . e(json_encode($rules)) . '"' : '' ?>>
      <?= csrf_field() ?>
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-sliders me-2"></i>Connection &amp; options</h3></div>
      <div class="card-body">
        <p class="small text-muted"><?= e($c->summary()) ?> <b><?= e($c->direction()) ?>.</b></p>
        <?php foreach ($c->fields() as $f) echo $field($f); ?>
        <?php if ($c->notes() !== ''): ?><p class="small text-muted mb-0"><?= $c->notes() ?></p><?php endif; ?>
      </div>
      <div class="card-footer"><button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button><?php if ($c->hasTest()): ?><span class="small text-muted ms-2">Test uses the saved values.</span><?php endif; ?></div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-list-ol me-2"></i>How to set it up</h3></div>
      <div class="card-body small"><?= $c->setup() ?></div>
    </div>
  </div>
</div>

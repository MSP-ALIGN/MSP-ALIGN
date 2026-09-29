<?php
/** @var \Align\Integrations\Connector $c; array $values, $status */
[$tone, $label, $detail] = $status;
$field = fn(array $f): string => \Align\View::fetch('integrations/_field', ['f' => $f, 'values' => $values]);
?>
<div class="small mb-1"><a href="/integrations">Integrations</a> /</div>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h3 mb-0 mr-3"><i class="<?= e($c->icon()) ?> text-secondary mr-2"></i><?= e($c->name()) ?></h1>
  <span class="badge badge-<?= e($tone) ?> px-2 py-1 mr-auto"><?= e($label) ?></span>
  <?php if ($c->hasTest()): ?>
    <form method="post" action="/integrations/<?= e($c->key()) ?>/test"><?= csrf_field() ?><button class="btn btn-sm btn-default" <?= $c->configured() ? '' : 'disabled title="Save the settings first"' ?>><i class="fas fa-vial mr-1"></i>Test connection</button></form>
  <?php endif; ?>
</div>
<?php if ($detail !== ''): ?><p class="small <?= $tone === 'danger' ? 'text-danger' : 'text-muted' ?>"><?= e($detail) ?></p><?php endif; ?>

<div class="row">
  <div class="col-lg-7">
    <form method="post" action="/integrations/<?= e($c->key()) ?>" class="card card-dark">
      <?= csrf_field() ?>
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-sliders mr-2"></i>Connection &amp; options</h3></div>
      <div class="card-body">
        <p class="small text-muted"><?= e($c->summary()) ?> <b><?= e($c->direction()) ?>.</b></p>
        <?php foreach ($c->fields() as $f) echo $field($f); ?>
        <?php if ($c->notes() !== ''): ?><p class="small text-muted mb-0"><?= $c->notes() ?></p><?php endif; ?>
      </div>
      <div class="card-footer"><button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save</button><?php if ($c->hasTest()): ?><span class="small text-muted ml-2">Test uses the saved values.</span><?php endif; ?></div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title"><i class="fas fa-fw fa-list-ol mr-2"></i>How to set it up</h3></div>
      <div class="card-body small"><?= $c->setup() ?></div>
    </div>
  </div>
</div>

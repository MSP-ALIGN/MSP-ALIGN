<?php
/** @var \Align\Integrations\Connector $c; array $values, $status */
[$tone, $label, $detail] = $status;
$field = function (array $f) use ($values): string {
    $n = $f['name'];
    $v = $values[$n] ?? null;
    $help = !empty($f['help']) ? '<small class="form-text text-muted">' . e($f['help']) . '</small>' : '';
    switch ($f['type']) {
        case 'secret':
            $h = '<input type="password" id="f-' . e($n) . '" name="' . e($n) . '" class="form-control" autocomplete="new-password" placeholder="' . ($v ? '•••••••• saved (leave blank to keep)' : 'Not set') . '">';
            if ($v) {
                $h .= '<div class="custom-control custom-checkbox mt-1"><input type="checkbox" class="custom-control-input" id="clear_' . e($n) . '" name="clear_' . e($n) . '" value="1"><label class="custom-control-label small font-weight-normal" for="clear_' . e($n) . '">Remove saved value</label></div>';
            }
            return '<div class="form-group"><label for="f-' . e($n) . '">' . e($f['label']) . '</label>' . $h . $help . '</div>';
        case 'select':
            $o = '';
            if ($v !== null && $v !== '' && !isset($f['options'][$v])) {
                $o .= '<option value="' . e($v) . '" selected>' . e($v) . ' (current)</option>';
            }
            foreach ($f['options'] as $k => $l) {
                $o .= '<option value="' . e($k) . '"' . ((string) $v === (string) $k ? ' selected' : '') . '>' . e($l) . '</option>';
            }
            return '<div class="form-group"><label for="f-' . e($n) . '">' . e($f['label']) . '</label><select name="' . e($n) . '" id="f-' . e($n) . '" class="custom-select">' . $o . '</select>' . $help . '</div>';
        case 'switch':
            return '<div class="form-group"><input type="hidden" name="' . e($n) . '_present" value="1"><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="f-' . e($n) . '" name="' . e($n) . '" value="1"' . ((string) $v === '1' ? ' checked' : '') . '><label class="custom-control-label font-weight-normal" for="f-' . e($n) . '">' . e($f['label']) . '</label></div>' . $help . '</div>';
        case 'checkboxes':
            $picked = array_filter(explode(',', (string) $v));
            $o = '';
            foreach ($f['options'] as $k => $l) {
                $o .= '<div class="col-sm-6"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="f-' . e($n . '-' . $k) . '" name="' . e($n) . '[]" value="' . e($k) . '"' . (in_array((string) $k, $picked, true) ? ' checked' : '') . '><label class="custom-control-label font-weight-normal" for="f-' . e($n . '-' . $k) . '">' . e($l) . '</label></div></div>';
            }
            return '<div class="form-group"><label>' . e($f['label']) . '</label><input type="hidden" name="' . e($n) . '_present" value="1"><div class="row small">' . $o . '</div>' . $help . '</div>';
        case 'number':
            $in = '<input type="number" step="any" id="f-' . e($n) . '" name="' . e($n) . '" class="form-control" value="' . e($v) . '"' . (isset($f['min']) ? ' min="' . e($f['min']) . '"' : '') . (isset($f['max']) ? ' max="' . e($f['max']) . '"' : '') . '>';
            if (!empty($f['suffix'])) {
                $in = '<div class="input-group" style="max-width:260px">' . $in . '<div class="input-group-append"><span class="input-group-text">' . e($f['suffix']) . '</span></div></div>';
            }
            return '<div class="form-group"><label for="f-' . e($n) . '">' . e($f['label']) . '</label>' . $in . $help . '</div>';
        default:
            $warn = $f['type'] === 'url' && str_starts_with(strtolower((string) $v), 'http://') ? '<small class="text-danger"><i class="fas fa-triangle-exclamation mr-1"></i>Not encrypted: the API key crosses the network in plain text.</small>' : '';
            return '<div class="form-group"><label for="f-' . e($n) . '">' . e($f['label']) . '</label><input type="' . ($f['type'] === 'url' ? 'url' : 'text') . '" id="f-' . e($n) . '" name="' . e($n) . '" class="form-control" value="' . e($v) . '" placeholder="' . e($f['placeholder'] ?? '') . '" autocomplete="off">' . $warn . $help . '</div>';
    }
};
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

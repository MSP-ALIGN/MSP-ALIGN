<?php
use Align\Onboarding\Requests;

/**
 * One online request form (new user / termination). @var string $kind, $action; bool $askName (ask for the submitter's name/email); ?array $by (known submitter)
 */
[$title, $icon, $introText, $fields] = Requests::FORMS[$kind];
$askName ??= true;
$field = function (array $f) use ($kind) {
    [$name, $label, $type, $req, $help] = $f;
    $id = 'rq-' . $kind . '-' . $name;
    if ($type === 'check') {
        return '<div class="custom-control custom-checkbox mb-2"><input type="checkbox" class="custom-control-input" id="' . $id . '" name="' . $name . '" value="1" checked><label class="custom-control-label" for="' . $id . '">' . e($label) . '</label></div>';
    }
    if ($type === 'access') {
        $row = fn(int $i) => '<div class="form-row mb-1" data-access-row><div class="col-7"><input class="form-control form-control-sm" name="' . $name . '[' . $i . '][who]" placeholder="Name or email" aria-label="Who"></div>'
            . '<div class="col-5"><select class="custom-select custom-select-sm" name="' . $name . '[' . $i . '][type]" aria-label="Access"><option value="forward">Forward email</option><option value="full">Full mailbox access</option></select></div></div>';
        return '<div class="form-group"><label>' . e($label) . '</label><div data-access-list>' . $row(0) . $row(1) . '</div>'
            . '<button type="button" class="btn btn-xs btn-link px-0" data-access-add>Add another person</button>' . ($help ? '<small class="form-text text-muted">' . e($help) . '</small>' : '') . '</div>';
    }
    $attr = ' id="' . $id . '" name="' . $name . '"' . ($req ? ' required' : '');
    $input = match ($type) {
        'textarea' => '<textarea class="form-control" rows="2"' . $attr . '></textarea>',
        'date' => '<input type="date" class="form-control"' . $attr . '>',
        'datetime' => '<input type="datetime-local" class="form-control"' . $attr . '>',
        default => '<input type="text" class="form-control" maxlength="190"' . $attr . '>',
    };
    return '<div class="form-group ' . ($type === 'textarea' ? 'col-12' : 'col-md-6') . '"><label for="' . $id . '">' . e($label) . ($req ? ' <span class="text-danger">*</span>' : '') . '</label>' . $input
        . ($help ? '<small class="form-text text-muted">' . e($help) . '</small>' : '') . '</div>';
};
?>
<form method="post" action="<?= e($action) ?>" class="card card-body bg-light mt-2 request-form">
  <?= csrf_field() ?>
  <h3 class="h6"><i class="fas <?= e($icon) ?> mr-1"></i><?= e($title) ?></h3>
  <p class="small text-muted"><?= e($introText) ?></p>
  <div class="form-row">
    <?php foreach ($fields as $f): if (in_array($f[2], ['check', 'access'], true)) continue; ?><?= $field($f) ?><?php endforeach; ?>
  </div>
  <?php foreach ($fields as $f): if (!in_array($f[2], ['check', 'access'], true)) continue; ?><?= $field($f) ?><?php endforeach; ?>
  <div class="form-row align-items-end border-top pt-2 mt-1">
    <?php if ($askName): ?>
      <div class="form-group col-md-4 mb-2"><label class="small" for="rq-<?= $kind ?>-by">Your name <span class="text-danger">*</span></label><input id="rq-<?= $kind ?>-by" class="form-control" name="by_name" required maxlength="120"></div>
      <div class="form-group col-md-4 mb-2"><label class="small" for="rq-<?= $kind ?>-em">Your email</label><input id="rq-<?= $kind ?>-em" type="email" class="form-control" name="by_email"></div>
    <?php endif; ?>
    <div class="form-group <?= $askName ? 'col-md-4' : 'col-12' ?> mb-2 text-md-right"><button class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i>Send request</button></div>
  </div>
  <p class="small text-muted mb-0">Only people who can approve changes should send these. We never ask for passwords here; we'll set a temporary password and share it securely.</p>
</form>

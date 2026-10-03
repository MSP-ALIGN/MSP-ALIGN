<?php
use Align\Contracts\Contracts;
use Align\Contracts\Template;

/**
 * A draft contract: fill in what you know, see it as the client will, then send it.
 * @var array $c, $clients, $problems, $counts, $providerFields, $clientFields, $me; string $preview; bool $mailReady, $templateNewer
 */
$id = (int) $c['id'];
$def = $c['def'];
$vals = $c['vals'];
$v = Contracts::values($c);
$cur = strtoupper((string) \Align\Settings::get('locale_currency', 'USD'));
$sign = $def['signing'];
$input = function (array $f) use ($vals) {
    $n = 'f[' . e($f['key']) . ']';
    $val = (string) ($vals['f'][$f['key']] ?? '');
    $id = 'pf-' . e($f['key']);
    $req = $f['required'] ? ' required' : '';
    return match ($f['type']) {
        'longtext' => '<textarea id="' . $id . '" name="' . $n . '" class="form-control" rows="3" maxlength="4000"' . $req . '>' . e($val) . '</textarea>',
        'date' => '<input type="date" id="' . $id . '" name="' . $n . '" class="form-control" value="' . e($val) . '"' . $req . '>',
        'number' => '<input type="number" step="any" id="' . $id . '" name="' . $n . '" class="form-control" value="' . e($val) . '"' . $req . '>',
        'money' => '<div class="input-group"><span class="input-group-text">' . e(\Align\Fmt::symbol()) . '</span><input type="number" step="any" min="0" id="' . $id . '" name="' . $n . '" class="form-control" value="' . e($val) . '"' . $req . '></div>',
        'email' => '<input type="email" id="' . $id . '" name="' . $n . '" class="form-control" value="' . e($val) . '"' . $req . '>',
        'phone' => '<input type="tel" id="' . $id . '" name="' . $n . '" class="form-control" value="' . e($val) . '"' . $req . '>',
        'choice' => '<select id="' . $id . '" name="' . $n . '" class="form-select"' . $req . '><option value="">Choose…</option>' . implode('', array_map(fn($o) => '<option' . ($o === $val ? ' selected' : '') . '>' . e($o) . '</option>', $f['options'])) . '</select>',
        'checkbox' => '<div class="form-check"><input type="hidden" name="' . $n . '" value=""><input class="form-check-input" type="checkbox" id="' . $id . '" name="' . $n . '" value="1"' . ($val === '1' ? ' checked' : '') . '><label class="form-check-label" for="' . $id . '">Yes</label></div>',
        default => '<input type="text" id="' . $id . '" name="' . $n . '" class="form-control" maxlength="500" value="' . e($val) . '"' . $req . '>',
    };
};
?>
<div class="small"><a href="/contracts?show=draft">Contracts</a> / <?= e(Contracts::number($c)) ?></div>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-file-signature text-secondary me-2"></i><?= e($c['title']) ?> <span class="badge text-bg-secondary align-middle">Draft</span></h1>
  <a class="btn btn-sm btn-default me-1" href="/contracts/<?= $id ?>/pdf" target="_blank" rel="noopener" title="As last saved"><i class="fas fa-file-pdf me-1"></i>Draft PDF</a>
  <button class="btn btn-sm btn-default me-1" form="prep-form" name="then" value="save"><i class="fas fa-floppy-disk me-1"></i>Save draft</button>
  <button class="btn btn-sm btn-primary" form="prep-form" name="then" value="send"><i class="fas fa-paper-plane me-1"></i>Send for signature…</button>
  <div class="btn-group ms-1">
    <button class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown" aria-label="More actions"><i class="fas fa-ellipsis"></i></button>
    <div class="dropdown-menu dropdown-menu-end">
      <form method="post" action="/contracts/<?= $id ?>/delete"><?= csrf_field() ?><button class="dropdown-item text-danger" data-confirm="Delete this draft?" data-confirm-ok="Delete"><i class="fas fa-fw fa-trash me-1"></i>Delete draft</button></form>
    </div>
  </div>
</div>
<?php if ($templateNewer): ?>
  <div class="alert alert-light border py-2 small"><i class="fas fa-circle-info me-1"></i>The template "<?= e($c['template_name']) ?>" has changed since this draft was made. This draft keeps the wording it was made with; make a new contract to use the latest version.</div>
<?php endif; ?>

<div class="row">
  <div class="col-xl-5">
    <form method="post" action="/contracts/<?= $id ?>" id="prep-form" data-unsaved data-ct-prepare data-preview="/contracts/<?= $id ?>/preview">
      <?= csrf_field() ?>
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-building me-1"></i>Who it's for</h3></div>
        <div class="card-body">
          <div class="mb-3"><label for="p-title">Contract title</label><input id="p-title" name="title" class="form-control" maxlength="190" value="<?= e($c['title']) ?>"></div>
          <div class="mb-3"><label for="p-client">Client</label>
            <select id="p-client" name="client_id" class="form-select">
              <option value="">A new client (not in Align yet)</option>
              <?php foreach ($clients as $cl): ?><option value="<?= (int) $cl['id'] ?>" <?= (int) $c['client_id'] === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div data-show-when="client_id=">
            <div class="mb-3"><label for="p-lead">Company name</label><input id="p-lead" name="lead_company" class="form-control" maxlength="190" value="<?= e((string) $c['lead_company']) ?>"></div>
            <div class="row g-2">
              <div class="col-sm-7 mb-3"><label for="p-addr">Address</label><textarea id="p-addr" name="lead_address" class="form-control" rows="2" maxlength="500"><?= e((string) $c['lead_address']) ?></textarea></div>
              <div class="col-sm-5 mb-3"><label for="p-phone">Phone</label><input id="p-phone" name="lead_phone" class="form-control" maxlength="60" value="<?= e((string) $c['lead_phone']) ?>"></div>
            </div>
          </div>
          <div class="row g-2">
            <div class="col-sm-6 mb-2"><label for="p-sname">Who signs</label><input id="p-sname" name="signer_name" class="form-control" maxlength="190" value="<?= e((string) $c['signer_name']) ?>" placeholder="Full name"></div>
            <div class="col-sm-6 mb-2"><label for="p-stitle">Their title</label><input id="p-stitle" name="signer_title" class="form-control" maxlength="190" value="<?= e((string) $c['signer_title']) ?>" placeholder="e.g. Owner"></div>
            <div class="col-12 mb-2"><label for="p-semail">Their email</label><input type="email" id="p-semail" name="signer_email" class="form-control" maxlength="190" value="<?= e((string) $c['signer_email']) ?>" placeholder="Where the signing link goes"></div>
          </div>
          <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="p-code" name="verify_code" value="1" <?= $c['verify_code'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="p-code">Ask for a code emailed to them before they can sign</label>
            <div class="form-text">Confirms it's them if the link is forwarded.<?= $mailReady ? '' : ' Needs email set up (Integrations → Email); without it, the link alone is used.' ?></div></div>
        </div>
      </div>

      <?php if ($def['sections']): ?>
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-layer-group me-1"></i>Sections</h3></div>
        <div class="card-body">
          <input type="hidden" name="sec_present" value="1">
          <?php foreach ($def['sections'] as $s): $on = !empty($vals['sec'][$s['key']] ?? $s['on']); ?>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="ps-<?= e($s['key']) ?>" name="sec[<?= e($s['key']) ?>]" value="1" <?= $on ? 'checked' : '' ?>>
              <label class="form-check-label" for="ps-<?= e($s['key']) ?>"><?= e($s['label']) ?></label></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-pen-to-square me-1"></i>Details</h3></div>
        <div class="card-body">
          <?php $printsStart = Contracts::printsStart($c); ?>
          <div class="mb-3"><label for="p-start">Contract start date<?= $printsStart ? ' <span class="text-danger" title="Required">*</span>' : '' ?></label>
            <input type="date" id="p-start" name="start" class="form-control" style="max-width:14rem" value="<?= e((string) Contracts::start($c)) ?>"<?= $printsStart ? ' required' : '' ?>>
            <div class="form-text"><?= $printsStart ? 'Printed on the contract. ' : '' ?>Its renewal dates count from here.</div></div>
          <?php foreach ($providerFields as $f): ?>
            <div class="mb-3"><label for="pf-<?= e($f['key']) ?>"><?= e($f['label']) ?><?= $f['required'] ? ' <span class="text-danger" title="Required">*</span>' : '' ?></label>
              <?= $input($f) ?><?= $f['help'] ? '<div class="form-text">' . e($f['help']) . '</div>' : '' ?></div>
          <?php endforeach; ?>
          <?php if ($clientFields): ?>
            <div class="small text-muted"><i class="fas fa-user-pen me-1"></i>The client fills in when signing: <?= e(implode(', ', array_column($clientFields, 'label'))) ?>.</div>
          <?php endif; ?>
        </div>
      </div>

      <?php $hasServices = !empty($def['pdf']) ? (bool) $def['services']['rows'] : (bool) array_filter(Contracts::blocks($def, $vals), fn($b) => $b['type'] === 'services'); if ($hasServices): ?>
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-ol me-1"></i><?= e($def['services']['title']) ?></h3></div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0 ct-svc">
            <thead><tr><th class="ps-3">Service</th><th style="width:7rem">Qty</th><th style="width:8rem">Price</th></tr></thead>
            <tbody>
            <?php foreach ($def['services']['rows'] as $r): $s = $vals['svc'][$r['key']] ?? ['qty' => $r['qty'], 'price' => $r['price'], 'on' => !$r['optional']]; $k = e($r['key']); ?>
              <tr>
                <td class="ps-3"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="sv-<?= $k ?>" name="svc[<?= $k ?>][on]" value="1" <?= !empty($s['on']) ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="sv-<?= $k ?>"><?= e($r['label']) ?></label></div>
                  <div class="small text-muted"><?= e(Template::PERIODS[$r['period']]) ?><?= $r['unit'] ? ', ' . e($r['unit']) : '' ?></div>
                  <?php if ($r['auto'] && $c['client_id']): $n = (int) ($counts[$r['auto']] ?? 0); ?>
                    <div class="small"><span class="text-muted">Align counts <?= $n ?>:</span> <?= e(mb_strtolower(Template::AUTO[$r['auto']])) ?><?php if ((float) $s['qty'] !== (float) $n): ?> <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-ct-use="#sq-<?= $k ?>" data-value="<?= $n ?>">use <?= $n ?></button><?php endif; ?></div>
                  <?php elseif ($r['auto']): ?>
                    <div class="small text-muted">Counted for you on existing clients (<?= e(mb_strtolower(Template::AUTO[$r['auto']])) ?>).</div>
                  <?php endif; ?></td>
                <td><input type="number" step="any" min="0" id="sq-<?= $k ?>" name="svc[<?= $k ?>][qty]" class="form-control form-control-sm" value="<?= e(rtrim(rtrim(number_format((float) $s['qty'], 2, '.', ''), '0'), '.')) ?>" aria-label="Quantity of <?= e($r['label']) ?>"></td>
                <td><input type="number" step="any" min="0" name="svc[<?= $k ?>][price]" class="form-control form-control-sm" value="<?= e(rtrim(rtrim(number_format((float) $s['price'], 2, '.', ''), '0'), '.')) ?>" aria-label="Price of <?= e($r['label']) ?>"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tbody data-ct-extra>
            <?php foreach (array_merge($vals['extra'] ?? [], [['label' => '', 'description' => '', 'qty' => 1, 'price' => '', 'period' => 'month', 'blank' => true]]) as $i => $x): ?>
              <tr data-ct-extra-row<?= !empty($x['blank']) ? ' data-ct-extra-blank' : '' ?>>
                <td class="ps-3"><input name="extra[<?= $i ?>][label]" class="form-control form-control-sm mb-1" maxlength="120" value="<?= e($x['label']) ?>" placeholder="Add a line (e.g. Network upgrade)" aria-label="Extra line">
                  <div class="d-flex"><input name="extra[<?= $i ?>][description]" class="form-control form-control-sm me-1" maxlength="300" value="<?= e($x['description'] ?? '') ?>" placeholder="Description" aria-label="Description">
                  <select name="extra[<?= $i ?>][period]" class="form-select form-select-sm" style="width:auto" aria-label="Billed"><?php foreach (Template::PERIODS as $pk => $pl): ?><option value="<?= $pk ?>" <?= $x['period'] === $pk ? 'selected' : '' ?>><?= $pl ?></option><?php endforeach; ?></select></div></td>
                <td><input type="number" step="any" min="0" name="extra[<?= $i ?>][qty]" class="form-control form-control-sm" value="<?= e((string) $x['qty']) ?>" aria-label="Quantity"></td>
                <td><input type="number" step="any" name="extra[<?= $i ?>][price]" class="form-control form-control-sm" value="<?= e((string) $x['price']) ?>" aria-label="Price (below 0 for a discount)" title="Below 0 for a discount"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <div class="small text-muted px-3 py-2">Untick a service to leave it out. Add a line with a price below 0 for a discount. Prices in <?= e($cur) ?>; totals are in the preview.</div>
        </div>
      </div>
      <?php endif; ?>
      <div class="text-end mb-3">
        <button class="btn btn-default me-1" name="then" value="save"><i class="fas fa-floppy-disk me-1"></i>Save draft</button>
        <button class="btn btn-primary" name="then" value="send"><i class="fas fa-paper-plane me-1"></i>Send for signature…</button>
      </div>
    </form>
  </div>
  <div class="col-xl-7">
    <div class="ct-preview-wrap">
      <div class="d-flex align-items-center small text-muted mb-1"><span class="me-auto"><i class="fas fa-eye me-1"></i>What the client sees</span><span data-ct-preview-state></span></div>
      <div class="ct-paper" data-ct-preview><?= $preview ?></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-send" tabindex="-1" aria-hidden="true" data-open-on-hash="send">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/contracts/<?= $id ?>/send">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-paper-plane me-2"></i>Send for signature</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($problems): ?>
            <div class="alert alert-warning mb-0"><b>Before it can be sent:</b><ul class="mb-0 mt-1"><?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul></div>
          <?php else: ?>
            <p class="mb-1">To <b><?= e((string) $c['signer_name']) ?></b> &lt;<?= e((string) $c['signer_email']) ?>&gt;, <?= e(Contracts::party($c)) ?></p>
            <p class="small text-muted mb-2">The link works for <?= (int) $sign['link_days'] ?> days<?= $c['verify_code'] && $mailReady ? ', with a code emailed to them' : '' ?><?= $sign['countersign'] === 'after' ? '. Once they sign, you countersign to complete it' : '' ?>.</p>
            <div class="mb-2"><label for="sd-subject">Subject</label><input id="sd-subject" name="subject" class="form-control" maxlength="255" value="<?= e(Contracts::fillText($sign['subject'] ?: Contracts::DEFAULT_SUBJECT, $v)) ?>"></div>
            <div class="mb-2"><label for="sd-message">Message</label><textarea id="sd-message" name="message" class="form-control" rows="6" maxlength="6000"><?= e(Contracts::fillText($sign['message'] ?: Contracts::DEFAULT_MESSAGE, $v)) ?></textarea>
              <div class="form-text">A <b>Review and sign</b> button is added below your message.</div></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="sd-cc" name="cc_me" value="1"><label class="form-check-label" for="sd-cc">Send me a copy (<?= e((string) $me['email']) ?>)</label></div>
            <?php if ($sign['countersign'] === 'before'): ?>
              <hr><h6 class="mb-2">Your signature <span class="text-muted small fw-normal">(this template has you sign first)</span></h6>
              <?= \Align\View::fetch('contracts/_sigpad', ['p' => 'ps', 'sigName' => (string) $me['name'], 'sigTitle' => '', 'company' => Contracts::party($c),
                  'photo' => $def['style']['photo'] ? avatar_url($me) : null]) ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light me-auto" data-bs-dismiss="modal">Cancel</button>
          <?php if (!$problems): ?>
            <button class="btn btn-default" name="action" value="link" formnovalidate title="Make the link to paste into your own email"><i class="fas fa-link me-1"></i>Create link only</button>
            <?php if ($mailReady): ?><button class="btn btn-primary" name="action" value="send"><i class="fas fa-paper-plane me-1"></i>Send</button><?php endif; ?>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

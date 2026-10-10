<?php
use Align\Vendors\Vendors;

/**
 * 2.8.0 The client vendor window, for techs and admins.
 * @var ?array $v vendor as Vendors::one() gives it (null = new); int $cid client id; array $templates Vendors::templates();
 *      string $back return path; ?string $preset a vendor name to fill in for a new one (from a license's vendor)
 * A vendor from the PSA has its details read-only (the PSA owns them); its template, category, services and Align
 * notes stay editable. With a template, a blank shared field uses the template's (shown as the placeholder).
 * Every value is escaped; $back is checked again by the controller.
 */
$v = $v ?? null;
$edit = $v !== null;
$fromPsa = $edit && $v['source'] === 'psa';
$id = $edit ? 'modal-vendor-' . (int) $v['id'] : 'modal-vendor';
$ro = $fromPsa ? 'readonly' : '';
$tag = $fromPsa ? ' <span class="badge text-bg-light border fw-normal" title="Managed in ' . e(psa_name()) . '">' . e(psa_name()) . '</span>' : '';
$own = fn(string $k) => $edit ? (in_array($k, Vendors::SHARED, true) ? ($v['own'][$k] ?? '') : ($v[$k] ?? '')) : '';
// a shared field's placeholder: the template's value (what a blank shows)
$ph = fn(string $k, string $fallback) => $edit && ($v["t_$k"] ?? '') !== '' && !$fromPsa ? 'Template: ' . $v["t_$k"] : $fallback;
$field = function (string $k, string $label, string $placeholder = '', string $type = 'text', string $col = 'col-md-6') use ($own, $ph, $ro, $tag, $id): string {
    return '<div class="mb-3 ' . $col . '"><label for="' . $id . '-' . $k . '">' . e($label) . $tag . '</label><input type="' . $type . '" id="' . $id . '-' . $k . '" name="' . $k
        . '" class="form-control" value="' . e((string) $own($k)) . '" placeholder="' . e($ph($k, $placeholder)) . '" maxlength="' . (Vendors::SIZES[$k] ?? 190) . '" ' . $ro . '></div>';
};
?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= $edit ? '/vendors/' . (int) $v['id'] : '/clients/' . (int) $cid . '/vendors' ?>" data-unsaved>
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-store me-2"></i><?= $edit ? 'Edit ' . e($v['name']) : 'Add vendor' ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($fromPsa): ?>
            <div class="alert alert-light border small py-2"><i class="fas fa-circle-info me-1"></i>This vendor comes from <?= e(psa_name()) ?>. Its name, account number, contact and support details update from <?= e(psa_name()) ?> every few minutes, so change those there. The template, category, services and Align notes are kept in Align.</div>
          <?php endif; ?>
          <div class="row g-2">
            <div class="mb-3 col-md-6"><label for="<?= $id ?>-template">Template</label>
              <select id="<?= $id ?>-template" name="template_id" class="form-select">
                <option value="">None (this client only)</option>
                <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $edit && (int) $v['template_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
              </select>
              <div class="form-text">Shared details (support line, website, hours) come from the template; fill in a field below only where this client's differs.</div></div>
            <div class="mb-3 col-md-6"><label for="<?= $id ?>-category">Category</label>
              <select id="<?= $id ?>-category" name="category" class="form-select">
                <option value=""><?= $edit && $v['template_id'] ? 'Template\'s (' . e(Vendors::CATEGORIES[$v['t_category']][0] ?? 'Other') . ')' : 'Template\'s, or a guess from the name' ?></option>
                <?php foreach (Vendors::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $edit && ($v['own']['category'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="row g-2">
            <?php $nameVal = $edit ? $own('name') : ($preset ?? ''); ?>
            <div class="mb-3 col-md-6"><label for="<?= $id ?>-name">Name<?= $tag ?></label><input id="<?= $id ?>-name" name="name" class="form-control" value="<?= e((string) $nameVal) ?>" placeholder="<?= e($ph('name', 'Comcast Business, GoDaddy, Adobe… (blank: the template\'s)')) ?>" maxlength="190" <?= $ro ?>></div>
            <?= $field('account_number', 'Account number', 'The client\'s account with this vendor') ?>
          </div>
          <div class="row g-2">
            <?= $field('contact_name', 'Contact / rep', 'Account rep or main contact') ?>
            <?= $field('support_phone', 'Support phone', '', 'text') ?>
          </div>
          <div class="row g-2">
            <?= $field('support_email', 'Support email', '', 'text') ?>
            <?= $field('website', 'Website / portal', 'https://…', 'text') ?>
          </div>
          <div class="row g-2">
            <?= $field('hours', 'Support hours', '24/7, M-F 8-5…') ?>
            <?= $field('sla', 'SLA / response time', '4 hours, next business day…') ?>
          </div>
          <?php if ($fromPsa && ($v['description'] ?? '') !== ''): ?><div class="mb-3"><label>Description<?= $tag ?></label><input class="form-control" value="<?= e($v['description']) ?>" readonly></div><?php endif; ?>
          <div class="mb-3"><label for="<?= $id ?>-services">Services <small class="text-muted">(what the client has)</small></label><input id="<?= $id ?>-services" name="services" class="form-control" value="<?= e($v['services'] ?? '') ?>" placeholder="200 Mbps fiber, 5 static IPs · 3 domains · Creative Cloud for teams…" maxlength="500"></div>
          <?php if ($fromPsa): ?>
            <?php if (($v['notes'] ?? '') !== ''): ?><div class="mb-3"><label><?= e(psa_name()) ?> notes<?= $tag ?></label><textarea class="form-control" rows="2" readonly><?= e($v['notes']) ?></textarea></div><?php endif; ?>
            <div class="mb-0"><label for="<?= $id ?>-align_notes">Notes <small class="text-muted">(Align)</small></label><textarea id="<?= $id ?>-align_notes" name="align_notes" class="form-control" rows="2" maxlength="5000"><?= e($v['align_notes'] ?? '') ?></textarea></div>
          <?php else: ?>
            <div class="mb-0"><label for="<?= $id ?>-notes">Notes</label><textarea id="<?= $id ?>-notes" name="notes" class="form-control" rows="2" maxlength="5000" placeholder="How to open a ticket, contract details… (never passwords or PINs)"><?= e($v['notes'] ?? '') ?></textarea></div>
          <?php endif; ?>
          <?php if ($edit && ($v['template_notes'] ?? '') !== ''): ?><div class="small text-muted mt-2"><i class="fas fa-layer-group me-1"></i>Template notes: <?= e($v['template_notes']) ?></div><?php endif; ?>
        </div>
        <div class="modal-footer">
          <?php if ($edit): ?>
            <?php if ($v['retired_at']): ?>
              <button class="btn btn-outline-success me-auto" name="action" value="restore" formnovalidate data-enter-skip><i class="fas fa-rotate-left me-1"></i>Restore</button>
            <?php else: ?>
              <button class="btn btn-outline-secondary me-auto" name="action" value="retire" formnovalidate data-confirm="Retire <?= e($v['name']) ?>? Its licenses stay linked; you can restore it."><i class="fas fa-box-archive me-1"></i>Retire</button>
            <?php endif; ?>
            <?php if (!$v['template_id']): ?><button class="btn btn-outline-primary me-2" name="action" value="template" formnovalidate data-confirm="Make a template from <?= e($v['name']) ?>'s name, category and support details, to add it to other clients?" data-confirm-danger="0" data-confirm-ok="Save as template"><i class="fas fa-layer-group me-1"></i>Save as template</button><?php endif; ?>
            <?php if (!$fromPsa): ?><button class="btn btn-outline-danger me-2" name="action" value="delete" formnovalidate data-confirm="Delete <?= e($v['name']) ?>? Its licenses keep the vendor name but aren't linked."><i class="fas fa-trash"></i></button><?php endif; ?>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" name="action" value="save"><i class="fas fa-check me-1"></i><?= $edit ? 'Save' : 'Add vendor' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

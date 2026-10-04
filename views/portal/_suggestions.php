<?php
/**
 * Portal: suggest a license or budget item (1.39), and see what was suggested. Everything waits for the IT
 * provider to review it; nothing changes the budget until they add it. Everything shown was typed by the client
 * (or the staff note) and is escaped; a missing data field (an older row) shows as empty.
 * @var string $kind license|budget; array $subs; bool $canSubmit; array $pu
 */
use Align\Budget\Budget;
use Align\Licensing\Licenses;
use Align\Portal\Submissions;

$isLic = $kind === 'license';
$what = $isLic ? 'license' : 'budget item';
$sym = e(\Align\Fmt::symbol());
?>
<?php if ($subs): ?>
<div class="card" id="suggestions">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-paper-plane me-2 text-secondary"></i>Suggestions from your organization</h3></div>
  <ul class="list-group list-group-flush">
    <?php foreach ($subs as $s): [$label, $tone] = Submissions::STATUS[$s['status']]; $d = $s['data']; ?>
      <li class="list-group-item py-2 d-flex flex-wrap align-items-center">
        <div class="me-auto pe-2">
          <b><?= e($s['title']) ?></b> <span class="badge text-bg-<?= $tone ?> portal-status align-middle"><?= e($label) ?></span>
          <div class="small text-muted">
            <?= e($isLic
                ? implode(' · ', array_filter([$d['vendor'] ?? null, ($d['seats'] ?? null) !== null ? $d['seats'] . ' ' . ($d['seats'] === 1 ? 'license' : 'licenses') : null, ($d['unit_price'] ?? null) !== null ? money_exact($d['unit_price']) . ' ' . strtolower(Licenses::CYCLES[$d['billing_cycle'] ?? ''][0] ?? '') : null]))
                : implode(' · ', array_filter([Budget::CATEGORIES[$d['category'] ?? ''][0] ?? null, money_exact($d['amount'] ?? 0) . ' ' . strtolower(Budget::FREQUENCIES[$d['frequency'] ?? ''][0] ?? '')]))) ?>
            · sent by <?= e($s['submitted_by_name']) ?> <?= e(rel_time($s['created_at'])) ?>
          </div>
          <?php if ($s['status'] === 'declined' && $s['decision_note']): ?><div class="small mt-1"><i class="fas fa-comment text-muted me-1"></i><?= e($s['decision_note']) ?></div><?php endif; ?>
          <?php if ($s['status'] === 'accepted'): ?><div class="small text-success mt-1"><i class="fas fa-check me-1"></i>Added to your <?= $isLic ? 'licensing' : 'budget' ?> <?= e(rel_time($s['decided_at'])) ?>, as your IT provider set it up.</div><?php endif; ?>
        </div>
        <?php if ($s['status'] === 'pending' && (int) $s['portal_user_id'] === (int) ($pu['id'] ?? 0)): ?>
          <form method="post" action="/portal/suggestions/<?= (int) $s['id'] ?>/withdraw" class="mt-1"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($kind) ?>">
            <button class="btn btn-xs btn-default" data-confirm="Withdraw “<?= e($s['title']) ?>”?">Withdraw</button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($canSubmit): ?>
<div class="modal fade" id="modal-suggest" tabindex="-1" aria-hidden="true" aria-labelledby="modal-suggest-title">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/portal/suggest/<?= e($kind) ?>">
      <?= csrf_field() ?>
      <div class="modal-header"><h5 class="modal-title" id="modal-suggest-title"><i class="fas fa-fw <?= $isLic ? 'fa-key' : 'fa-coins' ?> me-2 text-secondary"></i><?= $isLic ? 'Suggest a license' : 'Suggest a cost' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="small text-muted"><?= $isLic
            ? 'Something your organization pays for that isn\'t listed yet, such as practice software, a design app or a subscription someone signed up for. Fill in what you know; your IT provider checks it and adds it to your licensing.'
            : 'A technology cost your IT provider may not know about, such as an internet line, phone service, hosting or a service contract. Fill in what you know; your IT provider checks it and adds it to your budget.' ?></p>
        <div class="row g-2">
          <div class="mb-3 col-md-7"><label for="sg-name"><?= $isLic ? 'Product' : 'What it\'s for' ?></label><input id="sg-name" name="name" class="form-control" required maxlength="255" placeholder="<?= $isLic ? 'Adobe Acrobat Pro, QuickBooks Online…' : 'Fiber internet, phone service, website hosting…' ?>"></div>
          <div class="mb-3 col-md-5"><label for="sg-vendor"><?= $isLic ? 'Vendor or reseller' : 'Company you pay' ?> <small class="text-muted">(optional)</small></label><input id="sg-vendor" name="vendor" class="form-control" maxlength="190"></div>
        </div>
        <?php if ($isLic): ?>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label for="sg-cat">Kind of software</label><select id="sg-cat" name="category" class="form-select"><?php foreach (Licenses::CATEGORIES as $k => [$l]): ?><option value="<?= e($k) ?>" <?= $k === 'other' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3 col-md-4"><label for="sg-type">Licensed</label><select id="sg-type" name="license_type" class="form-select"><?php foreach (Licenses::TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3 col-md-4"><label for="sg-seats">How many <small class="text-muted">(optional)</small></label><input id="sg-seats" type="number" min="0" step="1" name="seats" class="form-control"></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label for="sg-price">Price <small class="text-muted">(optional)</small></label>
              <div class="input-group"><span class="input-group-text"><?= $sym ?></span><input id="sg-price" type="number" min="0" step="0.01" name="unit_price" class="form-control"></div></div>
            <div class="mb-3 col-md-4"><label for="sg-pricing">The price is</label><select id="sg-pricing" name="pricing" class="form-select"><option value="per_seat">For each license</option><option value="flat">For all of them</option></select></div>
            <div class="mb-3 col-md-4"><label for="sg-cycle">Billed</label><select id="sg-cycle" name="billing_cycle" class="form-select"><?php foreach (Licenses::CYCLES as $k => [$l]): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label for="sg-exp">Renews or expires <small class="text-muted">(optional)</small></label><input id="sg-exp" type="date" name="expire_date" class="form-control"></div>
          </div>
        <?php else: ?>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label for="sg-cat">Category</label><select id="sg-cat" name="category" class="form-select"><?php foreach (Submissions::CLIENT_BUDGET_CATEGORIES as $k): ?><option value="<?= e($k) ?>"><?= e(Budget::CATEGORIES[$k][0]) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3 col-md-4"><label for="sg-amount">Amount</label>
              <div class="input-group"><span class="input-group-text"><?= $sym ?></span><input id="sg-amount" type="number" min="0" step="0.01" name="amount" class="form-control" required></div></div>
            <div class="mb-3 col-md-4"><label for="sg-freq">How often</label><select id="sg-freq" name="frequency" class="form-select"><?php foreach (Budget::FREQUENCIES as $k => [$l]): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="row g-2">
            <div class="mb-3 col-md-4"><label for="sg-start">Starting <small class="text-muted">(optional)</small></label><input id="sg-start" type="date" name="start_date" class="form-control"></div>
          </div>
        <?php endif; ?>
        <div class="mb-3 mb-0"><label for="sg-notes">Anything else <small class="text-muted">(optional)</small></label><textarea id="sg-notes" name="notes" class="form-control" rows="2" maxlength="2000" placeholder="Who uses it, the contract length, why it's needed…"></textarea></div>
      </div>
      <div class="modal-footer">
        <span class="small text-muted me-auto">Shows as waiting until your IT provider reviews it.</span>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Send</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

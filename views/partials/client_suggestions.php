<?php
/**
 * Licenses or budget items the client suggested in the portal (1.39), waiting for staff. "Review and add"
 * opens the usual Add form filled in from the suggestion; "Decline" sends an optional note to the client.
 * @var array $subs; string $kind (license|budget); int $cid; string $back
 */
use Align\Budget\Budget;
use Align\Licensing\Licenses;

if (!$subs || !\Align\Auth::can('tech')) {
    return;
}
$detail = function (array $d) use ($kind): string {
    if ($kind === 'license') {
        $price = $d['unit_price'] !== null ? money_exact($d['unit_price']) . ($d['pricing'] === 'per_seat' ? ' each' : '') . ' ' . strtolower(Licenses::CYCLES[$d['billing_cycle']][0] ?? '') : 'no price given';
        return implode(' · ', array_filter([$d['vendor'] ?? null, Licenses::TYPES[$d['license_type']] ?? null, $d['seats'] !== null ? $d['seats'] . ' seats' : null, $price,
            !empty($d['expire_date']) ? 'renews ' . fmt_date($d['expire_date']) : null]));
    }
    return implode(' · ', array_filter([Budget::CATEGORIES[$d['category']][0] ?? null, $d['vendor'] ?? null,
        money_exact($d['amount'] ?? 0) . ' ' . strtolower(Budget::FREQUENCIES[$d['frequency']][0] ?? ''), !empty($d['start_date']) ? 'from ' . fmt_date($d['start_date']) : null]));
};
?>
<div class="card card-outline card-warning" id="client-submissions">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-inbox mr-2 text-warning"></i>Suggested by the client <span class="badge badge-warning ml-1"><?= count($subs) ?></span></h3></div>
  <div class="card-body p-0">
    <div class="px-3 pt-2 small text-muted">Sent from the client portal and waiting for you. Nothing is added until you review it; the client sees it as waiting.</div>
    <ul class="list-group list-group-flush">
      <?php foreach ($subs as $s): $d = $s['data']; ?>
        <li class="list-group-item">
          <div class="d-flex flex-wrap align-items-start">
            <div class="mr-auto pr-2">
              <b><?= e($s['title']) ?></b>
              <div class="small text-muted"><?= e($detail($d)) ?></div>
              <?php if (!empty($d['notes'])): ?><div class="small mt-1">“<?= e($d['notes']) ?>”</div><?php endif; ?>
              <div class="small text-muted mt-1"><?= e($s['submitted_by_name']) ?> · <?= e(rel_time($s['created_at'])) ?></div>
            </div>
            <div class="text-nowrap mt-1">
              <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-suggestion-<?= (int) $s['id'] ?>"><i class="fas fa-check mr-1"></i>Review and add</button>
              <button class="btn btn-sm btn-default" data-toggle="collapse" data-target="#decline-<?= (int) $s['id'] ?>" aria-expanded="false">Decline</button>
            </div>
          </div>
          <form method="post" action="/clients/<?= $cid ?>/suggestions/<?= (int) $s['id'] ?>/decline" class="collapse mt-2" id="decline-<?= (int) $s['id'] ?>">
            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
            <div class="input-group input-group-sm">
              <input name="note" class="form-control" maxlength="2000" placeholder="Note for the client (optional): why, or what you'll do instead" aria-label="Note for the client">
              <div class="input-group-append"><button class="btn btn-outline-danger">Decline</button></div>
            </div>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php foreach ($subs as $s):
    $pre = \Align\Portal\Submissions::prefill($s);
    echo $kind === 'license' ? \Align\View::fetch('licenses/_modal', ['l' => $pre, 'cid' => $cid, 'back' => $back])
        : \Align\View::fetch('budget/_modal', ['m' => $pre, 'cid' => $cid, 'back' => $back]);
endforeach; ?>

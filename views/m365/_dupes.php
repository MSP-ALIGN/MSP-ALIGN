<?php
/**
 * 2.6.0 The one-time offer to retire licenses that Microsoft 365 now counts (from the PSA or added by hand), on a
 * client's Licensing page (2.6.1: moved out of the Microsoft 365 card, which is on the Connectors page).
 * @var array $client; array $dupes Tenants::dupes() (only before the check is done)
 * 2.6.3: also for Google Workspace (Clients::dupes): optional string $kind 'gws' (default 'm365') picks the form's
 * address and the wording.
 * Security: names come from the PSA or staff and are escaped; the form posts with CSRF and M365Controller::dupes
 * checks the role and that each id is one of this client's offered licenses.
 */
use Align\Auth;

$cid = (int) $client['id'];
$tech = Auth::can('tech');
$kind = ($kind ?? 'm365') === 'gws' ? 'gws' : 'm365';
[$from, $what] = $kind === 'gws' ? ['Google Workspace', 'Google Workspace editions'] : ['Microsoft 365', 'Microsoft subscriptions'];
?>
<?php if ($dupes && $tech): // the one-time duplicate check after connecting ?>
<form method="post" action="/clients/<?= $cid ?>/<?= $kind ?>/dupes" class="card card-outline card-warning" id="<?= $kind ?>-dupes">
  <?= csrf_field() ?>
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-clone me-2 text-warning"></i>Counted twice?</h3></div>
  <div class="card-body py-2 small">
    <p class="mb-2">These licenses look like <?= $what ?> that <?= $from ?> now counts. Retire the ones that are the same, so their cost isn't counted twice:</p>
    <?php foreach ($dupes as $d): ?>
      <div class="form-check"><input type="checkbox" class="form-check-input" name="retire[]" value="<?= (int) $d['id'] ?>" id="dupe-<?= $kind ?>-<?= (int) $d['id'] ?>" checked>
        <label class="form-check-label fw-normal" for="dupe-<?= $kind ?>-<?= (int) $d['id'] ?>"><?= e($d['name']) ?> <span class="text-muted">(<?= $d['source'] === 'psa' ? e(psa_name()) : 'added in Align' ?><?= $d['seats'] !== null ? ', ' . (int) $d['seats'] . ' seats' : '' ?>)</span></label></div>
    <?php endforeach; ?>
    <div class="mt-2"><button class="btn btn-sm btn-warning">Retire the ticked ones</button>
      <button class="btn btn-sm btn-link" name="keep" value="1" formnovalidate>Keep them all</button></div>
    <?php if (array_filter($dupes, fn($x) => $x['source'] === 'psa')): ?><p class="text-muted mt-2 mb-0">Retired here only: archive them in <?= e(psa_name()) ?> too.</p><?php endif; ?>
  </div>
</form>
<?php endif; ?>

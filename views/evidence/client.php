<?php
/**
 * 2.18.0 A client's evidence check. Two views: the questionnaire (each automatic security check's rule, answered from
 * the client's own systems; Align's results are never on this page, only whether Align can read that system) and,
 * with ?results, the newest answers next to Align's result at that moment.
 * @var array $client; bool $results; array $checks (key => label, system, known); array $latest (Evidence::latest());
 *      array $current (Evidence::current(), results view only); array $usedBy (key => where it's used)
 * Every value is escaped; the form posts with CSRF to EvidenceController::save (techs).
 */
use Align\Compliance\Evidence;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
// Systems Align can read for this client (at least one check with a result); the others are left out
$systems = [];
foreach ($checks as $k => $c) {
    $systems[$c['system']][$k] = $c;
}
$systems = array_filter($systems, fn($cs) => array_filter($cs, fn($c) => $c['known']));
$tone = ['agree' => 'success', 'disagree' => 'danger', 'unsure' => 'secondary', 'no_result' => 'warning'];
$word = ['agree' => 'Agree', 'disagree' => 'Disagree', 'unsure' => 'You couldn\'t tell', 'no_result' => 'Align had no result'];
$res = ['pass' => 'True', 'fail' => 'Not true', 'unknown' => 'No result', 'unsure' => 'Can\'t tell'];
// Results in the questionnaire's order (system, then check)
$order = array_flip(array_keys($checks));
usort($latest, fn($a, $b) => ($order[$a['check_key']] ?? PHP_INT_MAX) <=> ($order[$b['check_key']] ?? PHP_INT_MAX));
?>
<div class="page-head d-flex flex-wrap align-items-start mb-3">
  <div class="me-auto pe-3">
    <div class="small"><a href="/clients/<?= $cid ?>/compliance">Compliance</a> /</div>
    <h1 class="h4 mb-0"><i class="fas fa-fw fa-scale-balanced text-secondary me-1"></i>Evidence check</h1>
    <div class="text-muted small mt-1 page-desc">Does Align read this client's security right? Answer each statement from the client's own systems
      (the Huntress portal, the Microsoft 365 or Google admin center, DNS, the training platform), not from Align's pages. Align shows how your answers
      compare once you save. Results across clients are on <a href="/compliance/evidence">Compliance → Evidence check</a>.</div>
  </div>
  <div class="page-actions d-flex flex-wrap align-items-center mt-1">
    <a class="btn btn-sm <?= $results ? 'btn-default' : 'btn-primary' ?> ms-1" href="/clients/<?= $cid ?>/evidence">Answer</a>
    <a class="btn btn-sm <?= $results ? 'btn-primary' : 'btn-default' ?> ms-1" href="/clients/<?= $cid ?>/evidence?results=1">Results</a>
  </div>
</div>

<?php if (!$results): ?>
  <?php if (!$systems): ?>
    <div class="card"><?= \Align\View::fetch('partials/empty', ['icon' => 'fa-plug', 'text' => 'Align has no automatic security results for this client yet.',
        'more' => 'Connect Huntress, Microsoft 365, Google Workspace or email authentication on the client\'s Connectors page, then come back after the next sync.',
        'action' => '<a class="btn btn-sm btn-primary" href="/clients/' . $cid . '/connectors">Connectors</a>']) ?></div>
  <?php else: ?>
    <form method="post" action="/clients/<?= $cid ?>/evidence" data-unsaved>
      <?= csrf_field() ?>
      <?php foreach ($systems as $system => $cs): ?>
        <div class="card">
          <div class="card-header"><h2 class="card-title"><?= e($system) ?></h2></div>
          <div class="table-responsive">
            <table class="table mb-0 align-middle">
              <thead><tr><th>Is this true for <?= e($client['name']) ?>?</th><th>Your answer</th><th>How you checked (optional, stays in Align)</th></tr></thead>
              <tbody>
                <?php foreach ($cs as $k => $c): ?>
                  <tr id="ev-<?= e($k) ?>">
                    <td style="min-width: 16rem">
                      <b><?= e($c['label']) ?></b>
                      <?php if (!$c['known']): ?><div class="small text-muted">Align can't read this one for this client: answer it if you know.</div><?php endif; ?>
                      <?php if (!empty($usedBy[$k])): ?>
                        <details class="small text-muted mt-1"><summary>Used as evidence for <?= count($usedBy[$k]) ?> item<?= count($usedBy[$k]) === 1 ? '' : 's' ?></summary>
                          <ul class="mb-0 ps-3"><?php foreach (array_slice($usedBy[$k], 0, 12) as $u): ?><li><?= e($u) ?></li><?php endforeach; ?><?= count($usedBy[$k]) > 12 ? '<li>…</li>' : '' ?></ul>
                        </details>
                      <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                      <div class="btn-group btn-group-sm" role="radiogroup" aria-label="<?= e($c['label']) ?>" data-radio-buttons>
                        <?php foreach (Evidence::ANSWERS as $v => $l): ?>
                          <label class="btn btn-outline-<?= ['pass' => 'success', 'fail' => 'danger', 'unsure' => 'secondary'][$v] ?>"><input type="radio" name="a[<?= e($k) ?>]" value="<?= $v ?>"><?= e($l) ?></label>
                        <?php endforeach; ?>
                      </div>
                    </td>
                    <td style="min-width: 14rem"><input class="form-control form-control-sm" name="note[<?= e($k) ?>]" maxlength="1000" aria-label="How you checked: <?= e($c['label']) ?>" placeholder="e.g. Entra ID → Security defaults"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="card card-body sticky-save d-flex flex-row flex-wrap align-items-center gap-2">
        <span class="small text-muted me-auto">Unanswered checks are left out. Each save is kept with what Align found at that moment.</span>
        <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save and compare</button>
      </div>
    </form>
  <?php endif; ?>
<?php else: ?>
  <?php if (!$latest): ?>
    <div class="card"><?= \Align\View::fetch('partials/empty', ['icon' => 'fa-scale-balanced', 'text' => 'No answers for this client yet.',
        'action' => '<a class="btn btn-sm btn-primary" href="/clients/' . $cid . '/evidence">Answer the checks</a>']) ?></div>
  <?php else: ?>
    <div class="card">
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead><tr><th>Check</th><th>Your answer</th><th>Align found</th><th>Outcome</th><th>Now</th></tr></thead>
          <tbody>
            <?php foreach ($latest as $r): $k = $r['check_key']; $now = $current[$k] ?? null; ?>
              <tr>
                <td style="min-width: 14rem"><b><?= e($checks[$k]['label'] ?? $k) ?></b><div class="small text-muted"><?= e(Evidence::system($k)) ?> · <?= e(fmt_date($r['created_at'])) ?><?= $r['user_name'] ? ' · ' . e($r['user_name']) : '' ?></div>
                  <?php if ($r['note']): ?><div class="small mt-1"><i class="fas fa-magnifying-glass me-1 text-muted"></i><?= e($r['note']) ?></div><?php endif; ?></td>
                <td class="text-nowrap"><?= e($res[$r['manual']]) ?></td>
                <td style="min-width: 14rem"><?= e($res[$r['auto_status']]) ?><?php if ($r['auto_detail']): ?><div class="small text-muted"><?= e($r['auto_detail']) ?></div><?php endif; ?></td>
                <td><span class="badge text-bg-<?= $tone[$r['outcome']] ?>"><?= e($word[$r['outcome']]) ?></span></td>
                <td class="small text-muted"><?= $now && $now['status'] !== $r['auto_status'] ? 'Align now finds: ' . e($res[$now['status']]) : '' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted">A disagreement means one of the two is wrong: check the client's system again. When Align is the one that's
        wrong, the anonymized export on Compliance → Evidence check can go with a bug report: it has no client names, notes or people, and
        names, domains and addresses are taken out of Align's text. Read it before you share it.</div>
    </div>
  <?php endif; ?>
<?php endif; ?>

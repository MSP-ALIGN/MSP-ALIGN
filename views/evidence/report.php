<?php
/**
 * 2.18.0 Evidence check across clients: each system's gate and each check's state (trusted, disagrees, needs more
 * clients, not compared, no answers; see Evidence::summary()), and every disagreement with what Align found and what the tech answered.
 * @var array $rows (Evidence::latest()); array $summary (Evidence::summary())
 * Every value is escaped. Techs and admins only (EvidenceController::report).
 */
use Align\Compliance\Evidence;
use Align\Health\SecurityChecks;

$labels = SecurityChecks::labels();
$res = ['pass' => 'True', 'fail' => 'Not true', 'unknown' => 'No result', 'unsure' => 'Can\'t tell'];
$disagree = array_filter($rows, fn($r) => $r['outcome'] === 'disagree');
$clients = count(array_unique(array_column($rows, 'client_id')));
?>
<?= \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-scale-balanced', 'title' => 'Evidence check', 'count' => $clients ?: null,
    'desc' => 'Whether Align\'s automatic security checks can be trusted as evidence, for compliance frameworks and alignment standards alike: techs answer each check for a client from the client\'s own systems, without seeing Align\'s result, and this page compares the two. A check is trusted once answers from at least ' . Evidence::GATE_CLIENTS . ' clients agree with Align and none disagree; a system passes when every one of its checks is trusted.',
    'primary' => $rows ? '<a class="btn btn-sm btn-default" href="/compliance/evidence/export"><i class="fas fa-file-csv me-1"></i>Anonymized export</a>' : null,
    'help' => 'guide-evidence',
]) ?>
<?= \Align\View::fetch('partials/section_tabs', ['tabs' => [['/compliance', 'Overview', 'fa-clipboard-check', false], ['/frameworks', 'Frameworks', 'fa-list-check', false, \Align\Auth::can('admin')], ['/compliance/evidence', 'Evidence check', 'fa-scale-balanced', true]]]) ?>

<?php if (!$rows): ?>
  <div class="card"><?= \Align\View::fetch('partials/empty', ['icon' => 'fa-scale-balanced', 'text' => 'No evidence checks answered yet.',
      'more' => 'Pick two or three clients whose systems you know well, open Compliance on each, and use Evidence check → Answer the checks.',
      'action' => '<a class="btn btn-sm btn-primary" href="/clients">Clients</a>']) ?></div>
<?php else: ?>
  <div class="row">
    <?php foreach ($summary as $system => $s): if (!$s['clients']) continue; ?>
      <div class="col-md-6 col-xl-4">
        <div class="card<?= $s['passed'] ? ' card-outline card-success' : ($s['disagree'] ? ' card-outline card-danger' : '') ?>" id="gate-<?= e(preg_replace('/[^a-z0-9]+/', '-', strtolower($system))) ?>">
          <div class="card-header"><h2 class="card-title"><?= e($system) ?></h2>
            <div class="card-tools"><span class="badge text-bg-<?= $s['passed'] ? 'success' : ($s['disagree'] ? 'danger' : 'secondary') ?>"><?= $s['passed'] ? 'Gate passed' : ($s['disagree'] ? 'Disagreements' : (int) $s['trusted'] . ' of ' . (int) $s['total'] . ' checks trusted') ?></span></div></div>
          <div class="card-body small">
            <div class="mb-2"><?= (int) $s['compared'] ?> client<?= $s['compared'] === 1 ? '' : 's' ?> compared (<?= Evidence::GATE_CLIENTS ?> needed per check) · <?= (int) $s['trusted'] ?> of <?= (int) $s['total'] ?> checks trusted · <?= (int) $s['agree'] ?> agree · <?= (int) $s['disagree'] ?> disagree<?= $s['unsure'] ? ' · ' . (int) $s['unsure'] . ' couldn\'t tell' : '' ?><?= $s['no_result'] ? ' · ' . (int) $s['no_result'] . ' with no result from Align' : '' ?></div>
            <ul class="list-unstyled mb-0">
              <?php foreach ($s['checks'] as $k => $c): $need = Evidence::GATE_CLIENTS - $c['compared']; ?>
                <li class="d-flex gap-2 py-1 border-top" data-check="<?= e($k) ?>"><span class="me-auto"><?= e($labels[$k] ?? $k) ?>
                    <?php if ($c['answered']): ?><span class="d-block text-muted"><?= (int) $c['agree'] ?> agree · <?= (int) $c['disagree'] ?> disagree<?= $c['unsure'] + $c['no_result'] ? ' · ' . (int) ($c['unsure'] + $c['no_result']) . ' not compared' : '' ?></span><?php endif; ?></span>
                  <span class="text-nowrap"><?= match ($c['state']) {
                      'trusted' => '<span class="badge text-bg-success">Trusted</span>',
                      'disagree' => '<span class="badge text-bg-danger">Disagrees</span>',
                      'needs' => '<span class="badge text-bg-secondary">Needs ' . $need . ' more client' . ($need === 1 ? '' : 's') . '</span>',
                      'none' => '<span class="badge text-bg-light border">Not compared</span>',
                      default => '<span class="badge text-bg-light border">No answers</span>',
                  } ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card" id="disagreements">
    <div class="card-header"><h2 class="card-title">Disagreements</h2></div>
    <?php if (!$disagree): ?>
      <?= \Align\View::fetch('partials/empty', ['icon' => 'fa-circle-check', 'text' => 'None: every answer that could be compared agrees with Align.']) ?>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead><tr><th>Client</th><th>Check</th><th>Tech answered</th><th>Align found</th><th>How the tech checked</th></tr></thead>
          <tbody>
            <?php foreach ($disagree as $r): ?>
              <tr>
                <td><a href="/clients/<?= (int) $r['client_id'] ?>/evidence?results=1"><?= e($r['client_name']) ?></a><div class="small text-muted"><?= e(fmt_date($r['created_at'])) ?><?= $r['user_name'] ? ' · ' . e($r['user_name']) : '' ?></div></td>
                <td style="min-width: 14rem"><?= e($labels[$r['check_key']] ?? $r['check_key']) ?><div class="small text-muted"><?= e(Evidence::system($r['check_key'])) ?></div></td>
                <td class="text-nowrap"><?= e($res[$r['manual']]) ?></td>
                <td style="min-width: 14rem"><?= e($res[$r['auto_status']]) ?><?php if ($r['auto_detail']): ?><div class="small text-muted"><?= e($r['auto_detail']) ?></div><?php endif; ?></td>
                <td class="small"><?= e((string) $r['note']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

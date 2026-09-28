<?php
/**
 * On the client's Backups page (techs): machines and jobs on your own backup server that aren't matched to
 * any client, likeliest first, each with a one-click "This client's".
 * @var array $client; array $claim Backup::claimable()
 */
$cid = (int) $client['id'];
$first = array_values(array_filter($claim['jobs'], fn($j) => $j['score'] > 0));
$firstM = array_values(array_filter($claim['machines'], fn($m) => $m['score'] > 0));
$restJ = array_values(array_filter($claim['jobs'], fn($j) => $j['score'] === 0));
$restM = array_values(array_filter($claim['machines'], fn($m) => $m['score'] === 0));
$total = count($claim['machines']);
$btn = fn(string $kind, string $uid, string $label) => '<form method="post" action="/clients/' . $cid . '/backups/claim" class="d-inline ml-2">' . csrf_field()
    . '<input type="hidden" name="kind" value="' . e($kind) . '"><input type="hidden" name="uid" value="' . e($uid) . '">'
    . '<button class="btn btn-xs btn-outline-primary text-nowrap"><i class="fas fa-check mr-1"></i>' . e($label) . '</button></form>';
$row = function (array $x, string $kind) use ($btn) {
    $sub = $kind === 'job'
        ? 'Backup job · ' . (int) $x['machines'] . ' machine' . ((int) $x['machines'] === 1 ? '' : 's') . ': ' . mb_strimwidth((string) $x['machine_names'], 0, 120, '…')
        : ($x['kind'] === 'vm' ? 'Virtual machine' : 'Computer') . ($x['job_names'] ? ' · job ' . $x['job_names'] : '') . ($x['last_point'] ? ' · backed up ' . rel_time($x['last_point']) : '');
    return '<li class="list-group-item py-2 d-flex align-items-center"><div class="mr-auto"><i class="fas fa-fw ' . ($kind === 'job' ? 'fa-list-check' : 'fa-server') . ' text-muted mr-1"></i><b>' . e($x['name']) . '</b>'
        . ($x['score'] > 0 ? ' <span class="badge badge-info font-weight-normal">Looks like a match</span>' : '')
        . '<div class="small text-muted ml-4">' . e($sub) . '</div></div>'
        . $btn($kind, $x['uid'], $kind === 'job' ? "This client's (whole job)" : "This client's") . '</li>';
};
$suggested = $first || $firstM;
?>
<div class="card card-outline card-info" id="hosted-claim">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-building text-info mr-2"></i>Backed up on your own server?</h3>
    <div class="card-tools small pt-1"><a href="/mapping/backups">Hosted backups<i class="fas fa-arrow-right ml-1"></i></a></div>
  </div>
  <div class="card-body py-2 small text-muted border-bottom">
    <?= $total ?> machine<?= $total === 1 ? '' : 's' ?> on your backup server <?= $total === 1 ? 'isn\'t' : 'aren\'t' ?> matched to any client yet.
    <?= $suggested ? 'These look like they could be ' . e($client['name']) . '\'s. ' : '' ?>If one is, press <b>This client's</b>: its backups then count here. Assigning a job brings all its machines, including ones added later.
  </div>
  <?php if ($suggested): ?>
    <ul class="list-group list-group-flush"><?php foreach ($first as $j) echo $row($j, 'job'); foreach ($firstM as $m) echo $row($m, 'workload'); ?></ul>
  <?php endif; ?>
  <?php if ($restJ || $restM): ?>
    <div class="card-body py-2 border-top"><a class="small" data-toggle="collapse" href="#claim-rest" role="button" aria-expanded="false"><i class="fas fa-angle-down mr-1"></i><?= $suggested ? 'Show the other ' : 'Show the ' ?><?= count($restJ) ? count($restJ) . ' job' . (count($restJ) === 1 ? '' : 's') . ' and ' : '' ?><?= count($restM) ?> machine<?= count($restM) === 1 ? '' : 's' ?></a></div>
    <div class="collapse" id="claim-rest">
      <div class="px-3 pb-2"><input type="search" class="form-control form-control-sm filter-input" data-filter-table="claim-rest-list" placeholder="Filter…" aria-label="Filter unmatched machines" style="max-width:280px"></div>
      <ul class="list-group list-group-flush" id="claim-rest-list" style="max-height:420px;overflow:auto"><?php foreach ($restJ as $j) echo $row($j, 'job'); foreach (array_slice($restM, 0, 300) as $m) echo $row($m, 'workload'); ?></ul>
      <?php if (count($restM) > 300): ?><div class="card-body py-2 small text-muted">Showing 300. Mark your own servers as yours under <a href="/mapping/backups">Hosted backups</a> to shorten this list.</div><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

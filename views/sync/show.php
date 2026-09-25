<?php $sum = json_decode((string) $run['summary'], true) ?: []; ?>
<div class="small"><a href="/sync">Sync</a> /</div>
<h1 class="h4">Sync #<?= (int) $run['id'] ?> <small class="text-muted"><?= e(fmt_datetime($run['started_at'])) ?> · <?= e($run['triggered_by']) ?> · <?= e($run['status']) ?></small></h1>
<div class="row">
  <div class="col-lg-5">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1">Steps</h3></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <?php foreach ($sum as $step => $result): ?>
            <tr><th class="font-weight-normal text-muted"><?= e($step) ?></th><td class="<?= str_starts_with((string) $result, 'ERROR') ? 'text-danger' : '' ?>"><?= e($result) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1">Log</h3></div>
      <div class="card-body"><pre class="sync-log mb-0"><?= e($run['log']) ?></pre></div>
    </div>
  </div>
</div>

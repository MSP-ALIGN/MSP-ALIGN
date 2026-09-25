<div class="row">
  <div class="col-lg-7">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-print mr-2"></i>Client reports</h3></div>
      <div class="card-body">
        <p class="small text-muted">Reports open in a print-ready page. Use <b>Print / Save as PDF</b> to hand one to a client or attach it to a QBR invite. Checkboxes at the top of each report switch costs, the full inventory and notes on or off.</p>
        <form method="get" id="report-form" target="_blank" action="">
          <div class="form-row">
            <div class="form-group col-md-6"><label>Client</label>
              <select class="form-control" id="report-client" required>
                <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
              </select></div>
            <div class="form-group col-md-6"><label>Report</label>
              <select class="form-control" id="report-type">
                <option value="assets">Asset &amp; lifecycle report</option>
                <option value="roadmap">3-year technology roadmap</option>
                <option value="budget">Technology budget</option>
              </select></div>
          </div>
          <div class="d-flex flex-wrap small mb-3">
            <input type="hidden" name="costs" value="0"><div class="custom-control custom-checkbox mr-3"><input type="checkbox" class="custom-control-input" id="r-costs" name="costs" value="1" checked><label class="custom-control-label font-weight-normal" for="r-costs">Include costs</label></div>
            <input type="hidden" name="inventory" value="0"><div class="custom-control custom-checkbox mr-3"><input type="checkbox" class="custom-control-input" id="r-inv" name="inventory" value="1" checked><label class="custom-control-label font-weight-normal" for="r-inv">Full inventory (asset report)</label></div>
            <input type="hidden" name="notes" value="0"><div class="custom-control custom-checkbox mr-3"><input type="checkbox" class="custom-control-input" id="r-notes" name="notes" value="1" checked><label class="custom-control-label font-weight-normal" for="r-notes">Notes &amp; descriptions</label></div>
            <input type="hidden" name="virtual" value="0"><div class="custom-control custom-checkbox mr-3"><input type="checkbox" class="custom-control-input" id="r-vm" name="virtual" value="1"><label class="custom-control-label font-weight-normal" for="r-vm">Virtual machines</label></div>
          </div>
          <button class="btn btn-primary" <?= $clients ? '' : 'disabled' ?>><i class="fas fa-file-lines mr-1"></i>Open report</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-layer-group mr-2"></i>All clients</h3></div>
      <div class="card-body">
        <p class="small text-muted">One page covering every client in planning: device counts, lifecycle problems and the hardware budget for each plan year. Useful for your own capacity and sales planning.</p>
        <a class="btn btn-default" href="/reports/portfolio" target="_blank"><i class="fas fa-print mr-1"></i>Portfolio summary</a>
        <a class="btn btn-default" href="/reports/portfolio?costs=0" target="_blank">Without costs</a>
      </div>
    </div>
  </div>
</div>

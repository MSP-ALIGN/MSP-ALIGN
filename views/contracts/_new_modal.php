<?php
/** New contract: template + client or new lead. @var array $templates, $clients; ?int $presetClient */
$presetClient = $presetClient ?? null;
?>
<div class="modal fade" id="modal-new-contract" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="/contracts">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-file-signature me-2"></i>New contract</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (!$templates): ?>
            <p class="mb-0">There are no contract templates yet. <?= \Align\Auth::can('admin') ? '<a href="/contracts/templates">Make one first.</a>' : 'Ask an admin to make one.' ?></p>
          <?php else: ?>
            <div class="mb-3"><label for="nc-template">Template</label>
              <select id="nc-template" name="template_id" class="form-select" required>
                <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
              </select></div>
            <?php if ($presetClient): ?>
              <input type="hidden" name="client_id" value="<?= (int) $presetClient ?>">
            <?php else: ?>
              <div class="mb-2"><label class="d-block">For</label>
                <div class="btn-group btn-group-sm" role="group" data-radio-buttons>
                  <input type="radio" class="btn-check" name="for" id="nc-for-lead" value="lead" checked><label class="btn btn-outline-secondary" for="nc-for-lead">A new client</label>
                  <input type="radio" class="btn-check" name="for" id="nc-for-client" value="client"><label class="btn btn-outline-secondary" for="nc-for-client">An existing client</label>
                </div></div>
              <div class="mb-3" data-show-when="for=lead"><label for="nc-lead">Company name</label>
                <input id="nc-lead" name="lead_company" class="form-control" maxlength="190" placeholder="e.g. Northfield Hardware &amp; Supply">
                <div class="form-text">Once they've signed, press <b>Add as a client</b> on the contract to add them to Align.</div></div>
              <div class="mb-3" data-show-when="for=client"><label for="nc-client">Client</label>
                <select id="nc-client" name="client_id" class="form-select"><option value="">Choose…</option>
                  <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                </select>
                <div class="form-text">Devices, users and licenses are counted for you from what Align already knows.</div></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <?php if ($templates): ?><button class="btn btn-primary">Make the contract</button><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

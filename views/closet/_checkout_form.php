<?php
/**
 * 2.15.0 The check-out form. @var string $action the URL it posts to; string $back; array $contacts the borrowing
 * client's contacts (id, name; empty for the MSP's stock); array $clients (MSP stock only: clients a loaner can go
 * to; empty otherwise); bool $qty (ask how many: counted stock); int $max (on the shelf); string $pid id prefix.
 * Every value is escaped; ClosetController checks the contact and client again.
 */
$clients = $clients ?? [];
?>
<form method="post" action="<?= e($action) ?>" class="row g-2 align-items-end small">
  <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
  <?php if ($qty): ?><div class="col-md-2"><label for="<?= $pid ?>-q">How many</label><input id="<?= $pid ?>-q" name="quantity" type="number" min="1" max="<?= max(1, (int) $max) ?>" value="1" class="form-control form-control-sm"></div><?php endif; ?>
  <?php if ($clients): ?>
    <div class="col-md-3"><label for="<?= $pid ?>-bc">Client <span class="text-muted">(optional)</span></label><select id="<?= $pid ?>-bc" name="borrower_client_id" class="form-select form-select-sm"><option value="">None (your team)</option>
      <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($contacts): ?>
    <div class="col-md-3"><label for="<?= $pid ?>-ct">Contact</label><select id="<?= $pid ?>-ct" name="contact_id" class="form-select form-select-sm"><option value="">Someone else (type a name)</option>
      <?php foreach ($contacts as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="col-md-3"><label for="<?= $pid ?>-p"><?= $contacts ? 'Or name' : 'Who' ?></label><input id="<?= $pid ?>-p" name="person" class="form-control form-control-sm" maxlength="190"<?= $contacts ? '' : ' required' ?>></div>
  <div class="col-md-2"><label for="<?= $pid ?>-d">Due back <span class="text-muted">(optional)</span></label><input id="<?= $pid ?>-d" name="due_on" type="date" class="form-control form-control-sm"></div>
  <div class="col-md-<?= $clients ? 5 : 6 ?>"><label for="<?= $pid ?>-n">Note <span class="text-muted">(optional)</span></label><input id="<?= $pid ?>-n" name="note" class="form-control form-control-sm" maxlength="500"></div>
  <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-right-from-bracket me-1"></i>Check out</button></div>
</form>

<?php
/**
 * 2.40.0 The guided compliance setup for one client and framework (ComplianceSetupController::show). One step at a
 * time, with the step list on the left; every step can be revisited, and the checklist stays available.
 * @var array $client, $fw, $link, $setup (or null for a viewer before anyone started), $score, $waiting
 * @var int $step; bool $canEdit; plus the step's own data (see the controller).
 * Every value is escaped with e(); forms post with CSRF to ComplianceSetupController::action, which checks the role,
 * the client and the framework again.
 */
use Align\Compliance\ClientQuestions;
use Align\Compliance\Compliance;
use Align\Compliance\Setup;

require __DIR__ . '/../partials/client_header.php';
$id = (int) $client['id'];
$fid = (int) $fw['id'];
$base = "/clients/$id/compliance/$fid/setup";
$act = fn(string $a) => "$base/$a";
$reached = (int) ($setup['step'] ?? 1);
$st = Compliance::STATUSES;
$fromStatus = array_combine(array_column(Setup::ANSWERS, 1), array_keys(Setup::ANSWERS)); // status => answer key, for "Use this answer"
$badge = function (string $s) use ($st): string {
    if ($s === 'mixed') {
        return '<span class="badge text-bg-light border">Mixed</span>';
    }
    [$label, $tone, $icon] = $st[$s] ?? $st['not_assessed'];
    return '<span class="badge text-bg-' . e($tone) . '"><i class="fas ' . e($icon) . ' me-1"></i>' . e($label) . '</span>';
};
?>
<div class="d-flex flex-wrap align-items-center mb-3 gap-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-route me-2 text-primary"></i><?= e($fw['name']) ?> setup</h1>
  <span class="small text-muted me-2">Score <b class="text-<?= e($score['tone']) ?>"><?= (int) $score['score'] ?>%</b> · <?= (int) $score['assessed'] ?>% assessed</span>
  <a class="btn btn-sm btn-default" href="/clients/<?= $id ?>/compliance/<?= $fid ?>"><i class="fas fa-list-check me-1"></i>Open the checklist</a>
</div>
<?php if (!empty($setup['finished_at'])): ?><div class="alert alert-success py-2 small"><i class="fas fa-flag-checkered me-1"></i>Setup finished <?= e(fmt_date($setup['finished_at'])) ?>. You can go back to any step to update answers.</div><?php endif; ?>

<div class="row">
  <div class="col-lg-3 mb-3">
    <?php /* The steps: done (reached), current, and the ones ahead; each opens its page */ ?>
    <div class="list-group setup-steps" aria-label="Setup steps">
      <?php foreach (Setup::STEPS as $n => [$label, $icon]): ?>
        <a href="<?= e("$base?step=$n") ?>" class="list-group-item list-group-item-action d-flex align-items-center<?= $n === $step ? ' active' : '' ?>" <?= $n === $step ? 'aria-current="step"' : '' ?>>
          <?php $isDone = $n < $reached || !empty($setup['finished_at']) || !empty($complete[$n]); /* (2.40.3) or already complete from the client's data */ ?>
          <span class="setup-step-n me-2 <?= $isDone ? 'done' : '' ?>"><?= $isDone ? '<i class="fas fa-check"></i>' : $n ?></span>
          <span class="me-auto"><?= e($label) ?></span><i class="fas <?= e($icon) ?> opacity-50"></i>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($waiting): ?><a class="btn btn-sm btn-warning w-100 mt-2" href="<?= e("$base?step=5#client-questions") ?>"><i class="fas fa-inbox me-1"></i><?= count($waiting) ?> client <?= count($waiting) === 1 ? 'answer' : 'answers' ?> to review</a><?php endif; ?>
  </div>

  <div class="col-lg-9">
  <?php if ($step === 1): /* ---- Scope */ ?>
    <div class="card card-dark"><div class="card-header py-2"><h2 class="card-title h5 mt-1">1. Scope</h2></div>
      <form method="post" action="<?= e($act('scope')) ?>" class="card-body">
        <?= csrf_field() ?>
        <p class="text-muted">A few questions about <?= e($client['name']) ?> decide which items don't apply. A "No" marks them <b>Not applicable</b>; change it later and they come back. These answers are shared with the client's other frameworks.</p>
        <?php foreach ($scope as $k => [$q, $help]): $a = $answers[$k] ?? null; ?>
          <div class="border rounded p-3 mb-2" data-scope="<?= e($k) ?>">
            <div class="fw-semibold mb-1"><?= e($q) ?></div>
            <div class="small text-muted mb-2"><?= e($help) ?></div>
            <div class="btn-group btn-group-sm" role="group">
              <?php foreach (['yes' => 'Yes', 'no' => 'No'] as $v => $l): ?>
                <input type="radio" class="btn-check" name="scope[<?= e($k) ?>]" value="<?= $v ?>" id="sc-<?= e($k) ?>-<?= $v ?>" <?= $a === $v ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                <label class="btn btn-outline-secondary" for="sc-<?= e($k) ?>-<?= $v ?>"><?= $l ?></label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($canEdit): ?><button class="btn btn-primary mt-2">Save and continue <i class="fas fa-arrow-right ms-1"></i></button><?php endif; ?>
      </form>
    </div>

  <?php elseif ($step === 2): /* ---- Evidence from the automatic checks */
    $usable = array_filter($evidence, fn($c) => $c['status'] === 'not_assessed' && in_array($c['indicator']['suggest'] ?? null, ['met', 'not_met'], true)); ?>
    <div class="card card-dark"><div class="card-header py-2"><h2 class="card-title h5 mt-1">2. Evidence from your tools</h2></div>
      <div class="card-body">
        <p class="text-muted">These items are checked automatically from the tools connected for <?= e($client['name']) ?> (RMM, Microsoft 365 or Google, email, security tools). Use the results to answer them; you can still change any answer on the checklist.</p>
        <?php if (!$evidence): ?><p class="mb-0">This framework has no automatically checked items. Continue to the next step.</p><?php endif; ?>
        <?php if ($evidence): ?>
          <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Item</th><th>What the check says</th><th>Answer now</th></tr></thead>
            <tbody>
            <?php foreach ($evidence as $c): $i = $c['indicator']; ?>
              <tr data-evidence="<?= (int) $c['id'] ?>"><td><b><?= e($c['ref']) ?></b> <span class="small"><?= e($c['title']) ?></span></td>
                <td class="small"><?php if ($i && empty($i['unknown'])): ?><i class="fas fa-robot me-1 text-<?= !empty($i['ok']) ? 'success' : 'danger' ?>"></i><?= e(($i['label'] ?? '') . ': ' . ($i['text'] ?? '')) ?><?php else: ?><span class="text-muted"><i class="fas fa-plug me-1"></i><?= e($i['text'] ?? 'Not connected, so it can\'t be checked yet.') ?></span><?php endif; ?></td>
                <td><?= $badge((string) $c['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
          <?php if ($canEdit): ?>
            <form method="post" action="<?= e($act('evidence')) ?>" class="d-inline"><?= csrf_field() ?>
              <button class="btn btn-primary" <?= $usable ? '' : 'disabled' ?>><i class="fas fa-robot me-1"></i>Use these results (<?= count($usable) ?>)</button></form>
          <?php endif; ?>
        <?php endif; ?>
        <a class="btn btn-default ms-1" href="<?= e("$base?step=3") ?>">Continue <i class="fas fa-arrow-right ms-1"></i></a>
      </div>
    </div>

  <?php elseif ($step === 3): /* ---- Client details (2.39.0) */ ?>
    <div class="card card-dark"><div class="card-header py-2"><h2 class="card-title h5 mt-1">3. Client details</h2></div>
      <div class="card-body">
        <p class="text-muted">Who fills the security roles at <?= e($client['name']) ?>. Documents made from templates fill these names in.</p>
        <ul class="list-unstyled mb-3">
          <?php foreach (\Align\Docs\Facts::FIELDS as $k => [$label]): ?>
            <li class="mb-1"><i class="fas fa-fw <?= isset($facts[$k]) ? 'fa-circle-check text-success' : 'fa-circle text-muted opacity-50' ?> me-1"></i><b><?= e($label) ?>:</b> <?= isset($facts[$k]) ? e($facts[$k]) : '<span class="text-muted">not set</span>' ?></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-default" href="/clients/<?= $id ?>/document-answers"><i class="fas fa-list-check me-1"></i>Answers for documents</a> <?php /* 2.41.0 the client's document form */ ?>
        <a class="btn btn-primary ms-1" href="<?= e("$base?step=4") ?>">Continue <i class="fas fa-arrow-right ms-1"></i></a>
      </div>
    </div>

  <?php elseif ($step === 4): /* ---- Required documents (Docs\Related) */ ?>
    <?php $word = \Align\Docs\Related::word((string) $fw['slug']); /* (2.40.0 review) the same wording as the checklist (2.38.2) */ ?>
    <?= \Align\View::fetch('documents/_related', ['client' => $client, 'rows' => $related, 'heading' => 'Documents for ' . $fw['name'],
        'word' => $word, 'note' => \Align\Docs\Related::FRAMEWORK_NOTE[(string) $fw['slug']] ?? null,
        'intro' => ($word === 'expected' ? 'The written documents usually expected for ' : 'The written documents ') . $fw['name'] . ($word === 'expected' ? '' : ' calls for') . '. Create each from its template, fill in the blanks and make it Active.',
        'none' => 'Optional documents that cover some of its items.', 'canEdit' => $canEdit, 'ai' => $canEdit && \Align\Ai\Ai::usable('drafts'),
        'review' => $canEdit ? \Align\Docs\Related::reviewFor($id) : 0]) ?>
    <a class="btn btn-primary" href="<?= e("$base?step=5") ?>">Continue <i class="fas fa-arrow-right ms-1"></i></a>

  <?php elseif ($step === 5): /* ---- Questions, one per topic */ ?>
    <div class="card card-dark"><div class="card-header py-2 d-flex align-items-center"><h2 class="card-title h5 mt-1 me-auto">5. Questions</h2>
      <span class="small"><?= (int) $answered ?> of <?= (int) $total ?> topics answered · page <?= (int) $page ?> of <?= (int) $pages ?></span></div>
      <form method="post" action="<?= e($act('answer')) ?>" class="card-body" id="setup-questions">
        <?= csrf_field() ?><input type="hidden" name="page" value="<?= (int) $page ?>">
        <p class="text-muted small">Each question covers the <?= e($fw['name']) ?> items listed under it. Pick the answer for <?= e($client['name']) ?> as a whole; adjust single items on the checklist. Don't know? Tick <b>Ask the client</b> and send the ticked questions below.</p>
        <?php foreach ($groups as $g): [$label, $q, $why, $proof] = $g['topic']; $key = $g['key']; ?>
          <div class="setup-q border rounded p-3 mb-3" data-topic="<?= e($key) ?>">
            <div class="d-flex flex-wrap align-items-start gap-2 mb-1">
              <div class="me-auto"><div class="small text-uppercase text-muted fw-semibold"><?= e($label) ?></div><div class="fw-semibold"><?= e($q) ?></div></div>
              <?= $badge($g['status']) ?>
            </div>
            <?php if ($why): ?><div class="small mb-1"><i class="fas fa-circle-info text-info me-1"></i><b>Why it matters:</b> <?= e($why) ?></div><?php endif; ?>
            <?php if ($proof): ?><div class="small mb-2"><i class="fas fa-file-circle-check text-success me-1"></i><b>What counts as proof:</b> <?= e($proof) ?></div><?php endif; ?>
            <?php foreach ($g['elsewhere'] as $x): ?>
              <div class="alert alert-info py-1 px-2 small mb-2" data-elsewhere><i class="fas fa-link me-1"></i>Already answered in <b><?= e($x['framework']) ?></b>: <?= $badge((string) $x['status']) ?><?= $x['agree'] ? '' : ' <span class="text-muted">(not all the same)</span>' ?>
                <?php if ($canEdit && $x['agree'] && isset($fromStatus[$x['status']])): /* app.js picks the matching answer; saving applies it */ ?>
                  <button type="button" class="btn btn-xs btn-light ms-1" data-use-answer="<?= e($fromStatus[$x['status']]) ?>">Use this answer</button><?php endif; ?></div>
            <?php endforeach; ?>
            <?php if ($canEdit): ?>
              <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <?php /* (2.40.3 review) the answer shown when the page opened: saving applies only answers changed since */ ?>
                <input type="hidden" name="was[<?= e($key) ?>]" value="<?= e($fromStatus[$g['status']] ?? '') ?>">
                <div class="btn-group btn-group-sm" role="group" aria-label="Answer">
                  <?php foreach (Setup::ANSWERS as $v => [$l]): ?>
                    <?php /* (2.40.3) the answer already recorded is picked; saving it unchanged changes nothing */ ?>
                    <input type="radio" class="btn-check" name="a[<?= e($key) ?>]" value="<?= e($v) ?>" id="a-<?= e($key) ?>-<?= e($v) ?>"<?= ($fromStatus[$g['status']] ?? null) === $v ? ' checked' : '' ?>>
                    <label class="btn btn-outline-secondary" for="a-<?= e($key) ?>-<?= e($v) ?>"><?= e($l) ?></label>
                  <?php endforeach; ?>
                </div>
                <?php if (!str_starts_with($key, 'control-')): ?>
                  <?php if (isset($asked[$key])): ?><span class="small text-warning"><i class="fas fa-hourglass-half me-1"></i>Asked the client</span>
                  <?php else: ?><div class="form-check small mb-0"><input class="form-check-input" type="checkbox" form="ask-form" name="topics[]" value="<?= e($key) ?>" id="ask-<?= e($key) ?>"><label class="form-check-label" for="ask-<?= e($key) ?>">Ask the client</label></div><?php endif; ?>
                <?php endif; ?>
              </div>
              <input class="form-control form-control-sm mb-2" name="n[<?= e($key) ?>]" maxlength="5000" placeholder="Note or evidence (optional): where the proof is, who confirmed it" aria-label="Note">
            <?php endif; ?>
            <details class="small"><summary class="text-muted"><?= count($g['controls']) ?> <?= e($fw['name']) ?> <?= count($g['controls']) === 1 ? 'item' : 'items' ?></summary>
              <ul class="list-unstyled mt-1 mb-0">
                <?php foreach ($g['controls'] as $c): ?><li class="mb-1"><?= $badge((string) $c['status']) ?> <b><?= e($c['ref']) ?></b> <?= e($c['title']) ?></li><?php endforeach; ?>
              </ul></details>
          </div>
        <?php endforeach; ?>
        <div class="d-flex flex-wrap gap-2">
          <?php if ($canEdit): ?>
            <?php if ($page > 1): ?><button class="btn btn-default" name="go" value="back"><i class="fas fa-arrow-left me-1"></i>Back</button><?php endif; ?>
            <button class="btn btn-default" name="go" value="stay">Save</button>
            <button class="btn btn-primary" name="go" value="next"><?= $page < $pages ? 'Save and next page' : 'Save and continue' ?> <i class="fas fa-arrow-right ms-1"></i></button>
          <?php else: /* (2.40.0 review) viewers move between pages with links: the buttons post */ ?>
            <?php if ($page > 1): ?><a class="btn btn-default" href="<?= e("$base?step=5&page=" . ($page - 1)) ?>"><i class="fas fa-arrow-left me-1"></i>Back</a><?php endif; ?>
            <a class="btn btn-primary" href="<?= e($page < $pages ? "$base?step=5&page=" . ($page + 1) : "$base?step=6") ?>">Next <i class="fas fa-arrow-right ms-1"></i></a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <?php /* Questions sent to the client: send the ticked ones, answers waiting for review, earlier requests */ ?>
    <div class="card card-dark" id="client-questions"><div class="card-header py-2"><h2 class="card-title h5 mt-1"><i class="fas fa-paper-plane me-2"></i>Questions for the client</h2></div>
      <div class="card-body">
        <?php if ($waiting): ?>
          <h3 class="h6">Answers waiting for you</h3>
          <?php foreach ($waiting as $w): ?>
            <div class="border rounded p-2 mb-2" data-waiting="<?= (int) $w['id'] ?>">
              <div class="small text-muted"><?= e($w['from_name']) ?> · <?= e(fmt_date($w['answered_at'])) ?></div>
              <div class="fw-semibold"><?= e($w['question']) ?></div>
              <div><b><?= e(ClientQuestions::ANSWERS[$w['answer']] ?? $w['answer']) ?></b><?= $w['answer_note'] ? ' · ' . e($w['answer_note']) : '' ?></div>
              <?php if ($canEdit): ?>
                <form method="post" action="<?= e($act('review')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="question_id" value="<?= (int) $w['id'] ?>"><input type="hidden" name="page" value="<?= (int) $page ?>"><input type="hidden" name="accept" value="1">
                  <button class="btn btn-xs btn-success mt-1"><?= $w['answer'] === 'unsure' ? 'Mark seen' : 'Accept' ?></button></form>
                <form method="post" action="<?= e($act('review')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="question_id" value="<?= (int) $w['id'] ?>"><input type="hidden" name="page" value="<?= (int) $page ?>">
                  <button class="btn btn-xs btn-default mt-1">Decline</button></form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($canEdit): ?>
          <form method="post" action="<?= e($act('ask')) ?>" id="ask-form" class="mb-3">
            <?= csrf_field() ?><input type="hidden" name="page" value="<?= (int) $page ?>">
            <p class="small text-muted mb-2">Tick <b>Ask the client</b> on the questions above, choose who to ask and send. They get a private link (no sign-in; a portal user also sees it in the portal). Nothing changes until you accept their answers.</p>
            <div class="row g-2">
              <div class="col-md-5"><select class="form-select form-select-sm" name="contact_id" aria-label="Contact" required><option value="">Choose a contact…</option>
                <?php foreach ($contacts as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?><?= $c['title'] ? ' (' . e($c['title']) . ')' : '' ?> · <?= e($c['email']) ?></option><?php endforeach; ?></select></div>
              <div class="col-md-7"><input class="form-control form-control-sm" name="message" maxlength="2000" placeholder="Message (optional)" aria-label="Message"></div>
            </div>
            <div class="mt-2"><button class="btn btn-sm btn-primary" name="action" value="send" <?= \Align\Mail\Mail::ready() ? '' : 'disabled' ?>><i class="fas fa-paper-plane me-1"></i>Send ticked questions</button>
              <button class="btn btn-sm btn-outline-primary" name="action" value="link" title="Nothing is emailed: you send the link yourself"><i class="fas fa-link me-1"></i>Create link only</button></div>
          </form>
        <?php endif; ?>
        <?php if ($requests): ?>
          <h3 class="h6">Sent</h3>
          <ul class="list-group list-group-flush small">
            <?php foreach ($requests as $r): $done = count(array_filter($r['questions'], fn($q) => $q['answer'] !== null)); ?>
              <li class="list-group-item px-0 d-flex flex-wrap align-items-center gap-2" data-request="<?= (int) $r['id'] ?>">
                <span class="me-auto"><?= e($r['name']) ?> · <?= count($r['questions']) ?> <?= count($r['questions']) === 1 ? 'question' : 'questions' ?> · sent <?= e(fmt_date($r['sent_at'])) ?><?= $r['sent_by_name'] ? ' by ' . e($r['sent_by_name']) : '' ?></span>
                <span class="badge text-bg-<?= $r['status'] === 'answered' ? 'success' : ($r['status'] === 'cancelled' || $r['expired'] ? 'secondary' : 'info') ?>"><?= e($r['status'] === 'open' ? ($r['expired'] ? 'Expired' : 'Waiting') : ucfirst($r['status'])) ?><?= $r['status'] === 'answered' ? " ($done)" : '' ?></span>
                <?php if ($canEdit && $r['status'] === 'open' && !$r['expired']): ?><form method="post" action="<?= e($act('cancel')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="page" value="<?= (int) $page ?>"><button class="btn btn-xs btn-outline-danger" data-confirm="Cancel these questions? The link stops working." data-confirm-ok="Cancel">Cancel</button></form><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($step === 6): /* ---- Gaps to roadmap projects */ ?>
    <div class="card card-dark"><div class="card-header py-2"><h2 class="card-title h5 mt-1">6. Gaps to plan</h2></div>
      <form method="post" action="<?= e($act('gaps')) ?>" class="card-body">
        <?= csrf_field() ?>
        <p class="text-muted">Topics answered <b>No</b> or <b>Partly</b>. Choose a quarter to add a proposed project to <?= e($client['name']) ?>'s roadmap (the client can see and approve it in the portal).</p>
        <?php if (!$gaps): ?><p class="mb-2"><i class="fas fa-circle-check text-success me-1"></i>No gaps among the answered items.</p><?php endif; ?>
        <?php foreach ($gaps as $g): ?>
          <div class="border rounded p-2 mb-2 d-flex flex-wrap align-items-center gap-2" data-gap="<?= e($g['key']) ?>">
            <div class="me-auto"><b><?= e($g['topic'][0]) ?></b> <span class="small text-muted"><?= count($g['bad']) ?> <?= count($g['bad']) === 1 ? 'item' : 'items' ?>: <?= e(implode(', ', array_map(fn($c) => $c['ref'], $g['bad']))) ?></span></div>
            <?php if ($g['project']): ?><a class="small" href="/clients/<?= $id ?>/roadmap"><i class="fas fa-road me-1"></i>On the roadmap (<?= e(ucfirst($g['project']['status'])) ?>)</a>
            <?php elseif ($canEdit): ?><select class="form-select form-select-sm w-auto" name="g[<?= e($g['key']) ?>]" aria-label="Quarter for <?= e($g['topic'][0]) ?>"><option value="">Not now</option>
              <?php foreach ($quarters as $qs => $ql): ?><option value="<?= e($qs) ?>"><?= e($ql) ?></option><?php endforeach; ?></select><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($canEdit): ?><button class="btn btn-primary mt-2" name="go" value="next">Add and continue <i class="fas fa-arrow-right ms-1"></i></button><?php else: ?><a class="btn btn-primary mt-2" href="<?= e("$base?step=7") ?>">Continue</a><?php endif; ?>
      </form>
    </div>

  <?php else: /* ---- 7 Finish: recurring tasks, signatures, report, next review */ ?>
    <div class="card card-dark"><div class="card-header py-2"><h2 class="card-title h5 mt-1">7. Finish</h2></div>
      <form method="post" action="<?= e($act('finish')) ?>" class="card-body">
        <?= csrf_field() ?>
        <h3 class="h6">Recurring tasks</h3>
        <p class="small text-muted"><?= e($fw['name']) ?> asks for these regularly. Finishing adds the ones <?= e($client['name']) ?> doesn't have yet to its plan; each shows on the roadmap and the To do list when it's due, and needs a note or a document when it's done.</p>
        <ul class="list-group list-group-flush mb-3">
          <?php foreach ($kinds as $k => [$label, $what, $every]): $t = $tasks[$k] ?? null; ?>
            <li class="list-group-item px-0 small d-flex flex-wrap gap-2" data-kind="<?= e($k) ?>"><span class="me-auto"><b><?= e($label) ?></b> · every <?= (int) ($t['every_months'] ?? $every) ?> months<div class="text-muted"><?= e($what) ?></div></span>
              <?= $t ? '<span class="badge text-bg-success align-self-start">On the plan · due ' . e(fmt_date($t['next_due'])) . '</span>' : '<span class="badge text-bg-light border align-self-start">Will be added</span>' ?></li>
          <?php endforeach; ?>
          <?php if (!$kinds): ?><li class="list-group-item px-0 small text-muted">No recurring tasks for this framework.</li><?php endif; ?>
        </ul>
        <h3 class="h6">Sign off</h3>
        <ul class="small">
          <li>Send the policies for signature or acknowledgment from each document's page (step 4 lists them).</li>
          <li><a href="/clients/<?= $id ?>/report/compliance/<?= $fid ?>" target="_blank">Print the <?= e($fw['name']) ?> report</a> for the client or their auditor.</li>
        </ul>
        <label class="form-label small" for="next-review">Next review of <?= e($fw['name']) ?></label>
        <input type="date" class="form-control form-control-sm w-auto mb-3" id="next-review" name="next_review" value="<?= e((string) ($link['next_review'] ?? '')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
        <?php if ($canEdit): ?><button class="btn btn-success"><i class="fas fa-flag-checkered me-1"></i><?= empty($setup['finished_at']) ? 'Finish setup' : 'Save' ?></button><?php endif; ?>
      </form>
    </div>
  <?php endif; ?>
  </div>
</div>

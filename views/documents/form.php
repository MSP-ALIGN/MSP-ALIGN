<?php
/**
 * 2.41.0 A client's answers for its documents (Docs\Fields), as one form: every field, or one template's fields
 * (then "Save and make the draft"), or one document's fields (then back to it), or a rebuild of an older document.
 * Each field shows where its value comes from: typed (an answer), from a connection (used until someone types an
 * answer), or an AI suggestion waiting to be confirmed ("Use" makes it the answer). was[] carries the typed value the
 * page was loaded with, so saving changes only the answers changed here.
 * Vars: $client, $tpl (template row or null), $doc (document row or null), $rebuild (older document or null), $keys,
 * $groups, $values (Fields::values), $canEdit, $aiUsable, $existing (the template's newest document or null).
 * All text is escaped with e(); values are plain text.
 */
use Align\Docs\Fields;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$answered = count(array_filter($keys ?? array_keys(Fields::FIELDS), fn($k) => $values[$k]['value'] !== ''));
$total = count($keys ?? Fields::FIELDS);
$hidden = fn() => ($tpl ? '<input type="hidden" name="template" value="' . (int) $tpl['id'] . '">' : '')
    . ($doc ? '<input type="hidden" name="doc" value="' . (int) $doc['id'] . '">' : '')
    . ($rebuild ? '<input type="hidden" name="rebuild" value="' . (int) $rebuild['id'] . '">' : '');
?>
<div class="card card-dark" id="doc-form">
  <div class="card-header py-2">
    <h1 class="card-title mt-2 h3"><i class="fas fa-fw fa-list-check me-2"></i><?php if ($rebuild): ?>Rebuild “<?= e($rebuild['title']) ?>” with the form<?php elseif ($tpl): ?>Form: <?= e($tpl['name']) ?><?php elseif ($doc): ?>Form: <?= e($doc['title']) ?><?php else: ?>Answers for documents<?php endif; ?>
      <span class="badge text-bg-light ms-1" data-answered><?= $answered ?> of <?= $total ?> answered</span></h1>
    <div class="card-tools d-flex">
      <?php if ($canEdit && $aiUsable && $answered < $total): /* AI suggests only empty fields; suggestions wait to be confirmed */ ?>
        <form method="post" action="/clients/<?= $cid ?>/document-answers/suggest" class="me-2"><?= csrf_field() ?><?= $hidden() ?>
          <button class="btn btn-sm btn-default" data-suggest><i class="fas fa-wand-magic-sparkles me-1"></i>Suggest answers with AI</button></form>
      <?php endif; ?>
      <a class="btn btn-sm btn-default" href="<?= $doc ? '/documents/' . (int) $doc['id'] : "/clients/$cid/documents" ?>"><i class="fas fa-arrow-left me-1"></i><?= $doc ? 'Back to the document' : 'Documents' ?></a>
    </div>
  </div>
  <div class="card-body">
    <?php if ($rebuild): ?>
      <p class="mb-2">Answer the questions below, then <b>Save and rebuild</b> makes a new draft from the template “<?= e($tpl['name']) ?>” with these answers. “<?= e($rebuild['title']) ?>” stays exactly as it is, so you can copy anything from it you want to keep.</p>
    <?php elseif ($tpl): ?>
      <p class="mb-2">These answers fill “<?= e($tpl['name']) ?>”. They're shared with every document of <?= e($client['name']) ?> that uses them, so you answer each one once.
        <?php if ($existing): ?><br><span class="text-muted small"><?= e($client['name']) ?> already has “<a href="/documents/<?= (int) $existing['id'] ?>"><?= e($existing['title']) ?></a>” (<?= e(\Align\Docs\Documents::STATUSES[$existing['status']][0] ?? $existing['status']) ?>) from this template. Saving updates the answers in its drafts; <b>Save and make the draft</b> makes another one.</span><?php endif; ?></p>
    <?php elseif ($doc): ?>
      <p class="mb-2">The answers this document uses. Saving updates them in this document<?= $doc['status'] === 'draft' ? '' : '’s draft when you choose Update' ?> and in the client's other drafts. Text you edited by hand in a document is never replaced.</p>
    <?php else: ?>
      <p class="mb-2">Answer once, and every document of <?= e($client['name']) ?> made from a template uses the answer. Draft documents update when an answer changes; an Active document shows <b>Update available</b> and gets a new draft, so what was approved or signed never changes. Text you edited by hand in a document is never replaced.</p>
    <?php endif; ?>
    <p class="small text-muted mb-3"><span class="badge text-bg-success me-1">Answer</span> typed by your team · <span class="badge text-bg-info me-1">From a connection</span> used until someone types an answer · <span class="badge text-bg-warning me-1">AI suggested, confirm</span> counts only after <b>Use</b>.</p>
    <?php if (!$groups): ?>
      <p class="empty-state mb-0">This <?= $tpl ? 'template' : 'document' ?> has no named fields. Fill its blanks in the editor (the Blanks list shows them).</p>
    <?php else: ?>
    <form method="post" action="/clients/<?= $cid ?>/document-answers" data-answers-form>
      <?php /* (2.41.0 review) Enter in a field submits the form's first button: make that a plain save, never Use or Dismiss */ ?>
      <?php if ($canEdit): ?><button class="visually-hidden" name="go" value="save" tabindex="-1" aria-hidden="true">Save</button><?php endif; ?>
      <?= csrf_field() ?><?= $hidden() ?>
      <?php foreach ($groups as $g => $fields): ?>
        <h2 class="h6 text-uppercase text-muted mt-3 mb-2"><?= e(Fields::GROUPS[$g]) ?></h2>
        <div class="row g-3">
          <?php foreach ($fields as $k => [$label, $help, $type, , $options, $max]): $v = $values[$k]; $typed = $v['source'] === 'typed' ? $v['value'] : ''; ?>
            <div class="col-md-6 col-xl-4" data-field-row="<?= e($k) ?>">
              <label class="form-label mb-1 d-flex align-items-center gap-1" for="ans-<?= e($k) ?>"><?= e($label) ?>
                <?php if ($v['source'] === 'typed'): ?><span class="badge text-bg-success">Answer</span>
                <?php elseif ($v['source'] === 'auto'): ?><span class="badge text-bg-info" title="<?= e($v['from']) ?>">From a connection</span><?php endif; ?></label>
              <?php /* was[]: the typed answer when the page was loaded (a connection's value is never saved as an answer unless typed) */ ?>
              <input type="hidden" name="was[<?= e($k) ?>]" value="<?= e($typed) ?>">
              <?php if ($type === 'select'): ?>
                <select class="form-select form-select-sm" id="ans-<?= e($k) ?>" name="answers[<?= e($k) ?>]" <?= $canEdit ? '' : 'disabled' ?>>
                  <option value="">Not answered</option>
                  <?php foreach ($options as $o): ?><option value="<?= e($o) ?>" <?= $typed === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?>
                </select>
              <?php else: ?>
                <input class="form-control form-control-sm" id="ans-<?= e($k) ?>" name="answers[<?= e($k) ?>]" value="<?= e($typed) ?>" maxlength="<?= (int) $max ?>"
                  type="<?= $type === 'email' ? 'email' : 'text' ?>" placeholder="<?= e($v['source'] === 'auto' ? $v['auto'] : '') ?>" <?= $canEdit ? '' : 'readonly' ?>>
              <?php endif; ?>
              <?php if ($v['source'] === 'auto'): ?><div class="form-text text-info-emphasis">From <?= e($v['from']) ?>: <b><?= e($v['auto']) ?></b>. Type an answer to use something else.</div>
              <?php elseif ($v['source'] === 'typed' && $v['auto'] !== '' && $v['auto'] !== $v['value']): /* "Refresh from connections": the connection now says something else */ ?>
                <div class="form-text text-warning-emphasis" data-differs>Your connections say <b><?= e($v['auto']) ?></b> (<?= e($v['from']) ?>). Clear the answer to use it.</div><?php endif; ?>
              <?php if ($v['suggested'] !== '' && $v['source'] !== 'typed'): ?>
                <div class="alert alert-warning py-1 px-2 mt-1 mb-0 small d-flex align-items-center gap-2" data-suggestion>
                  <span><span class="badge text-bg-warning me-1">AI suggested, confirm</span><?= e($v['suggested']) ?></span>
                  <?php if ($canEdit): ?><button class="btn btn-xs btn-primary ms-auto" name="accept[<?= e($k) ?>]" value="1">Use</button>
                    <button class="btn btn-xs btn-light" formaction="/clients/<?= $cid ?>/document-answers/dismiss" name="key" value="<?= e($k) ?>" formnovalidate>Dismiss</button><?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="form-text"><?= e($help) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($canEdit): ?>
        <div class="mt-4 d-flex flex-wrap gap-2">
          <?php if ($rebuild): ?><button class="btn btn-primary" name="go" value="rebuild"><i class="fas fa-rotate me-1"></i>Save and rebuild</button>
          <?php elseif ($tpl): ?><button class="btn btn-primary" name="go" value="create"><i class="fas fa-file-circle-plus me-1"></i>Save and make the draft</button><?php endif; ?>
          <button class="btn <?= $tpl || $rebuild ? 'btn-default' : 'btn-primary' ?>" name="go" value="save"><i class="fas fa-check me-1"></i><?= $doc ? 'Save and go back' : 'Save answers' ?></button>
        </div>
      <?php endif; ?>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php
use Align\Docs\Documents;

/**
 * Portal documents. (2.36.0) One list with category tabs (counts) and a search box instead of a card per category:
 * the tabs and the search filter it in the browser (app.js data-filter-table / data-filter-pills; nothing is sent).
 * Categories show with client-facing names (Documents::PORTAL_LABELS); documents the PSA shares with the client (2.35.0)
 * are one more tab, named for the MSP. What waits for this person's signature or acknowledgment stays on top.
 * Every value is escaped.
 * @var array $docs; array $signWaiting (2.34.0, portal/_signatures); array $ackWaiting (2.34.0, portal/_acknowledgments);
 *   array $shared (2.35.0, the PSA's shared documents); array $provider (company name, from PortalController::render)
 */
$shared = $shared ?? [];
$company = (string) ($provider['company'] ?? '') ?: 'your IT team';
$counts = array_count_values(array_column($docs, 'category'));
$order = array_flip(array_keys(Documents::CATEGORIES));
usort($docs, fn($a, $b) => [($order[$a['category']] ?? 99), mb_strtolower($a['title'])] <=> [($order[$b['category']] ?? 99), mb_strtolower($b['title'])]);
$label = fn(string $cat) => Documents::PORTAL_LABELS[$cat] ?? (Documents::CATEGORIES[$cat][0] ?? 'Other');
$total = count($docs) + count($shared);
?>
<div class="d-flex flex-wrap align-items-center portal-page-head">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-file-lines me-2 text-secondary"></i>Documents</h1>
    <div class="small text-muted">Policies, plans and guides your IT provider keeps for your organization.</div></div>
</div>
<?= \Align\View::fetch('portal/_signatures', ['signWaiting' => $signWaiting ?? []]) /* 2.34.0 */ ?>
<?= \Align\View::fetch('portal/_acknowledgments', ['ackWaiting' => $ackWaiting ?? []]) /* 2.34.0 */ ?>
<?php if (!$total): ?><div class="card"><?= \Align\View::fetch('partials/empty', ['icon' => 'fa-file-lines', 'text' => 'No documents have been shared with you yet.']) ?></div>
<?php else: ?>
<div class="card">
  <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
    <?php /* (2.36.0) category tabs: narrow the list to one category; counts of what's in each */ ?>
    <div class="nav nav-pills portal-doc-tabs me-auto" data-filter-pills="portal-docs" role="group" aria-label="Document categories">
      <button type="button" class="nav-link py-1 active" data-value="" aria-pressed="true">All <span class="badge text-bg-light border ms-1"><?= $total ?></span></button>
      <?php foreach (array_keys(Documents::CATEGORIES) as $cat): if (empty($counts[$cat])) continue; ?>
        <button type="button" class="nav-link py-1" data-value="<?= e($cat) ?>" aria-pressed="false"><?= e($label($cat)) ?> <span class="badge text-bg-light border ms-1"><?= (int) $counts[$cat] ?></span></button>
      <?php endforeach; ?>
      <?php if ($shared): ?><button type="button" class="nav-link py-1" data-value="psa" aria-pressed="false">From <?= e($company) ?> <span class="badge text-bg-light border ms-1"><?= count($shared) ?></span></button><?php endif; ?>
    </div>
    <div class="portal-doc-search"><?= \Align\View::fetch('portal/_search', ['target' => 'portal-docs', 'placeholder' => 'Search documents']) ?></div>
  </div>
  <ul class="list-group list-group-flush" id="portal-docs">
    <?php foreach ($docs as $d): [, $icon, $tone] = Documents::CATEGORIES[$d['category']] ?? Documents::CATEGORIES['other']; ?>
      <li class="list-group-item py-2 d-flex align-items-center" data-cat="<?= e($d['category']) ?>">
        <i class="fas fa-fw <?= e($icon) ?> text-<?= e($tone) ?> me-2" aria-hidden="true"></i>
        <div class="me-auto min-w-0"><a href="/portal/documents/<?= (int) $d['id'] ?>" class="fw-bold"><?= e($d['title']) ?></a>
          <div class="small text-muted"><?= e($label($d['category'])) ?> · updated <?= e(fmt_date($d['updated_at'])) ?></div></div>
        <?php if ($d['kind'] === 'file'): // 2.20.0 ?><a href="/portal/documents/<?= (int) $d['id'] ?>/file?download=1" class="btn btn-xs btn-default ms-2" title="Download (<?= e((\Align\Docs\Files::TYPES[$d['file_type']][1] ?? 'File') . ', ' . \Align\Docs\Files::size((int) $d['file_size'])) ?>)" aria-label="Download <?= e($d['title']) ?>"><i class="fas fa-download"></i></a>
        <?php else: ?><a href="/portal/documents/<?= (int) $d['id'] ?>?print=1" target="_blank" class="btn btn-xs btn-default ms-2" title="Print or save as PDF" aria-label="Print <?= e($d['title']) ?>"><i class="fas fa-print"></i></a><?php endif; ?>
      </li>
    <?php endforeach; ?>
    <?php foreach ($shared as $d): // 2.35.0 documents the PSA shares with this client, read live when opened ?>
      <li class="list-group-item py-2 d-flex align-items-center" data-cat="psa">
        <i class="fas fa-fw fa-book text-secondary me-2" aria-hidden="true"></i>
        <div class="me-auto min-w-0"><a href="/portal/shared/<?= e(rawurlencode((string) $d['psa_id'])) ?>" class="fw-bold"><?= e($d['name']) ?></a>
          <div class="small text-muted"><?= e(implode(' · ', array_filter(['From ' . $company, $d['description'] ?: null, $d['updated_at'] ? 'updated ' . fmt_date($d['updated_at']) : null]))) ?></div></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?= \Align\View::fetch('portal/_search', ['target' => 'portal-docs', 'none' => true]) ?>
</div>
<?php endif; ?>

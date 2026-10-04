<?php
/**
 * A link that doesn't open anything (unknown, expired, replaced or cancelled): the same page for all of them, so it
 * never says which. @var array $company
 */
?>
<div class="card card-body text-center py-5">
  <i class="fas fa-link-slash fa-2x text-muted mb-3"></i>
  <h1 class="h4">This signing link has expired or was replaced</h1>
  <p class="text-muted mb-1">For your security, signing links only work for a limited time, and a new link turns the old one off.</p>
  <p class="mb-0">Please ask <?= e($company['name']) ?> for a new link<?= $company['phone'] ? ': ' . e($company['phone']) : '' ?><?= $company['email'] ? ' · ' . e($company['email']) : '' ?>.</p>
</div>

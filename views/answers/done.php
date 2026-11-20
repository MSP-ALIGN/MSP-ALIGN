<?php
/** 2.40.0 After the client sent their answers. @var array $company, int $count */
?>
<div class="card card-body text-center py-5">
  <i class="fas fa-circle-check fa-2x text-success mb-3"></i>
  <h1 class="h4">Thank you</h1>
  <p class="mb-0">Your <?= (int) $count ?> <?= $count === 1 ? 'answer was' : 'answers were' ?> sent to <?= e($company['name']) ?>. You can close this page.</p>
</div>

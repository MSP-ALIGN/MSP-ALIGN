<div class="card card-body text-center py-5">
  <i class="fas fa-lock fa-2x text-muted mb-3"></i>
  <h4><?= e($title ?? 'Not available') ?></h4>
  <p class="text-muted mb-3"><?= e($message ?? 'That page is not available.') ?></p>
  <div><a href="/portal" class="btn btn-primary">Back to home</a></div>
</div>

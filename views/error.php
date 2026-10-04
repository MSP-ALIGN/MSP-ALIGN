<?php
/**
 * Error page (403, 404, 500). Vars: $title, $message. Security: both are escaped. Callers pass text meant for
 * people (safe_error() for an exception); the 500 handler shows exception details only when config.php has debug on.
 */
?><div class="error-page mt-5">
  <h2 class="headline text-warning"><i class="fas fa-triangle-exclamation"></i></h2>
  <div class="error-content">
    <h3><?= e($title ?? 'Error') ?></h3>
    <p><?= e($message ?? '') ?></p>
    <a href="/" class="btn btn-primary btn-sm">Back to dashboard</a>
  </div>
</div>

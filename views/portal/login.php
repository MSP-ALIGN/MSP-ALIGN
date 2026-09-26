<p class="login-box-msg"><b>Client portal</b><br><span class="text-muted small">Sign in to see your technology plan, budget and documents.</span></p>
<form method="post" action="/portal/login">
  <?= csrf_field() ?>
  <div class="input-group mb-3">
    <input type="email" name="email" class="form-control" placeholder="Email" autocomplete="username" required autofocus>
    <div class="input-group-append"><div class="input-group-text"><span class="fas fa-envelope"></span></div></div>
  </div>
  <div class="input-group mb-3">
    <input type="password" name="password" class="form-control" placeholder="Password" autocomplete="current-password" required>
    <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
  </div>
  <button class="btn btn-primary btn-block">Sign in</button>
  <?php if (\Align\Mail\Notifications::enabled('client_portal_reset') && \Align\Mail\Mail::ready()): ?>
    <p class="mt-3 mb-0 small text-center"><a href="/portal/forgot">Forgot your password?</a></p>
  <?php else: ?>
  <p class="mt-3 mb-0 small text-muted text-center">Forgot your password or need access? Ask your IT provider to send you a new sign-in link.</p>
  <?php endif; ?>
</form>

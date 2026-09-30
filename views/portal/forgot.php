<p class="login-box-msg"><b>Reset your password</b><br><span class="text-muted small">Enter the email you sign in with. If it has a portal account, we'll email a link to choose a new password.</span></p>
<form method="post" action="/portal/forgot">
  <?= csrf_field() ?>
  <div class="input-group mb-3">
    <input type="email" name="email" class="form-control" placeholder="Email" autocomplete="username" required autofocus>
    <span class="input-group-text"><span class="fas fa-envelope"></span></span>
  </div>
  <button class="btn btn-primary w-100">Email me a reset link</button>
  <p class="mt-3 mb-0 small text-center"><a href="/portal/login">Back to sign in</a></p>
</form>

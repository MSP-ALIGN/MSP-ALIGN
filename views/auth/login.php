<p class="login-box-msg"><?= e(\Align\Branding::loginMessage()) ?></p>
<form method="post" action="/login">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <div class="input-group mb-3">
    <input type="email" name="email" class="form-control" placeholder="Email" autocomplete="username" required autofocus>
    <span class="input-group-text"><span class="fas fa-envelope"></span></span>
  </div>
  <div class="input-group mb-3">
    <input type="password" name="password" class="form-control" placeholder="Password" autocomplete="current-password" required>
    <span class="input-group-text"><span class="fas fa-lock"></span></span>
  </div>
  <button class="btn btn-primary w-100">Sign in</button>
</form>

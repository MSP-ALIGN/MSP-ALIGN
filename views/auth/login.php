<p class="login-box-msg"><?= e(\Align\Branding::loginMessage()) ?></p>
<form method="post" action="/login">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <div class="input-group mb-3">
    <input type="email" name="email" class="form-control" placeholder="Email" autocomplete="username" required autofocus>
    <div class="input-group-append"><div class="input-group-text"><span class="fas fa-envelope"></span></div></div>
  </div>
  <div class="input-group mb-3">
    <input type="password" name="password" class="form-control" placeholder="Password" autocomplete="current-password" required>
    <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
  </div>
  <button class="btn btn-primary btn-block">Sign in</button>
</form>

<form method="post" action="/login" class="stack">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <label>Email<input type="email" name="email" autocomplete="username" required autofocus></label>
  <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
  <button class="btn primary">Sign in</button>
</form>

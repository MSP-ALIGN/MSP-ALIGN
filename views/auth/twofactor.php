<?php
/** The two-factor code form, shown only while a password step is pending in this session. */
?><p class="login-box-msg">Enter the 6-digit code from your authenticator app</p>
<form method="post" action="/login/2fa">
  <?= csrf_field() ?>
  <div class="input-group mb-3">
    <input name="code" class="form-control text-center" inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code" placeholder="123456" required autofocus>
    <span class="input-group-text"><span class="fas fa-key"></span></span>
  </div>
  <?php if (($days = \Align\Remember::days()) > 0): ?>
    <div class="form-check mb-3 text-start"><input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
      <label class="form-check-label small" for="remember">Remember this browser for <?= $days ?> days <span class="text-muted">(your password is still asked for; not on a shared computer)</span></label></div>
  <?php endif; ?>
  <button class="btn btn-primary w-100">Verify</button>
  <p class="mt-3 mb-0 text-center"><a href="/login" class="small">Start over</a></p>
</form>

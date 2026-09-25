<p class="login-box-msg">Enter the 6-digit code from your authenticator app</p>
<form method="post" action="/login/2fa">
  <?= csrf_field() ?>
  <div class="input-group mb-3">
    <input name="code" class="form-control text-center" inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code" placeholder="123456" required autofocus>
    <div class="input-group-append"><div class="input-group-text"><span class="fas fa-key"></span></div></div>
  </div>
  <button class="btn btn-primary btn-block">Verify</button>
  <p class="mt-3 mb-0 text-center"><a href="/login" class="small">Start over</a></p>
</form>

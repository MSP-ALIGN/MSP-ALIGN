<form method="post" action="/login/2fa" class="stack">
  <?= csrf_field() ?>
  <p class="muted">Enter the 6-digit code from your authenticator app.</p>
  <label>Code<input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code" required autofocus></label>
  <button class="btn primary">Verify</button>
  <a href="/login" class="muted small">Start over</a>
</form>

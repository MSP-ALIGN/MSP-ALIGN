"""2.2.1 review (sign-in, sessions and crypto): a regression check for each fix in src/Auth.php, src/Security.php and
src/Totp.php. Each check fails on the code before the fix."""
from lib import *
import json, base64, subprocess

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def P(v):
    """A Python value as a PHP expression (through JSON, so newlines and non-ASCII arrive exactly)."""
    return "json_decode('" + json.dumps(v).replace("\\", "\\\\").replace("'", "\\'") + "', true)"
def new_user(email, pw, secret):
    q("delete from users where email=%s", email)
    phpv(f"Align\\DB::insert('users', ['email' => {json.dumps(email)}, 'name' => 'Core Auth', 'role' => 'tech', 'password_hash' => Align\\Security::hashPassword({json.dumps(pw)}),"
         f" 'totp_secret_enc' => Align\\Crypto::encrypt({json.dumps(secret)}), 'totp_enabled' => 1, 'is_active' => 1, 'must_change_password' => 0]);")
    return q("select id from users where email=%s", email)[0]["id"]
def password_step(s, email, pw):
    return s.post(B + "/login", data={"_csrf": csrf(s, "/login"), "email": email, "password": pw})
def signed_in(s): return s.get(B + "/clients", allow_redirects=False).status_code == 200

EMAIL = "core-auth-a@example.com"
PW = "Granite-Meadow-Lantern-58"
SEC = base64.b32encode(b"core auth e2e key A!").decode()  # 20 bytes, 32 characters
q("delete from login_attempts")
uid = new_user(EMAIL, PW, SEC)

# ---- a password reset or "sign out everywhere" after the password step voids the pending code step
s = requests.Session()
r = password_step(s, EMAIL, PW)
ok(r.url.endswith("/login/2fa"), "password accepted: the code step is next")
q("update users set session_version = session_version + 1 where id=%s", uid)  # what an admin reset / sign out everywhere does
r = s.post(B + "/login/2fa", data={"_csrf": csrf(s, "/login/2fa"), "code": totp(SEC)})
ok(not signed_in(s), "after the account's sessions were ended, the old password step plus a valid code doesn't sign in")
ok(r.url.endswith("/login") and "took too long" in flash(r.text), "...it asks to start again: " + flash(r.text))
ok(q("select totp_last_step from users where id=%s", uid)[0]["totp_last_step"] is None, "...and the code wasn't used up")
s2 = requests.Session(); password_step(s2, EMAIL, PW)
s2.post(B + "/login/2fa", data={"_csrf": csrf(s2, "/login/2fa"), "code": totp(SEC)})
ok(signed_in(s2), "starting again with the password and a code signs in as before")

# ---- a session ended elsewhere: its sign-in form works first time (no "session expired")
q("update users set session_version = session_version + 1 where id=%s", uid)
t = s2.get(B + "/login").text
ok('name="email"' in t and not errs(t), "a revoked session opening /login gets the sign-in form")
tok = re.search(r'name="_csrf" value="([^"]+)"', t).group(1)
r = s2.post(B + "/login", data={"_csrf": tok, "email": EMAIL, "password": PW})
ok(r.status_code != 419 and r.url.endswith("/login/2fa"), f"...and signing in from that form works (status {r.status_code}, {r.url})")

# ---- safe redirect paths: $ no longer matches before a trailing newline
ok(phpv("echo Align\\Security::safePath(" + P("/clients\n") + ");") == "/", "safePath refuses a path ending in a newline (header() would refuse the Location line)")
ok(phpv("echo Align\\Security::safePath(" + P("/clients?show=all") + ");") == "/clients?show=all", "...a normal path still passes")

# ---- password rules count characters, not bytes, and catch part of the name
def problem(pw, ctx=None): return phpv("var_export(Align\\Security::passwordProblem(" + P(pw) + ", " + P(ctx or []) + "));")
ok("at least 12" in problem("пароль"), "six Cyrillic letters (12 bytes) are too short: " + problem("пароль"))
ok("repetitive" in problem("абяабяабяабяабя"), "three different letters repeated are too repetitive, also in Cyrillic: " + problem("абяабяабяабяабя"))
ok("name" in problem("Quillfeather-Lake-77", ["aq@example.example", "Avery Quillfeather"]), "a password built on the surname contains the user's name")
ok(problem("Correct-Horse-Battery-9", ["sec-new@example.com", "Sec Newbie"]) == "NULL", "...a good password still passes")

# ---- a blank or garbled TOTP secret never accepts a code (its key would be empty, its codes guessable)
ok(phpv("var_export(Align\\Totp::verifyStep('!!!!', Align\\Totp::code('!!!!')));") == "NULL", "a secret that decodes to nothing accepts no code")
ok(phpv("var_export(Align\\Totp::verifyStep('', Align\\Totp::code('')));") == "NULL", "an empty secret accepts no code")
ok(phpv(f"var_export(Align\\Totp::verifyStep('{SEC}', Align\\Totp::code('{SEC}')) !== null);") == "true", "...a real secret still works")

q("delete from users where email=%s", EMAIL); q("delete from login_attempts")
done()

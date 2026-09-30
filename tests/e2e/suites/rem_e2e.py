"""1.45.1: the managed-services estimate follows each ITFlow recurring invoice's own frequency (monthly, yearly...),
and "Remember this browser" skips the two-factor code (never the password) for a number of days."""
from lib import *
import json, subprocess, re

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()

# ---- managed-services estimate from recurring invoices
out = phpv("echo Align\\Budget\\Billing::syncFromPsa(Align\\Providers\\Providers::psa());")
b = {r["client_id"]: r for r in q("select * from psa_billing")}
ok(b.get(1) and float(b[1]["monthly"]) == 1850.0 and b[1]["detail"] == "1 monthly", "a monthly recurring invoice counts as it is (the one-off project invoice left out): " + out)
ok(b.get(2) and float(b[2]["monthly"]) == 975.0 and b[2]["method"] == "all invoices", "a client without recurring invoices: the last 3 months averaged, as before")
ok(b.get(3) and float(b[3]["monthly"]) == 500.0 and b[3]["detail"] == "1 yearly", "a yearly recurring invoice ($6,000) counts as $500 a month, not $6,000; a schedule that stopped billing drops out")
ok(b.get(4) and float(b[4]["monthly"]) == 200.0 and "billed once so far" in b[4]["detail"], "a recurring invoice billed once counts as yearly until a second one shows it's monthly")
t = login("tech@example.com", TECH_PASSWORD).get(B + "/clients/3/budget").text
ok("$500/mo estimated from ITFlow recurring invoices (1 yearly)" in H.unescape(t) and not errs(t), "the budget says how the estimate was worked out")
cases = [
    ([["2026-08-30", 100], ["2026-09-29", 100]], "monthly", 100),
    ([["2025-01-31", 6000], ["2026-01-31", 6000]], "yearly", 500),
    ([["2026-06-01", 500], ["2026-07-01", 500], ["2026-09-01", 500]], "monthly", 500),  # August cancelled: still monthly
    ([["2026-09-01", 2400], ["2026-09-04", 2400]], "yearly (billed once so far)", 200),  # sent again by hand 3 days later
    ([["2025-06-01", 50], ["2025-07-01", 50], ["2025-08-01", 1200], ["2026-08-01", 1200]], "yearly", 100),
    ([["2026-06-01", 100], ["2026-07-01", 100], ["2026-07-10", 100], ["2026-08-01", 100], ["2026-09-01", 100]], "monthly", 100),
]
for invs, label, per in cases:
    r = json.loads(phpv(f"echo json_encode(Align\\Budget\\Billing::schedule(json_decode({json.dumps(json.dumps(invs))}, true), '2026-09-30'));"))
    ok(r and r["label"] == label and round(r["amount"] / r["months"], 2) == per, f"schedule {[i[0] for i in invs]}: {label}, {per}/mo ({r})")
ok(phpv("var_dump(Align\\Budget\\Billing::schedule([['2026-01-01', 300], ['2026-02-01', 300]], '2026-09-30'));") == "NULL", "a monthly schedule with nothing since February has stopped")

# ---- remember this browser (staff)
SEC = "NBSWY3DPEB3W64TMMQQGC3DMNBSWY3DP"
q("delete from users where email='rem@example.com'"); q("delete from login_attempts"); q("delete from remembered_browsers")
setting("remember_2fa_days", "14")
phpv("Align\\DB::insert('users', ['email' => 'rem@example.com', 'name' => 'Remy Tech', 'role' => 'tech', 'password_hash' => Align\\Security::hashPassword('Remember-Pass-2026'),"
     f" 'totp_secret_enc' => Align\\Crypto::encrypt('{SEC}'), 'totp_enabled' => 1, 'is_active' => 1, 'must_change_password' => 0]);")
def pw(s, p="Remember-Pass-2026"): return s.post(B + "/login", data={"_csrf": csrf(s, "/login"), "email": "rem@example.com", "password": p})
s1 = requests.Session(); r = pw(s1)
ok(r.url.endswith("/login/2fa") and "Remember this browser for 14 days" in r.text, "the code step offers to remember the browser for 14 days")
r = s1.post(B + "/login/2fa", data={"_csrf": csrf(s1, "/login/2fa"), "code": totp(SEC), "remember": "1"})
jar = {c.name: c.value for c in s1.cookies if c.name.endswith("ALIGNTRUST")}
row = q("select * from remembered_browsers where kind='staff'")
ok(r.url.endswith("/") and jar and row and len(row[0]["token_hash"]) == 64 and list(jar.values())[0] not in str(row), "signed in; the browser gets a cookie and only its hash is stored")
def again(extra=None, p="Remember-Pass-2026"):
    s = requests.Session()
    for k, v in (extra or jar).items(): s.cookies.set(k, v, domain="127.0.0.1", path="/")
    return s, pw(s, p)
s2, r = again()
ok(r.url.endswith("/") and s2.get(B + "/clients").url.endswith("/clients"), "the same browser signs in with the password only")
ok(re.fullmatch(r"remembered browser #\d+, no code asked", q("select detail from audit_log where action='login.success' order by id desc limit 1")[0]["detail"]), "...and the audit log says the code was skipped, and which remembered browser")
q("delete from users where email='rem2@example.com'")
phpv("Align\\DB::insert('users', ['email' => 'rem2@example.com', 'name' => 'Other Tech', 'role' => 'tech', 'password_hash' => Align\\Security::hashPassword('Other-Pass-20266'),"
     f" 'totp_secret_enc' => Align\\Crypto::encrypt('{SEC}'), 'totp_enabled' => 1, 'is_active' => 1, 'must_change_password' => 0]);")
ob = requests.Session()
for k, v in jar.items(): ob.cookies.set(k, v, domain="127.0.0.1", path="/")
r = ob.post(B + "/login", data={"_csrf": csrf(ob, "/login"), "email": "rem2@example.com", "password": "Other-Pass-20266"})
ok(r.url.endswith("/login/2fa"), "one person's remembered browser doesn't skip the code for someone else")
q("delete from users where email='rem2@example.com'")
s3, r = again(p="wrong-password-123")
ok("/login" in r.url and not r.url.endswith("/2fa") and s3.get(B + "/clients", allow_redirects=False).status_code == 302, "a remembered browser still needs the right password")
q("delete from login_attempts")
s4, r = again({"ALIGNTRUST": "0" * 64}); ok(r.url.endswith("/login/2fa"), "a made-up cookie gets the code step")
t = s2.get(B + "/account").text
ok("Remembered browsers" in t and "this browser" in t, "the Account page lists remembered browsers")
adm = login("admin@example.com", "LongPassword123!")
def days(n): return adm.post(B + "/settings", data={**form(adm, "/settings", 'action="/settings"'), "remember_2fa_days": n})
days("0")
s5, r = again(); ok(r.url.endswith("/login/2fa") and "Remember this browser" not in r.text, "set to 0 days: off, and remembered browsers ask for the code again")
ok(not q("select id from remembered_browsers") and q("select detail from audit_log where action='settings.remember_2fa' order by id desc limit 1")[0]["detail"].startswith("14 → 0 days (off;"),
   "turning it off forgets every remembered browser, and the log says from what to what")
days("14")
s6, r = again(); ok(r.url.endswith("/login/2fa"), "turned back on: forgotten browsers still ask for the code")
s6.post(B + "/login/2fa", data={"_csrf": csrf(s6, "/login/2fa"), "code": totp(SEC), "remember": "1"})
jar = {c.name: c.value for c in s6.cookies if c.name.endswith("ALIGNTRUST")}
rid = q("select id from remembered_browsers where kind='staff' order by id desc limit 1")[0]["id"]
s6.post(B + "/account/remembered", data={"_csrf": csrf(s6, "/account"), "id": str(rid)})
s7, r = again(); ok(r.url.endswith("/login/2fa"), "a forgotten browser asks for the code")
q("update remembered_browsers set token_hash=%s where id=%s", "x" * 64, rid)
# a new remembered browser, then "sign out everywhere else" forgets it
s8 = requests.Session(); pw(s8); s8.post(B + "/login/2fa", data={"_csrf": csrf(s8, "/login/2fa"), "code": totp(SEC), "remember": "1"})
jar = {c.name: c.value for c in s8.cookies if c.name.endswith("ALIGNTRUST")}
s9, r = again(); ok(r.url.endswith("/"), "remembered again")
s9.post(B + "/account/2fa", data={"_csrf": csrf(s9, "/account"), "action": "signout_all"})
s10, r = again(); ok(r.url.endswith("/login/2fa"), "sign out everywhere forgets remembered browsers")
s11 = requests.Session(); pw(s11); s11.post(B + "/login/2fa", data={"_csrf": csrf(s11, "/login/2fa"), "code": totp(SEC), "remember": "1"})
jar = {c.name: c.value for c in s11.cookies if c.name.endswith("ALIGNTRUST")}
s11.post(B + "/account/password", data={"_csrf": csrf(s11, "/account"), "current": "Remember-Pass-2026", "new": "Remember-Pass-2027", "confirm": "Remember-Pass-2027"})
s12, r = again(p="Remember-Pass-2027"); ok(r.url.endswith("/login/2fa"), "a password change forgets remembered browsers")
q("update remembered_browsers set created_at = now() - interval 15 day where kind='staff'")
ok(phpv("$_COOKIE['ALIGNTRUST'] = " + json.dumps(list(jar.values())[0]) + "; var_dump(Align\\Remember::valid('staff', (int) Align\\DB::value(\"SELECT id FROM users WHERE email='rem@example.com'\")));") == "NULL", "older than the days set: not valid")

# ---- remember this browser (client portal)
PSEC = "MFZWIZLTMZXW6YTBMFZWIZLTMZXW6YTB"
q("delete from portal_users where email='rem@client.example'")
phpv("Align\\DB::insert('portal_users', ['client_id' => 1, 'email' => 'rem@client.example', 'name' => 'Remi Client', 'password_hash' => Align\\Security::hashPassword('Portal-Remember-99'),"
     f" 'totp_secret_enc' => Align\\Crypto::encrypt('{PSEC}'), 'totp_enabled' => 1, 'is_active' => 1, 'can_devices' => 1]);")
def ppw(s): return s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": "rem@client.example", "password": "Portal-Remember-99"})
p1 = requests.Session(); r = ppw(p1)
ok(r.url.endswith("/portal/login/2fa") and "Remember this browser" in r.text, "the portal's code step offers it too")
p1.post(B + "/portal/login/2fa", data={"_csrf": csrf(p1, "/portal/login/2fa"), "code": totp(PSEC), "remember": "1"})
pj = {c.name: c.value for c in p1.cookies if c.name.endswith("ALIGNPORTALTRUST")}
p2 = requests.Session()
for k, v in pj.items(): p2.cookies.set(k, v, domain="127.0.0.1", path="/portal")
r = ppw(p2); ok(pj and r.url.endswith("/portal") and p2.get(B + "/portal", allow_redirects=False).status_code == 200, "a remembered portal browser signs in with the password only")
st = requests.Session()
st.cookies.set("ALIGNTRUST", list(pj.values())[0], domain="127.0.0.1", path="/")  # the portal token, under the staff cookie's name
st.post(B + "/login", data={"_csrf": csrf(st, "/login"), "email": "rem@example.com", "password": "Remember-Pass-2027"})
ok(st.get(B + "/clients", allow_redirects=False).status_code == 302, "a portal browser's token doesn't count for a staff sign-in")

pid = q("select id from portal_users where email='rem@client.example'")[0]["id"]
tech = login("tech@example.com", TECH_PASSWORD)
tech.post(B + f"/portal-users/{pid}", data={"_csrf": csrf(tech, "/clients/1/portal"), "action": "disable"})
tech.post(B + f"/portal-users/{pid}", data={"_csrf": csrf(tech, "/clients/1/portal"), "action": "enable"})
p3 = requests.Session()
for k, v in pj.items(): p3.cookies.set(k, v, domain="127.0.0.1", path="/portal")
r = ppw(p3); ok(r.url.endswith("/portal/login/2fa"), "disabling the portal user forgets their remembered browsers")

q("delete from portal_users where email='rem@client.example'"); q("delete from users where email='rem@example.com'"); q("delete from remembered_browsers"); q("delete from login_attempts")
done()

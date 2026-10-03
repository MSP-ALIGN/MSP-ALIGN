"""2.2.1 review of the client portal (PortalAuth, PortalController, PortalAdminController, Submissions, portal views):
long email addresses sign in and reset without an error page, a new sign-in drops an authenticator key someone else
started setting up in the same browser, suggestions are stored as clean single-line text with valid JSON details,
a meeting's join link is only a link when it is http(s), and staff changes to portal access say what changed."""
import atexit, html as H
from lib import *

SEC_A = "ONSWG4TFORZWK3DPONSWG4TFORZWK3DP"
SEC_L = "OBQXE5DBNRSGK4TPOBQXE5DBNRSGK4TP"
SEC_S = "IFBEGRCFIZDUQSKKIFBEGRCFIZDUQSKK"
PW = "Quartz-Lantern-Field-31"
# 190 characters, the longest address the portal_users column holds (a 64-character local part, labels under 64)
LONG = "l" * 64 + "@" + "d" * 60 + "." + "e" * 56 + ".example"
assert len(LONG) == 190


def cleanup():
    q("delete from portal_submissions where title like 'PQ %%'")
    q("delete from portal_users where email like '%%@portalq.example' or email=%s", LONG)
    q("delete from meetings where title like 'PQ %%'")
    q("delete from login_attempts")


cleanup()
atexit.register(cleanup)


def mkuser(email, secret, client=1, **perms):
    p = {"can_roadmap": 1, "can_budget": 1, "can_devices": 1, "can_documents": 1, "can_approve": 1, "can_contacts": 0, "can_submit": 1, **perms}
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{client},"email"=>"{email}","name"=>"Quinn Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        + ",".join(f'"{k}"=>{v}' for k, v in p.items()) + f',"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{secret}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    return q("select id from portal_users where email=%s", email)[0]["id"]


def plogin(email, secret, pw=PW):
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": pw})
    if r.url.endswith("/portal/login/2fa"):
        r = s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(secret)})
    return s, r


# ---- PortalAuth::key / forgot: an email longer than the login_attempts column no longer gives an error page
s = requests.Session()
r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": "x" * 250 + "@portalq.example", "password": "wrong-password"})
ok(r.status_code == 200 and r.url.endswith("/portal/login") and "incorrect" in flash(r.text), f"a 250-character email gets the normal 'incorrect' answer, not an error page ({r.status_code})")
q("delete from login_attempts")
mkuser(LONG, SEC_L)
sl, r = plogin(LONG, SEC_L)
ok(r.status_code == 200 and r.url.endswith("/portal") and sl.get(B + "/portal", allow_redirects=False).status_code == 200,
   f"a portal user with a 190-character email can sign in (password and code) ({r.status_code} {r.url})")
ok(q("select count(*) n from login_attempts where email like 'portal:sha256:%%'")[0]["n"] >= 1, "the long address's attempts are counted under its hash")
q("delete from login_attempts")
fs = requests.Session()
fr = fs.get(B + "/portal/forgot")
if fr.url.endswith("/portal/forgot"):
    long_valid = "r" * 64 + "@" + "s" * 63 + "." + "t" * 63 + ".example"  # 200 characters, a valid address
    r = fs.post(B + "/portal/forgot", data={"_csrf": csrf(fs, "/portal/forgot"), "email": long_valid})
    ok(r.status_code == 200 and "If that email has a portal account" in flash(r.text), f"a reset request for a 200-character address gets the usual answer ({r.status_code})")
    ok(q("select count(*) n from login_attempts where email like 'reset:sha256:%%'")[0]["n"] == 1, "...and is still counted for the rate limit")
else:
    print("SKIP self-service reset is off here (mail not set up), so the long-address reset check didn't run")
q("delete from login_attempts")

# ---- PortalAuth::completeLogin: an authenticator key half set up by someone else in this browser isn't carried over
mkuser("alex@portalq.example", SEC_A)
sa, r = plogin("alex@portalq.example", SEC_A)
r = sa.post(B + "/portal/account/2fa", data={"_csrf": csrf(sa, "/portal/account"), "action": "begin"})
key_a = re.search(r'<code[^>]*>([A-Z2-7 ]+)</code>', r.text)
ok(key_a is not None, "the signed-in user starts replacing their authenticator (a new key is shown)")
key_a = key_a.group(1).replace(" ", "") if key_a else "NOKEY"
tok_b = "b" * 64
php('Align\\DB::insert("portal_users", ["client_id"=>1,"email"=>"blake@portalq.example","name"=>"Blake Newcomer","is_active"=>1,"can_documents"=>1,'
    f'"invite_token_hash"=>hash("sha256","{tok_b}"),"invite_expires_at"=>date("Y-m-d H:i:s", time()+3600)]);')
r = sa.post(B + f"/portal/invite/{tok_b}", data={"_csrf": csrf(sa, f"/portal/invite/{tok_b}"), "password": "Harbor-Willow-Stone-58", "confirm": "Harbor-Willow-Stone-58"})
t = r.text
ok(r.url.endswith("/portal/account") and "Blake Newcomer" in t, "an invite opened in the same browser signs the new person in")
ok(key_a not in t.replace(" ", "") and "Set up two-factor sign-in" in t, "the new person isn't offered the other user's half-set-up authenticator key")
ok(q("select invite_token_hash from portal_users where email='blake@portalq.example'")[0]["invite_token_hash"] is None, "the invite link is used up")
ok("expired or was already used" in requests.get(B + f"/portal/invite/{tok_b}").text, "and can't be opened again")

# ---- Submissions::fromPost: one-line names, clean notes, valid JSON even from invalid UTF-8, strict dates
sp, r = plogin("alex@portalq.example", SEC_A)
r = sp.post(B + "/portal/suggest/license", data={"_csrf": csrf(sp, "/portal/licensing"), "name": "PQ Line\r\nBcc: evil@attacker.example", "vendor": "Ven\tdor",
    "notes": b"first\x00\x1b[31m\r\nsecond \xff end", "expire_date": "2026-01-31\n", "seats": "2"})
row = q("select * from portal_submissions where title like 'PQ Line%%'")
ok(row and "\r" not in row[0]["title"] and "\n" not in row[0]["title"] and row[0]["title"] == "PQ Line Bcc: evil@attacker.example", "a suggested name is one line: line breaks become spaces " + repr(row[0]["title"] if row else None))
try:
    d = json.loads(row[0]["data"]) if row else {}
except ValueError:
    d = {}
ok(d.get("name") == row[0]["title"] if row else False, "the details are stored as valid JSON even with an invalid UTF-8 byte in the notes")
ok(d.get("notes") == "first[31m\nsecond ? end" and d.get("vendor") == "Ven dor", "notes keep their line break, lose control characters; the bad byte becomes ? " + repr(d.get("notes")))
ok("expire_date" in d and d["expire_date"] is None, "a date with a trailing line break is refused " + repr(d.get("expire_date")))
r = sp.post(B + "/portal/suggest/budget", data={"_csrf": csrf(sp, "/portal/budget"), "name": b"PQ Fiber \xfe line", "amount": "45"})
row = q("select title, data from portal_submissions where title like 'PQ Fiber%%'")
ok(r.status_code == 200 and row and row[0]["title"] == "PQ Fiber ? line" and json.loads(row[0]["data"])["amount"] == 45, f"an invalid UTF-8 byte in the name is saved as ? instead of an error page ({r.status_code})")
t = sp.get(B + "/portal/licensing").text
ok("PQ Line Bcc: evil@attacker.example" in t and not errs(t), "the suggestion shows on the licensing page")

# ---- views/portal/meetings.php: the join link is only a link when it is http(s)
for title, url in (("PQ Script link", "javascript:alert(document.domain)"), ("PQ Teams link", "https://teams.example/join/abc")):
    q("insert into meetings (uid,client_id,title,type,status,starts_at,ends_at,video_url) values (uuid(),1,%s,'qbr','scheduled',now()+interval 2 day,now()+interval 2 day+interval 1 hour,%s)", title, url)
t = sp.get(B + "/portal/meetings").text
ok("PQ Script link" in t and "javascript:" not in t, "a javascript: join link isn't shown as a link")
ok('href="https://teams.example/join/abc"' in t, "an https join link still is")

# ---- PortalAdminController: audit entries say what access was granted or changed
staff = login("admin@example.com", "LongPassword123!")
r = staff.post(B + "/clients/1/portal", data={"_csrf": csrf(staff, "/clients/1/portal"), "name": "Casey Invitee", "email": "casey@portalq.example",
    "can_roadmap": "1", "can_documents": "1", "send_email": "0"})
a = q("select detail from audit_log where action='portal_user.invite' order by id desc limit 1")
ok(a and "casey@portalq.example" in a[0]["detail"] and "can_roadmap on" in a[0]["detail"] and "can_budget" not in a[0]["detail"], "the invite entry lists the access granted: " + (a[0]["detail"] if a else ""))
cid = q("select id from portal_users where email='casey@portalq.example'")[0]["id"]
staff.post(B + f"/portal-users/{cid}", data={"_csrf": csrf(staff, "/clients/1/portal"), "action": "save", "name": "Casey Invitee",
    "can_roadmap": "1", "can_documents": "1", "can_budget": "1", "can_submit": "1"})
a = q("select detail from audit_log where action='portal_user.update' order by id desc limit 1")
ok(a and "can_budget on" in a[0]["detail"] and "can_submit on" in a[0]["detail"] and "can_roadmap" not in a[0]["detail"], "the change entry lists only what changed: " + (a[0]["detail"] if a else ""))
staff.post(B + f"/portal-users/{cid}", data={"_csrf": csrf(staff, "/clients/1/portal"), "action": "save", "name": "Casey Renamed",
    "can_roadmap": "1", "can_documents": "1", "can_budget": "1", "can_submit": "1"})
a = q("select detail from audit_log where action='portal_user.update' order by id desc limit 1")
ok(a and 'name "Casey Invitee" to "Casey Renamed"' in a[0]["detail"], "a rename is in the entry too")

done()

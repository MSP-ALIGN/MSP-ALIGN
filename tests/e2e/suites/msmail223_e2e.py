"""2.23.0 Email: Set up with Microsoft. An admin signs in once (device code) and Align creates its own single-tenant app
in the MSP's tenant: both redirect URIs (email and staff calendars), only delegated permissions (sign-in, Mail.Send,
Calendars.ReadWrite) granted for the whole organization, Application.ReadWrite.OwnedBy with the app as its own owner,
and a certificate whose key stays in Align. Then Connect with Microsoft signs in as the sending mailbox and mail goes
out (refresh with the certificate). The form can't change the app's ids or credential, the certificate is replaced
in three steps, a failed setup deletes the half-made app and saves nothing, Remove forgets it, and only admins can do
any of it."""
import re, json, atexit, requests, html as H
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
HOME = "dddddddd-0000-4000-8000-00000000000d"
APP = "eeeeeeee-0000-4000-8000-00000000000e"
KEEP = q("select name, value, is_secret from settings where name like 'm365\\_%%' or name like 'mail\\_%%'")


def mock(body): return requests.post(M + "/mock/msmail", json=body).json()
def mstate(): return requests.get(M + "/mock/msmail-state").json()
def s(k): return (q("select value from settings where name=%s", k) or [{"value": None}])[0]["value"]
def has(k): return bool(q("select 1 from settings where name=%s and value is not null and value<>''", k))
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def post(path, data=None, who=None): return (who or st).post(B + path, data={"_csrf": csrf(who or st, "/integrations/email") if (who or st) is st else csrf(who, "/account"), **(data or {})})


def cleanup():
    q("delete from settings where name like 'm365\\_%%' or name like 'mail\\_%%'")
    for r in KEEP:
        q("insert into settings (name, value, is_secret) values (%s,%s,%s) on duplicate key update value=values(value), is_secret=values(is_secret)", r["name"], r["value"], r["is_secret"])
    requests.post(M + "/mock/msmail-reset")


atexit.register(cleanup)
requests.post(M + "/mock/msmail-reset")
q("delete from settings where name like 'm365\\_%%' or name in ('mail_mode','mail_from')")
setting("mail_provider", "microsoft")
setting("m365_login_base", M + "/msl")
setting("m365_graph_base", M + "/msg/v1.0")

# ---- the offer, admins only
p = st.get(B + "/integrations/email").text
ok("Set up with Microsoft" in p and 'action="/integrations/email/setup/start"' in p and not errs(p), "the email page offers Set up with Microsoft")
ok('name="m365_tenant"' in p and "your own app" in p, "registering your own app is still there")
r = tech.post(B + "/integrations/email/setup/start", data={"_csrf": csrf(tech, "/account")}, allow_redirects=False)
ok(r.status_code in (302, 403) and not mstate()["calls"], "a tech can't start the setup")

# ---- the device code sign-in
post("/integrations/email/setup/start")
p = st.get(B + "/integrations/email").text
ok("MAIL-1234" in p and "microsoft.com/devicelogin" in p and "Global Administrator" in p, "the page shows the code and where to enter it")
r = post("/integrations/email/setup/finish")
ok("isn't finished yet" in text(r.text) and "MAIL-1234" in r.text and not has("m365_app_auto"), "Continue before signing in keeps waiting, nothing saved")

# ---- a failed step deletes the half-made app and saves nothing
mock({"approved": True, "fail": "grant"})
r = post("/integrations/email/setup/finish")
ms = mstate()
ok(ms["app"] and ms["app"]["deleted"] and not has("m365_app_auto") and not has("m365_client_id") and "Global Administrator" in text(r.text),
   "a step that fails deletes the app it made and saves nothing: " + flash(r.text)[:120])
ok(q("select 1 from audit_log where action='email.app_failed'"), "the failure is audited")

# ---- the real setup
mock({"fail": "", "app": None, "keys": []})
post("/integrations/email/setup/start")
r = post("/integrations/email/setup/finish")
ms = mstate()
app = ms["app"]
ok(app and not app["deleted"] and "Created" in text(r.text) and "Connect with Microsoft" in text(r.text), "the app is created: " + flash(r.text)[:160])
ok(app["signInAudience"] == "AzureADMyOrg", "it's single tenant (only the MSP's own accounts can sign in)")
ok(sorted(app["redirectUris"]) == sorted([B + "/settings/email/callback", B + "/account/calendar/callback"]), "both redirect URIs: email and staff calendars: %s" % app["redirectUris"])
scopes = {a["id"] for a in app["access"] if a["type"] == "Scope"}
roles = {a["id"] for a in app["access"] if a["type"] == "Role"}
ok(len(scopes) == 9 and "10465720-29dd-4523-a11a-6a75c743c9d9" not in scopes and roles == {"18a4783c-866b-4cc7-a460-3d5e5662c884"},
   "only the nine delegated permissions (ids read from Graph) plus OwnedBy, no application permission on mail or calendars")
g = app["grants"][0] if app["grants"] else {}
ok(g.get("consentType") == "AllPrincipals" and g.get("clientId") == app["sp"] and set(g.get("scope", "").split()) == {"openid", "profile", "email", "offline_access", "User.Read", "Mail.Send", "Mail.Send.Shared", "Calendars.ReadWrite", "Calendars.ReadWrite.Shared"},
   "the delegated permissions are granted for the whole organization")
ok(app["granted"] == ["18a4783c-866b-4cc7-a460-3d5e5662c884"] and app["owners"] and app["owners"][0].endswith("/directoryObjects/" + app["sp"]),
   "it may change only itself, and owns itself (certificate replacement)")
ok(len(ms["keys"]) == 1 and "PRIVATE KEY" not in json.dumps(ms), "a certificate is registered; its private key never left Align")
ok(s("m365_app_auto") == "1" and s("m365_client_id") == APP and s("m365_tenant") == HOME and s("m365_auth") == "certificate" and s("mail_mode") == "delegated"
   and has("m365_key_pem") and has("m365_cert_pem") and not has("m365_client_secret") and s("m365_cert_expires"),
   "the settings: app, tenant, certificate auth, sign in as a mailbox")
ok(q("select 1 from audit_log where action='email.app_created'"), "audited")
p = st.get(B + "/integrations/email").text
ok("Align's own app" in p and 'name="m365_tenant"' not in p and 'name="m365_client_secret"' not in p and "Connect with Microsoft" in p and not errs(p),
   "the page shows the app, hides the hand-made app's fields and asks to connect the mailbox")

# ---- the form can't change the app
F = form(st, "/integrations/email")
st.post(B + "/integrations/email", data={**F, "m365_tenant": "evil.example", "m365_client_id": "11111111-2222-3333-4444-555555555555", "m365_auth": "secret",
                                          "m365_client_secret": "x", "mail_mode": "app", "mail_from": "alerts@examplemsp.example"})
ok(s("m365_tenant") == HOME and s("m365_client_id") == APP and s("m365_auth") == "certificate" and s("mail_mode") == "delegated" and not has("m365_client_secret")
   and s("mail_from") == "alerts@examplemsp.example", "a posted tenant, client ID, secret or app-only mode is ignored; other fields still save")

# ---- connect the sending mailbox, send
r = st.get(B + "/integrations/email/connect")
ok("Connected to Microsoft 365 as alerts@examplemsp.example" in text(r.text), "Connect with Microsoft signs in as the mailbox: " + flash(r.text)[:120])
la = mstate().get("last_authorize", {})
ok(la.get("client_id") == APP and "Mail.Send" in la.get("scope", "") and la.get("code_challenge_method") == "S256", "the sign-in uses Align's app with PKCE")
def test_send():
    """Sends a test email; [the page's message, the mock's calls during it, whether a test email reached the mock]."""
    before = len(mstate()["calls"])
    r = st.post(B + "/integrations/email/test", data={"_csrf": csrf(st, "/integrations/email"), "to": "someone@example.com"})
    ms = mstate()
    sent = [m for m in ms.get("mail", []) if (m.get("message") or {}).get("subject") == "MSP Align test email"]
    return flash(r.text), ms["calls"][before:], sent


msg, calls, sent = test_send()
ok(sent and sent[-1]["from"] == "alerts@examplemsp.example" and "Test email sent" in msg, "a test email goes out as the mailbox: " + msg[:120])
q("delete from settings where name='m365_token_cache'")
n = len(sent)
msg, calls, sent = test_send()
ok(len(sent) == n + 1 and any(c.get("grant") == "refresh_token" for c in calls), "a new access token comes from the refresh token, signed with the certificate: " + msg[:120])

# ---- certificate replacement in three steps
r = post("/integrations/email/setup/rotate")
ok(len(mstate()["keys"]) == 2 and has("m365_next_key_pem") and "switching" in text(r.text), "Replace certificate adds a new one first: " + flash(r.text)[:120])
q("update settings set value=%s where name='m365_next_since'", "2000-01-01 00:00:00")
old_exp = s("m365_cert_expires")
r = post("/integrations/email/setup/rotate")
ok(len(mstate()["keys"]) == 1 and not has("m365_next_key_pem") and not has("m365_old_key_id") and s("m365_rotated_at"), "then switches to it and removes the old one: " + flash(r.text)[:120])
q("delete from settings where name='m365_token_cache'")
n = len(sent)
msg, calls, sent = test_send()
ok(len(sent) == n + 1 and any(c.get("grant") == "refresh_token" for c in calls), "mail still goes out, signed with the new certificate: " + msg[:120])
ok(php("echo json_encode(Align\\Mail\\M365Setup::rotateIfDue());").stdout.strip() == "null", "the daily run does nothing while the certificate isn't due")

# ---- techs can't remove it; admins can
r = tech.post(B + "/integrations/email/setup/forget", data={"_csrf": csrf(tech, "/account")}, allow_redirects=False)
ok(r.status_code in (302, 403) and s("m365_app_auto") == "1", "a tech can't remove the app")
r = post("/integrations/email/setup/forget")
ok(not has("m365_app_auto") and not has("m365_client_id") and not has("m365_key_pem") and not has("m365_refresh_token") and "Removed" in text(r.text),
   "Remove forgets the app, its certificate and the mailbox sign-in")
p = st.get(B + "/integrations/email").text
ok("Set up with Microsoft" in p and 'name="m365_tenant"' in p, "the page offers the setup again")
ok(q("select 1 from audit_log where action='email.app_removed'"), "audited")
done()

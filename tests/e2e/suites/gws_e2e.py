"""2.6.3 Google Workspace for clients and email authentication, against the mock Google (tests/mock-server.php): the
MSP's service account key (checked, saved as a secret, never shown back; the client ID and scopes to copy); connecting
a client (domain-wide delegation missing, an unknown admin, a domain that isn't the customer's, another client's
domain); its editions in Licensing (users per edition, across pages; the price list, a license's own price, leaving
an edition out, retiring and restoring, refusing an empty answer); the security checks (2-Step Verification, super
admins, unused accounts, Drive sharing, third-party apps, less secure apps; unknown when the Policy API is refused);
only GET requests and the Policy API paced; a client's own service account; SPF, DKIM and DMARC for Google and
Microsoft 365 clients (pass, fail, a failed lookup); where results show (overview, compliance, alignment, health,
dashboard); the hourly sync steps; the API's rules for these licenses; roles; disconnecting."""
import re, json, subprocess, tempfile, os, html as H
from datetime import datetime, timedelta, timezone
from lib import *

API = B + "/api/v1"
C1, C2, C3 = 1, 2, 3
DOM, DOM2 = "example-gws.test", "other-gws.test"
ADMIN, ADMIN2 = "admin@" + DOM, "boss@" + DOM2
SA_EMAIL, SA_ID = "align@msp-project.iam.gserviceaccount.com", "104857600000000000001"
OWN_EMAIL, OWN_ID = "align@client-project.iam.gserviceaccount.com", "104857600000000000002"
SCOPES = ["https://www.googleapis.com/auth/admin.directory.customer.readonly", "https://www.googleapis.com/auth/admin.directory.user.readonly",
          "https://www.googleapis.com/auth/apps.licensing", "https://www.googleapis.com/auth/cloud-identity.policies.readonly"]
STD, PLUS, ARCH = "1010020028", "1010020025", "1010340001"


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def phpo(code): r = php(code); return (r.stdout + r.stderr).strip()
def mock(): return requests.get(M + "/mock/gws").json()
def gset(**kw): requests.post(M + "/mock/gws-set", data=json.dumps(kw))
def ago(days): return (datetime.now(timezone.utc) - timedelta(days=days)).strftime("%Y-%m-%dT%H:%M:%S.000Z")
def lic(cid, sku): r = q("select * from licenses where client_id=%s and source='gws' and gws_sku_id=%s", cid, sku); return r[0] if r else None
def sync(cid, checks=True): return json.loads(phpo(f'echo json_encode(Align\\Google\\Clients::syncClient({cid}, {"true" if checks else "false"}));'))
def sec(cid): return json.loads(q("select security_json from client_gws where client_id=%s", cid)[0]["security_json"] or "null")
def st_of(cid, k): return ((sec(cid) or {}).get("checks", {}).get(k) or {}).get("status")
def det(cid, k): return ((sec(cid) or {}).get("checks", {}).get(k) or {}).get("detail", "")
def ea(cid): r = q("select result_json from client_email_auth where client_id=%s", cid); return json.loads(r[0]["result_json"]) if r else None


def keypair():
    """A fresh RSA key: (private PEM, public PEM), made with openssl."""
    d = tempfile.mkdtemp()
    subprocess.run(["openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048", "-out", d + "/k.pem"], check=True, capture_output=True)
    subprocess.run(["openssl", "pkey", "-in", d + "/k.pem", "-pubout", "-out", d + "/p.pem"], check=True, capture_output=True)
    return open(d + "/k.pem").read(), open(d + "/p.pem").read()


def key_json(email, cid, pem): return json.dumps({"type": "service_account", "project_id": "p", "private_key_id": "abc123", "private_key": pem, "client_email": email, "client_id": cid})


def user(admin=False, enrolled=True, enforced=True, last=3, created=400, suspended=False):
    return {"isAdmin": admin, "isEnrolledIn2Sv": enrolled, "isEnforcedIn2Sv": enforced, "suspended": suspended, "archived": False,
            "lastLoginTime": ago(last) if last is not None else "1970-01-01T00:00:00.000Z", "creationTime": ago(created)}


def pol(typ, value, ou="03ph8a2z1", order=1.0, kind="ADMIN"):
    return {"name": "policies/x", "customer": "customers/C01abc", "type": kind, "policyQuery": {"orgUnit": "orgUnits/" + ou, "sortOrder": order, "query": "q"}, "setting": {"type": "settings/" + typ, "value": value}}


def cleanup():
    q("delete from licenses where source='gws' or name like 'ZzGws%%'")
    q("delete from client_gws"); q("delete from gws_prices")
    php('(require "' + ROOT + '/db/migrations/062_google_workspace.php")();')  # the price list as Google's editions again
    q("delete from client_email_auth"); q("delete from client_m365")
    q("delete from client_frameworks where client_id=%s and framework_id in (select id from compliance_frameworks where slug='m365-baseline')", C1)
    q("delete from settings where name like 'gwc\\_%%' or name='dns_mock_url'")
    q("delete from api_keys where name like 'gws %%'"); q("delete from api_rate"); q("delete from api_ip_rate")
    requests.get(M + "/mock/gws-reset")


cleanup()
import atexit
atexit.register(cleanup)
setting("gwc_token_url", M + "/gws-token")
setting("gwc_api_base", M + "/gws-api")
setting("dns_mock_url", M + "/dns")
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
tok = lambda s, p: csrf(s, p)
priv, pub = keypair()
opriv, opub = keypair()

# ---- the migration
ok(q("select count(*) n from information_schema.tables where table_schema=database() and table_name in ('client_gws','gws_prices','client_email_auth')")[0]["n"] == 3, "migration 062 adds the tables")
ok("'gws'" in q("select column_type from information_schema.columns where table_schema=database() and table_name='licenses' and column_name='source'")[0]["column_type"], "licenses.source takes gws")
ok(q("select count(*) n from gws_prices")[0]["n"] == 14, "the price list starts with Google's editions")
links = {r["ref"]: r["auto_check"] for r in q("select c.ref, c.auto_check from compliance_controls c join compliance_frameworks f on f.id=c.framework_id where f.slug='m365-baseline' and c.ref like 'M365-EXO-0%%' and c.ref <= 'M365-EXO-03'")}
ok(links == {"M365-EXO-01": "email_spf", "M365-EXO-02": "email_dkim", "M365-EXO-03": "email_dmarc"}, f"the baseline's email controls are linked: {links}")
php('(require "' + ROOT + '/db/migrations/062_google_workspace.php")();')
ok(q("select count(*) n from gws_prices")[0]["n"] == 14, "the migration can run again")

# ---- the integration page: saving the key
ok(st.get(B + "/integrations").text.count("Google Workspace (clients)") >= 1, "the Integrations page has the card")
ok(tech.get(B + "/integrations/google-workspace").status_code == 403, "techs can't open the integration page")
t = st.get(B + "/integrations/google-workspace").text
ok("Not set up" in t and "Admin SDK API" in t, "not set up yet: the steps are shown")
r = st.post(B + "/integrations/google-workspace/key", data={"_csrf": tok(st, "/integrations/google-workspace"), "key": '{"type": "authorized_user"}'})
ok("isn't a service account key" in flash(r.text) and not q("select 1 from settings where name='gwc_sa_json'"), "a file that isn't a service account key is refused")
r = st.post(B + "/integrations/google-workspace/key", data={"_csrf": tok(st, "/integrations/google-workspace")}, files={"key_file": ("k.json", key_json(SA_EMAIL, SA_ID, priv), "application/json")})
row = q("select value, is_secret from settings where name='gwc_sa_json'")
ok(row and row[0]["is_secret"] == 1 and "PRIVATE KEY" not in (row[0]["value"] or ""), "the key (uploaded as a file) is saved as an encrypted secret")
t = r.text
ok(SA_ID in t and SA_EMAIL in t and ",".join(SCOPES) in H.unescape(t) and "PRIVATE KEY" not in t, "the page shows the client ID and scopes to copy, never the key")
ok(q("select 1 from audit_log where action='gws.key_saved'"), "saving the key is audited")

# ---- connecting a client
gset(accounts={SA_EMAIL: {"client_id": SA_ID, "public_pem": pub}, OWN_EMAIL: {"client_id": OWN_ID, "public_pem": opub}},
     domains={DOM: {"customer": "C01abc", "name": "Example GWS Co", "admins": [ADMIN], "allowed": {},
                    "users": [user(admin=True), user(admin=True, enrolled=False, enforced=False), user(enrolled=False), user(last=200), user(last=None, created=10), user(suspended=True, enrolled=False)],
                    "licenses": [{"skuId": STD, "skuName": "Google Workspace Business Standard"}] * 4 + [{"skuId": ARCH, "skuName": "Google Workspace Archived User"}],
                    "policies": [pol("drive_and_docs.external_sharing", {"externalSharingMode": "ALLOWED", "warnForExternalSharing": False}, kind="SYSTEM", order=0.5),
                                 pol("api_controls.unconfigured_third_party_apps", {"accessLevel": "ALLOW_SIGN_IN_ONLY"}),
                                 pol("security.less_secure_apps", {"allowLessSecureApps": False}),
                                 pol("gmail.mail_delegation", {"enableMailDelegation": True})]},
              DOM2: {"customer": "C02xyz", "name": "Other", "admins": [ADMIN2], "allowed": {SA_ID: SCOPES}, "users": [user(admin=True)], "licenses": [{"skuId": STD, "skuName": "x"}]}},
     dns={DOM: ["v=spf1 include:_spf.google.com ~all", "google-site-verification=abc"], "_dmarc." + DOM: ["v=DMARC1; p=none; rua=mailto:d@" + DOM],
          "google._domainkey." + DOM: ["v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA"]})
t = tech.get(B + f"/clients/{C1}/connectors").text
ok('id="gws"' in t and SA_ID in t and "Domain-wide delegation" in t and "own Google Cloud" not in t, "the client's Connectors page has the card with what the super admin pastes (techs don't see the own-key form)")
conn = lambda s, d, a, **kw: s.post(B + f"/clients/{C1}/gws/connect", data={"_csrf": tok(s, f"/clients/{C1}/connectors"), "domain": d, "admin_email": a, **kw})
r = conn(tech, DOM, ADMIN)
ok("Domain-wide delegation" in flash(r.text) and SA_ID in flash(r.text) and not q("select 1 from client_gws"), "delegation not allowed yet: says where to add which client ID, nothing saved: " + flash(r.text)[:160])
gset(domains={DOM: {**mock()["domains"][DOM], "allowed": {SA_ID: SCOPES[:3]}}})
r = conn(tech, DOM, ADMIN)
ok("Domain-wide delegation" in flash(r.text), "a scope missing from the delegation is refused the same way")
gset(domains={DOM: {**mock()["domains"][DOM], "allowed": {SA_ID: SCOPES}}})
r = conn(tech, DOM, "nobody@" + DOM)
ok("doesn't know nobody@" in flash(r.text), "an admin Google doesn't know: says so")
r = conn(tech, "not a domain", ADMIN)
ok("primary domain" in flash(r.text), "a domain that isn't one is refused before calling Google")
r = conn(tech, "wrong.test", ADMIN)
ok("belongs to " + DOM + ", not wrong.test" in flash(r.text), "a domain that isn't the customer's is refused")
q("insert into licenses (client_id, source, name, seats, category, pricing) values (%s, 'manual', 'ZzGws G Suite Basic', 4, 'productivity', 'per_seat')", C1)
r = conn(tech, DOM, ADMIN)
row = q("select * from client_gws where client_id=%s", C1)[0]
ok(row["status"] == "connected" and row["customer_id"] == "C01abc" and row["org_name"] == "Example GWS Co" and row["mode"] == "msp" and row["key_enc"] is None, "connected: the customer's id and name, the MSP's account")
ok("Connected to Example GWS Co" in flash(r.text), "the tech is told: " + flash(r.text)[:120])
ok(q("select 1 from audit_log where action='gws.connected' and detail like %s", "%" + ADMIN + "%"), "connecting is audited (with the admin it acts as)")
r = tech.post(B + f"/clients/{C2}/gws/connect", data={"_csrf": tok(tech, f"/clients/{C2}/connectors"), "domain": DOM, "admin_email": ADMIN})
ok("already connected to another client" in flash(r.text), "the same Google Workspace can't be connected to a second client")

# ---- licenses
l = lic(C1, STD)
ok(l and l["seats"] == 4 and l["seats_used"] == 4 and l["name"] == "Google Workspace Business Standard" and l["vendor"] == "Google" and l["software_type"] == STD and l["price_source"] == "list",
   f"users per edition become a license, read across pages (4 users): {l and (l['seats'], l['name'])}")
a = lic(C1, ARCH)
ok(a and a["name"] == "Google Workspace Archived User", "an edition the list doesn't know takes Google's name, and joins the price list")
ok(q("select name from gws_prices where sku_id=%s", ARCH)[0]["name"] == "Google Workspace Archived User", "...on the price list")
t = st.get(B + f"/clients/{C1}/licenses").text
ok("Google Workspace" in t and "fa-google" in t and 'id="gws"' in t, "Licensing shows where it comes from, with a status line")
ok('id="gws-dupes"' in t and "ZzGws G Suite Basic" in t, "a license added by hand that looks like Google Workspace is offered for retiring once")
mid = q("select id from licenses where name='ZzGws G Suite Basic'")[0]["id"]
r = tech.post(B + f"/clients/{C1}/gws/dupes", data={"_csrf": tok(tech, f"/clients/{C1}/licenses"), "retire[]": [str(mid)]})
ok(q("select retired_reason from licenses where id=%s", mid)[0]["retired_reason"] == "align" and 'id="gws-dupes"' not in tech.get(B + f"/clients/{C1}/licenses").text, "...retired, and not offered again")
q("delete from licenses where name='ZzGws G Suite Basic'")
# the price list
pr = {f"prices[{STD}][name]": "Workspace Standard", f"prices[{STD}][unit_price]": "14.00", f"prices[{STD}][billing_cycle]": "monthly",
      f"prices[{ARCH}][name]": "Archived User", f"prices[{ARCH}][unit_price]": "", f"prices[{ARCH}][billing_cycle]": "monthly", f"prices[{ARCH}][skip]": "1"}
r = st.post(B + "/integrations/google-workspace/prices", data={"_csrf": tok(st, "/integrations/google-workspace"), **pr})
l = lic(C1, STD)
ok(float(l["unit_price"]) == 14 and l["name"] == "Workspace Standard", "the price list sets the name and price")
ok(lic(C1, ARCH)["retired_at"] is not None and lic(C1, ARCH)["retired_reason"] == "gws", "an edition left out is retired")
r = st.post(B + "/integrations/google-workspace/prices", data={"_csrf": tok(st, "/integrations/google-workspace"), f"prices[{STD}][unit_price]": "-3"})
ok("isn't a number between" in flash(r.text), "a negative price is refused")
# a license's own price
lid = l["id"]
form_ = {"_csrf": tok(st, f"/clients/{C1}/licenses"), "unit_price": "12.50", "billing_cycle": "monthly", "pricing": "per_seat", "category": "productivity", "name": "Hacked", "seats": "999"}
st.post(B + f"/licenses/{lid}", data=form_)
l = lic(C1, STD)
ok(float(l["unit_price"]) == 12.5 and l["price_source"] == "custom" and l["name"] == "Workspace Standard" and l["seats"] == 4, "a license's own price sticks; name and seats stay Google's")
st.post(B + f"/licenses/{lid}", data={**form_, "_csrf": tok(st, f"/clients/{C1}/licenses"), "use_list_price": "1"})
ok(float(lic(C1, STD)["unit_price"]) == 14 and lic(C1, STD)["price_source"] == "list", "...and goes back to the list")
r = st.post(B + f"/licenses/{lid}", data={"_csrf": tok(st, f"/clients/{C1}/licenses"), "action": "delete"})
ok(lic(C1, STD) and "can be retired but not deleted" in flash(r.text) and "Google Workspace" in flash(r.text), "Google Workspace licenses can't be deleted")
# syncing changes
d = mock()["domains"][DOM]
gset(domains={DOM: {**d, "licenses": [{"skuId": STD, "skuName": "x"}] * 2 + [{"skuId": PLUS, "skuName": "x"}, {"skuId": "Google-Apps-For-Business", "skuName": "G Suite Basic"}]}})
res = sync(C1, False)
ok(lic(C1, "Google-Apps-For-Business") and lic(C1, "Google-Apps-For-Business")["name"] == "G Suite Basic", "a legacy G Suite SKU id is kept too, with Google's name")
ok(any(c["path"].startswith("licensing") and "customerId=C01abc" in c["q"] for c in mock()["calls"]), "licenses are read by the customer id")
ok(res["error"] is None and lic(C1, STD)["seats"] == 2 and lic(C1, PLUS) and lic(C1, PLUS)["name"] == "Google Workspace Business Plus", f"the sync updates seats and adds a new edition: {res}")
gset(domains={DOM: {**d, "licenses": [{"skuId": PLUS, "skuName": "x"}]}})
sync(C1, False)
ok(lic(C1, STD)["retired_at"] is not None, "an edition nobody has any more is retired")
gset(domains={DOM: {**d, "licenses": [{"skuId": STD, "skuName": "x"}, {"skuId": PLUS, "skuName": "x"}]}})
sync(C1, False)
ok(lic(C1, STD)["retired_at"] is None and lic(C1, STD)["seats"] == 1, "...and restored when it's back")
gset(domains={DOM: {**d, "licenses": []}})
res = sync(C1, False)
ok("no license assignments" in (res["error"] or "") and lic(C1, STD)["retired_at"] is None, "an empty answer changes nothing and is an error")
ok(q("select last_error from client_gws where client_id=%s", C1)[0]["last_error"], "...kept on the client's row")
t = text(st.get(B + "/").text)
ok("Google Workspace licenses not syncing" in t, "the dashboard lists it")
gset(domains={DOM: d})
sync(C1, False)
ok(q("select last_error from client_gws where client_id=%s", C1)[0]["last_error"] is None, "a good sync clears it")

# ---- security checks (read when connecting)
s = sec(C1)
ok(s is not None, "the checks were read when connecting")
ok(st_of(C1, "gws_mfa_users") == "fail" and "3 of 5 active users enrolled" in det(C1, "gws_mfa_users"), "2-Step Verification: active users only (a suspended one doesn't count), across pages: " + det(C1, "gws_mfa_users"))
ok(st_of(C1, "gws_mfa_admins") == "fail" and "1 of 2 super admins" in det(C1, "gws_mfa_admins"), "a super admin without it fails")
ok(st_of(C1, "gws_mfa_enforced") == "fail" and "4 of 5" in det(C1, "gws_mfa_enforced"), "enforcement for every active user: " + det(C1, "gws_mfa_enforced"))
ok(st_of(C1, "gws_admin_count") == "pass" and "2 super admins" in det(C1, "gws_admin_count"), "two super admins pass")
ok(st_of(C1, "gws_stale") == "fail" and "1 of 5 active accounts unused" in det(C1, "gws_stale"), "an account unused 200 days fails (a new one never signed in doesn't): " + det(C1, "gws_stale"))
ok(st_of(C1, "gws_external_sharing") == "fail", "Drive sharing allowed without a warning (Google's default) fails")
ok(st_of(C1, "gws_third_party_apps") == "pass" and st_of(C1, "gws_less_secure_apps") == "pass", "third-party apps limited to sign-in, less secure apps off: pass")
calls = mock()["calls"]
ok(all(c["m"] in ("GET", "POST") for c in calls) and all(c["m"] == "GET" for c in calls if c["path"] != "/token"), "only GET requests reach Google's APIs (POST only for the token)")
pc = [c for c in calls if c["path"].startswith("cloudidentity")]
ok(pc and "settings%2F" in pc[0]["q"] and "gmail" not in pc[0]["q"], "the Policy API is asked only for the settings checked")
# an admin's policy overrides Google's default for the same org unit; a sub-unit with a looser setting fails
d = mock()["domains"][DOM]
sharing_ok = pol("drive_and_docs.external_sharing", {"external_sharing_mode": "ALLOWED", "warn_for_external_sharing": True, "allow_publishing_files": False}, order=1.0)
gset(domains={DOM: {**d, "policies": d["policies"] + [sharing_ok, pol("drive_and_docs.external_sharing", {"externalSharingMode": "ALLOWLISTED_DOMAINS"}, ou="sales", order=2.0)]}})
gset(clear_calls=True)
sync(C1)
ok(st_of(C1, "gws_external_sharing") == "pass", "sharing with a warning and no publishing (an admin's policy over the default; snake_case values too) passes")
pc = [c for c in mock()["calls"] if c["path"].startswith("cloudidentity")]
ok(len(pc) >= 3 and all(b["t"] - a["t"] >= 1.0 for a, b in zip(pc, pc[1:])), f"Policy API pages are a second apart ({len(pc)} pages)")
gset(domains={DOM: {**d, "policies": d["policies"] + [sharing_ok, pol("drive_and_docs.external_sharing", {"externalSharingMode": "ALLOWED"}, ou="sales", order=2.0),
                                                      pol("api_controls.unconfigured_third_party_apps", {"accessLevel": "ACCESS_LEVEL_UNSPECIFIED"}, ou="sales", order=3.0)]}})
sync(C1)
ok(st_of(C1, "gws_external_sharing") == "fail" and "1 of 2 org units" in det(C1, "gws_external_sharing"), "one org unit sharing freely fails, and says how many: " + det(C1, "gws_external_sharing"))
ok(st_of(C1, "gws_third_party_apps") == "fail", "an org unit letting users give any app access fails")
gset(domains={DOM: {**d, "policy_error": True}})
sync(C1)
ok(st_of(C1, "gws_external_sharing") == "unknown" and "super admin" in det(C1, "gws_external_sharing") and st_of(C1, "gws_mfa_users") == "fail", "Policy API refused: those three unknown (saying why), the rest still read")
gset(domains={DOM: {**d, "users": [user(admin=True), user(admin=True), user(), user()]}})
sync(C1)
ok(all(st_of(C1, k) == "pass" for k in ["gws_mfa_users", "gws_mfa_admins", "gws_mfa_enforced", "gws_admin_count", "gws_stale"]), "a well-run domain passes")
gset(domains={DOM: {**d, "disabled_apis": ["admin.googleapis.com"]}})
sync(C1)
ok(st_of(C1, "gws_mfa_users") == "unknown" and "Admin SDK API" in det(C1, "gws_mfa_users"), "an API not turned on: unknown, saying which to turn on")
gset(domains={DOM: d})
sync(C1)

# ---- email authentication
e = ea(C1)
ok(e and e["domain"] == DOM and e["provider"] == "google", "the domain was checked when connecting")
ok(e["checks"]["email_spf"]["status"] == "pass" and e["checks"]["email_dkim"]["status"] == "pass", "SPF ending ~all and a DKIM key at the google selector pass")
ok(e["checks"]["email_dmarc"]["status"] == "fail" and "p=none" in e["checks"]["email_dmarc"]["detail"], "DMARC at p=none fails (monitoring only)")
gset(dns={DOM: ["v=spf1 include:a ~all", "v=spf1 include:b -all"], "_dmarc." + DOM: ["v=DMARC1; p=reject"], "google._domainkey." + DOM: []})
r = tech.post(B + f"/clients/{C1}/email-auth/check", data={"_csrf": tok(tech, f"/clients/{C1}/connectors")})
e = ea(C1)
ok(e["checks"]["email_spf"]["status"] == "fail" and "2 SPF records" in e["checks"]["email_spf"]["detail"], "two SPF records fail")
ok(e["checks"]["email_dmarc"]["status"] == "pass" and e["checks"]["email_dkim"]["status"] == "fail" and "Gmail" in e["checks"]["email_dkim"]["detail"], "DMARC reject passes; no DKIM key fails, saying where to turn it on")
ok("Checked " + DOM in flash(r.text), "Check now says what it found")
gset(dns={DOM: ["v=spf1 include:a ?all"]}, dns_fail=["_dmarc." + DOM])
php("echo Align\\Domains\\EmailAuth::refreshDue(true);")
e = ea(C1)
ok(e["checks"]["email_spf"]["status"] == "fail" and e["checks"]["email_dmarc"]["status"] == "unknown", "SPF ending ?all fails; a failed lookup is unknown, not failed")
gset(dns={DOM: ["v=spf1 include:_spf.google.com -all"], "_dmarc." + DOM: ["v=DMARC1; p=quarantine"], "google._domainkey." + DOM: ["v=DKIM1; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA"]}, dns_fail=[])
php("echo Align\\Domains\\EmailAuth::refreshDue(true);")
ok(all(c["status"] == "pass" for c in ea(C1)["checks"].values()), "SPF, DKIM and DMARC all set: pass")
ok(phpo("echo Align\\Domains\\EmailAuth::refreshDue();") == "nothing due", "checked once a day: nothing due right after")
# a Microsoft 365 client: its tenant's default domain, with Microsoft's selectors; an onmicrosoft.com one is skipped
q("insert into client_m365 (client_id, status, tenant_id, tenant_name, tenant_domain) values (%s, 'connected', '33333333-aaaa-4bbb-8ccc-000000000003', 'M', 'm365client.test')", C3)
q("insert into client_m365 (client_id, status, tenant_id, tenant_name, tenant_domain) values (%s, 'connected', '22222222-aaaa-4bbb-8ccc-000000000002', 'N', 'contoso.onmicrosoft.com')", C2)
gset(dns={"m365client.test": ["v=spf1 include:spf.protection.outlook.com -all"], "selector2._domainkey.m365client.test": ["v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GN"]})
php("echo Align\\Domains\\EmailAuth::refreshDue();")
e = ea(C3)
ok(e and e["provider"] == "m365" and e["checks"]["email_dkim"]["status"] == "pass" and "selector2" in e["checks"]["email_dkim"]["detail"], "a Microsoft 365 client is checked too, at Microsoft's DKIM selectors")
ok(e["checks"]["email_dmarc"]["status"] == "fail" and "No DMARC" in e["checks"]["email_dmarc"]["detail"], "no DMARC record fails")
ok(ea(C2) is None, "an onmicrosoft.com domain isn't checked")
q("delete from client_m365 where client_id=%s", C3)
php("echo Align\\Domains\\EmailAuth::refreshDue();")
ok(ea(C3) is None, "a result is dropped once the client has nothing connected")
q("delete from client_m365")

# ---- where the results show
t = st.get(B + f"/clients/{C1}").text
card = text(t.split('id="gws-security"')[1].split("</ul>")[0]) if 'id="gws-security"' in t else ""
ok("2-Step Verification" in card and "super admins" in card, "the client overview has the Google Workspace security card")
ok('id="email-auth"' in t and "DMARC" in text(t.split('id="email-auth"')[1][:3000]), "...and the email authentication card")
ok('id="security"' in t, "the Security area links to the cards")
ok('id="gws-security"' not in st.get(B + f"/clients/{C2}").text, "a client without Google Workspace has no card")
vt = viewer.get(B + f"/clients/{C1}").text
ok('id="gws-security"' in vt and "/connectors" not in vt and "/email-auth/check" not in vt, "viewers see the cards without Connectors links or Check now")
sc = phpo(f'echo json_encode(Align\\Health\\SecurityChecks::stored([{C1}]));')
ok('"gws_mfa_users"' in sc and '"email_spf"' in sc, "the health score's Security area counts Google Workspace and email checks together")
row = q("select security from client_health where client_id=%s and day=curdate()", C1)
# 3 of 8 Google Workspace checks pass (admins, apps, less secure apps) and all 3 email checks: 6 of 11 = 55
ok(row and row[0]["security"] == 55, f"the health score stores it: 6 of 11 known checks pass = 55 ({row and row[0]['security']})")
fw = q("select id from compliance_frameworks where slug='m365-baseline'")[0]["id"]
q("insert ignore into client_frameworks (client_id, framework_id) values (%s, %s)", C1, fw)
t = st.get(B + f"/clients/{C1}/compliance/{fw}").text
blk = t.split("M365-EXO-03")[1][:2500] if "M365-EXO-03" in t else ""
ok("p=quarantine" in H.unescape(blk) and 'data-value="met"' in blk, "a linked compliance control shows the DMARC check and suggests Met")
ok("gws_external_sharing" in st.get(B + f"/frameworks/{fw}").text and "email_dmarc" in st.get(B + f"/frameworks/{fw}").text, "the framework editor offers the new checks")
ind = json.loads(phpo(f'echo json_encode(Align\\Alignment\\Alignment::indicators([], null, null, false, Align\\Health\\SecurityChecks::indicators({C1})));'))
ok(ind["gws_admin_count"]["suggest"] == "aligned" and ind["gws_mfa_users"]["suggest"] == "misaligned" and ind["email_spf"]["suggest"] == "aligned", "alignment hints for the new checks")
ok("gws_stale" in json.loads(phpo('echo json_encode(array_keys(Align\\Alignment\\Alignment::checks()));')), "standards can use them")

# ---- the hourly sync
align("sync")
out = json.dumps(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
ok("Google Workspace licenses" in out and "Email authentication" in out, "the sync has the two new steps: " + out[-300:])

# ---- the API
key = phpo('[$i,$t]=Align\\Api\\Keys::create("gws api", Align\\Api\\Keys::allScopes(), null, null, 5000, null, 1); echo $t;')
h = {"Authorization": "Bearer " + key, "Content-Type": "application/json"}
lid = lic(C1, STD)["id"]
r = requests.patch(API + f"/licenses/{lid}", headers=h, json={"seats": 50})
ok(r.status_code == 422 and "Google Workspace" in r.text, f"the API refuses changing what Google owns ({r.status_code})")
r = requests.patch(API + f"/licenses/{lid}", headers=h, json={"unit_price": 9})
ok(r.status_code == 200 and r.json()["data"]["price_source"] == "custom" and r.json()["data"]["source"] == "gws", "...but takes a price (its own from then on)")
r = requests.delete(API + f"/licenses/{lid}", headers=h)
ok(r.status_code == 409 and "managed_in_gws" in r.text, "...and won't delete it")

# ---- a client's own service account (admins)
r = st.post(B + f"/clients/{C2}/gws/connect", data={"_csrf": tok(st, f"/clients/{C2}/connectors"), "domain": DOM2, "admin_email": ADMIN2, "own": "1", "key": "nope"})
ok("whole JSON key file" in flash(r.text), "an own key that isn't one is refused")
r = tech.post(B + f"/clients/{C2}/gws/connect", data={"_csrf": tok(tech, f"/clients/{C2}/connectors"), "domain": DOM2, "admin_email": ADMIN2, "own": "1", "key": key_json(OWN_EMAIL, OWN_ID, opriv)})
ok("Only an admin" in flash(r.text) and not q("select 1 from client_gws where client_id=%s", C2), "techs can't save an own key")
d2 = mock()["domains"][DOM2]
gset(domains={DOM2: {**d2, "allowed": {OWN_ID: SCOPES}}})
r = st.post(B + f"/clients/{C2}/gws/connect", data={"_csrf": tok(st, f"/clients/{C2}/connectors"), "domain": DOM2, "admin_email": ADMIN2, "own": "1", "key": key_json(OWN_EMAIL, OWN_ID, opriv)})
row = q("select * from client_gws where client_id=%s", C2)[0]
ok(row["mode"] == "own" and row["key_client_id"] == OWN_ID and row["key_enc"] and "PRIVATE" not in row["key_enc"] and lic(C2, STD), "connected with the client's own key (encrypted), its licenses synced")
t = st.get(B + f"/clients/{C2}/connectors").text
ok(OWN_ID in t and "PRIVATE KEY" not in t and "Saved; paste a new key" in t, "the page shows the own key's client ID, never the key")
r = tech.post(B + f"/clients/{C2}/gws/connect", data={"_csrf": tok(tech, f"/clients/{C2}/connectors"), "domain": DOM2, "admin_email": ADMIN2})
ok("Only an admin" in flash(r.text) and q("select mode from client_gws where client_id=%s", C2)[0]["mode"] == "own", "techs can't switch a client using its own key back to yours (which would drop the key)")
st.post(B + "/integrations/google-workspace/forget", data={"_csrf": tok(st, "/integrations/google-workspace")})
ok(sync(C2)["error"] is None, "a client with its own key keeps syncing without the MSP's key")
ok("isn't set up" in (sync(C1)["error"] or ""), "...while one using the MSP's stops, saying why")
ok(q("select 1 from audit_log where action='gws.key_forgotten'"), "removing the key is audited")

# ---- roles and disconnecting
ok(viewer.get(B + f"/clients/{C1}/connectors").status_code == 403, "viewers can't open Connectors")
r = viewer.post(B + f"/clients/{C1}/gws/disconnect", data={"_csrf": tok(viewer, f"/clients/{C1}")})
ok(q("select 1 from client_gws where client_id=%s", C1), "viewers can't disconnect")
r = tech.post(B + f"/clients/{C1}/gws/disconnect", data={"_csrf": tok(tech, f"/clients/{C1}/connectors")})
ok(not q("select 1 from client_gws where client_id=%s", C1) and lic(C1, PLUS)["retired_reason"] == "gws", "disconnecting retires its licenses")
ok(ea(C1) is None, "...and drops its email result (its domain came from this connection)")
ok("Domain-wide delegation" in flash(r.text), "...and says how to remove the access in Google")
ok('id="gws-security"' not in st.get(B + f"/clients/{C1}").text, "the card goes with it")
ok(not errs(st.get(B + f"/clients/{C1}").text) and not errs(st.get(B + f"/clients/{C2}/connectors").text), "no PHP errors on the pages")
done()

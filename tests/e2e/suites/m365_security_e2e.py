"""2.6.1 Microsoft 365 security checks, against the mock Microsoft: each check (pass, fail, unknown with why: a licence
the tenant lacks, or permissions not approved yet), the users list followed across pages, security defaults and
Conditional Access; the client overview card; the health score's Security area (and its weight); suggested answers for
compliance controls and alignment standards linked to a check (the built-in ones linked by the migration); the app
gaining the new permissions by itself and clients approving them again, with licenses syncing meanwhile.
2.6.2: the Connectors page lets its forms lead to Microsoft's sign-in (form-action), the app's permissions are brought
up to date when someone approves or an admin opens the integration page (not only by the daily run), the token's
granted permissions name what's missing, and a tenant approved minutes ago gets "Microsoft can take a few minutes"."""
import re, json, html as H
from datetime import date, datetime, timedelta, timezone
from lib import *

HOME = "aaaaaaaa-0000-4000-8000-000000000001"
T1 = "11111111-aaaa-4bbb-8ccc-000000000001"
C1, C2 = 1, 2
ORG, USR = "498476ce-e0fe-48b0-b801-37ba7e2685c6", "df021288-bdef-4463-88db-98f22de89214"
SEC_ROLES = ["bf394140-e372-4bf9-a898-299cfc7564e5", "246dd0d5-5bd0-4def-940b-0421030a5b68", "b0afded3-3588-46d8-8b3d-9842eff778da", "483bed4a-2ad3-4361-a73b-c83ccdbdc53c",
             "38d9df27-64da-44fd-b7c5-a6fbac20248f", "230c1aed-a721-4c5d-9cb4-a90514e508ef"]


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def phpo(code): r = php(code); return (r.stdout + r.stderr).strip()
def mock(): return requests.get(M + "/mock/m365c").json()
def mset(**kw): requests.post(M + "/mock/m365c-set", data=json.dumps(kw))
def sync(cid, force=True): return json.loads(phpo(f'echo json_encode(Align\\M365\\Tenants::syncClient({cid}, {"true" if force else "false"}));'))
def sec(cid): return json.loads(q("select security_json from client_m365 where client_id=%s", cid)[0]["security_json"] or "null")
def st_of(cid, k): return (sec(cid) or {}).get("checks", {}).get(k, {}).get("status")
def ago(days): return (datetime.now(timezone.utc) - timedelta(days=days)).strftime("%Y-%m-%dT%H:%M:%SZ")


def cleanup():
    q("delete from licenses where source='m365'")
    q("delete from client_m365"); q("delete from m365_prices")
    q("delete from client_frameworks where client_id=%s and framework_id in (select id from compliance_frameworks where slug='m365-baseline')", C1)
    q("delete from settings where name like 'm365c\\_%%' or name='health_weight_security'")
    requests.get(M + "/mock/m365c-reset")


cleanup()
import atexit
atexit.register(cleanup)
setting("m365c_login_base", M + "/m365c-login")
setting("m365c_graph_base", M + "/m365c-graph/v1.0")
st = login("admin@example.com", "LongPassword123!")
tok = lambda s, p: csrf(s, p)

# ---- the migration: columns, and built-in content linked to the checks
ok(q("select count(*) n from information_schema.columns where table_schema=database() and ((table_name='client_m365' and column_name in ('security_json','security_at')) or (table_name='client_health' and column_name='security'))")[0]["n"] == 3,
   "migration 061 adds the columns")
links = {r["ref"]: r["auto_check"] for r in q("select c.ref, c.auto_check from compliance_controls c join compliance_frameworks f on f.id=c.framework_id where f.slug='m365-baseline' and c.ref in ('M365-ID-01','M365-ID-04','M365-ID-07','M365-ID-18')")}
ok(links == {"M365-ID-01": "m365_mfa_enforced", "M365-ID-04": "m365_legacy_blocked", "M365-ID-07": "m365_admin_count", "M365-ID-18": None}, f"the Microsoft 365 baseline's controls are linked (not ID-18, which also covers shared mailboxes): {links}")
std = {r["title"]: r["auto_check"] for r in q("select title, auto_check from alignment_standards where title in ('MFA on Microsoft 365 for every user','Legacy sign-in blocked in Microsoft 365')")}
ok(all(v in ("m365_mfa_enforced", "m365_legacy_blocked") for v in std.values()), f"the starter standards are linked: {std}")
php('(require "' + ROOT + '/db/migrations/061_m365_security.php")();')
ok(True, "the migration can run again")

# ---- the app asks clients for the security permissions too (never OwnedBy)
st.post(B + "/integrations/microsoft-365/setup", data={"_csrf": tok(st, "/integrations/microsoft-365")})
requests.get(M + "/mock/m365c-approve")
st.post(B + "/integrations/microsoft-365/setup/finish", data={"_csrf": tok(st, "/integrations/microsoft-365")})
ok(set(mock()["app"]["roles"]) == {ORG, USR, *SEC_ROLES}, "a new app asks for the read-only security permissions too")

# ---- connect a client: the checks, from a tenant without Entra ID P1 and with security defaults off
r = st.post(B + f"/clients/{C1}/m365/connect", data={"_csrf": tok(st, f"/clients/{C1}/licenses")}, allow_redirects=False)
st.get(r.headers["Location"])
s = sec(C1)
ok(s and s["secure"] == {"current": 62, "max": 100} and st_of(C1, "m365_secure_score") == "fail", f"Secure Score is read (62%, below 70: fail): {s and s['secure']}")
ok(st_of(C1, "m365_mfa_users") == "fail" and "2 of 3 licensed users" in s["checks"]["m365_mfa_users"]["detail"], "MFA for users counts enabled, licensed members across pages (guests, disabled accounts and shared mailboxes left out): " + s["checks"]["m365_mfa_users"]["detail"])
ok(st_of(C1, "m365_mfa_admins") == "pass" and "1 of 1 admins" in s["checks"]["m365_mfa_admins"]["detail"], "every enabled admin has MFA (a disabled one doesn't count)")
ok(st_of(C1, "m365_mfa_enforced") == "unknown" and "per-user MFA" in s["checks"]["m365_mfa_enforced"]["detail"], "defaults off and no Conditional Access: MFA enforcement unknown (per-user MFA can't be read)")
ok(st_of(C1, "m365_legacy_blocked") == "fail" and "Security defaults are off" in s["checks"]["m365_legacy_blocked"]["detail"], "legacy sign-in isn't blocked")
ok(st_of(C1, "m365_admin_count") == "fail" and "1 Global Administrator" in s["checks"]["m365_admin_count"]["detail"], "one Global Administrator fails (2 to 4)")
ok(st_of(C1, "m365_stale") == "unknown" and "activity report" in s["checks"]["m365_stale"]["detail"], "no P1: unused accounts come from the activity report (empty here: unknown, not failed)")
ok(s["consent"] is False, "nothing is waiting for approval")
sc = phpo(f'echo Align\\M365\\Security::score(Align\\M365\\Security::stored(Align\\M365\\Tenants::row({C1})));')
ok(sc == "20", f"Security area = 1 of 5 known checks passing = 20 ({sc[:300]})")

# ---- where it shows
t = st.get(B + f"/clients/{C1}").text
card = text(t.split('id="m365-security"')[1].split('</ul>')[0]) if 'id="m365-security"' in t else ""
ok("Secure Score" in card and "62%" in card and "2 of 3 licensed users registered for MFA" in card and "needs Entra ID P1" in card, "the client overview has the Microsoft 365 security card")
ok('id="m365-security"' not in st.get(B + f"/clients/{C2}").text, "a client that isn't connected has no card")
row = q("select security from client_health where client_id=%s and day=curdate()", C1)[0]
ok(row["security"] == 20, f"the health score stores the Security area ({row['security']})")
t = text(st.get(B + f"/clients/{C1}").text)
ok("Based on" in t and "of 6 areas" in t and "Security" in t, "the health card counts six areas and shows Security")
ok("Two to four Global Administrators: 1 Global Administrator" in t, "what pulls Security down is listed")
q("insert into settings (name,value,is_secret) values ('health_weight_security','0',0) on duplicate key update value='0'")
ok(phpo(f'echo json_encode(Align\\Health\\Health::combine(["security" => 20]));') == "null", "a weight of 0 leaves Security out")
q("delete from settings where name='health_weight_security'")
ok('name="health_weight_security"' in st.get(B + "/settings/planning").text, "the weight is on the settings page")

# ---- compliance: suggested answers for linked controls
fw = q("select id from compliance_frameworks where slug='m365-baseline'")[0]["id"]
q("insert ignore into client_frameworks (client_id, framework_id) values (%s, %s)", C1, fw)
t = st.get(B + f"/clients/{C1}/compliance/{fw}").text
blk = t.split("M365-ID-04")[1][:2500] if "M365-ID-04" in t else ""
ok("Security defaults are off" in H.unescape(blk) and 'data-value="not_met"' in blk, "a linked control shows the check and suggests Not met")
ok(q("select count(*) n from client_control_status s join compliance_controls c on c.id=s.control_id where s.client_id=%s and c.ref='M365-ID-04' and s.status='not_met'", C1)[0]["n"] == 0,
   "...but only suggests: nothing is answered")
ok("m365_secure_score" in st.get(B + f"/frameworks/{fw}").text, "the framework editor offers the Microsoft 365 checks")

# ---- alignment: suggestions for linked standards
ind = json.loads(phpo(f'echo json_encode(Align\\Alignment\\Alignment::indicators([], null, Align\\M365\\Security::stored(Align\\M365\\Tenants::row({C1}))));'))
ok(ind["m365_legacy_blocked"]["suggest"] == "misaligned" and ind["m365_mfa_admins"]["suggest"] == "aligned" and ind["m365_stale"]["suggest"] is None, "alignment hints: misaligned, aligned, and none when unknown")
ok("m365_legacy_blocked" in json.loads(phpo('echo json_encode(array_keys(Align\\Alignment\\Alignment::checks()));')), "standards can use the Microsoft 365 checks")

# ---- a well-secured tenant, with security defaults on
mset(security={"tenant": T1, "data": {"secure": [81, 100], "defaults": True, "admins": 3,
     "users": [{"userType": "member", "isAdmin": True, "isMfaRegistered": True}, {"userType": "member", "isAdmin": False, "isMfaRegistered": True}],
     "signins": [{"accountEnabled": True, "assignedLicenses": [{"skuId": "x"}], "signInActivity": {"lastSignInDateTime": ago(3)}, "createdDateTime": ago(400)},
                 {"accountEnabled": True, "assignedLicenses": [{"skuId": "x"}], "signInActivity": {"lastSignInDateTime": ago(200)}, "createdDateTime": ago(400)},
                 {"accountEnabled": True, "assignedLicenses": [], "signInActivity": {"lastSignInDateTime": ago(300)}, "createdDateTime": ago(400)},
                 {"accountEnabled": True, "assignedLicenses": [{"skuId": "x"}], "createdDateTime": ago(5)},
                 {"accountEnabled": True, "assignedLicenses": [{"skuId": "x"}], "signInActivity": {"lastSignInDateTime": ago(200), "lastNonInteractiveSignInDateTime": ago(2)}, "createdDateTime": ago(400)}]}})
sync(C1)
s = sec(C1)
ok(all(st_of(C1, k) == "pass" for k in ["m365_secure_score", "m365_mfa_users", "m365_mfa_admins", "m365_mfa_enforced", "m365_legacy_blocked", "m365_admin_count"]), "security defaults on, 3 admins, everyone with MFA: those pass")
ok(st_of(C1, "m365_stale") == "fail" and "1 of 4 licensed accounts" in s["checks"]["m365_stale"]["detail"], "one licensed account unused for 200 days fails (unlicensed, brand-new and phone-only ones don't count): " + s["checks"]["m365_stale"]["detail"])
ok(json.loads(phpo(f'echo json_encode(Align\\Alignment\\Alignment::indicators([], null, Align\\M365\\Security::stored(Align\\M365\\Tenants::row({C1}))));'))["m365_mfa_enforced"]["suggest"] == "aligned", "the hint follows")

# ---- Conditional Access instead of security defaults
mset(security={"tenant": T1, "data": {"defaults": False, "admins": 3, "users": [{"userType": "member", "isAdmin": True, "isMfaRegistered": True}], "signins": [],
     "ca": [{"state": "enabled", "grantControls": {"builtInControls": ["mfa"]}, "conditions": {"users": {"includeUsers": ["All"], "excludeUsers": ["b1", "b2"]}, "applications": {"includeApplications": ["All"]}, "clientAppTypes": ["all"]}},
            {"state": "enabled", "grantControls": {"builtInControls": ["block"]}, "conditions": {"users": {"includeUsers": ["All"]}, "applications": {"includeApplications": ["All"]}, "clientAppTypes": ["exchangeActiveSync", "other"]}}]}})
sync(C1)
ok(st_of(C1, "m365_mfa_enforced") == "pass" and st_of(C1, "m365_legacy_blocked") == "pass" and "Conditional Access" in sec(C1)["checks"]["m365_mfa_enforced"]["detail"], "Conditional Access policies count (two break-glass accounts excluded is fine)")
def ca_case(policies):
    mset(security={"tenant": T1, "data": {"defaults": False, "ca": policies}})
    sync(C1)
    return st_of(C1, "m365_mfa_enforced"), st_of(C1, "m365_legacy_blocked")
mfa_pol = lambda **kw: {"state": "enabled", "grantControls": kw.get("g", {"builtInControls": ["mfa"]}), "conditions": {"users": kw.get("u", {"includeUsers": ["All"]}), "applications": {"includeApplications": kw.get("apps", ["All"])}}}
ok(ca_case([mfa_pol(apps=["00000002-0000-0ff1-ce00-000000000000"])])[0] == "fail" and "not for all users and apps" in sec(C1)["checks"]["m365_mfa_enforced"]["detail"], "MFA for one app only doesn't count, and says why")
ok(ca_case([mfa_pol(g={"operator": "OR", "builtInControls": ["mfa", "compliantDevice"]})])[0] == "fail", "MFA OR a compliant device doesn't require MFA")
ok(ca_case([mfa_pol(g={"operator": "AND", "builtInControls": ["mfa", "compliantDevice"]})])[0] == "pass", "MFA AND a compliant device does")
ok(ca_case([mfa_pol(g={"builtInControls": [], "authenticationStrength": {"id": "00000000-0000-0000-0000-000000000002"}})])[0] == "pass", "an authentication strength counts as MFA")
ok(ca_case([mfa_pol(u={"includeUsers": ["All"], "excludeGroups": ["g1"]})])[0] == "fail", "excluding a group doesn't count as everyone")
ok(ca_case([mfa_pol(u={"includeUsers": ["All"], "excludeUsers": ["a", "b", "c", "d"]})])[0] == "fail", "excluding more than three accounts doesn't count")
ok(ca_case([{"state": "enabled", "grantControls": {"builtInControls": ["block"]}, "conditions": {"users": {"includeUsers": ["All"]}, "applications": {"includeApplications": ["All"]}, "clientAppTypes": ["exchangeActiveSync"]}}])[1] == "fail",
   "blocking only Exchange ActiveSync isn't blocking legacy sign-in")
mset(security={"tenant": T1, "data": {"defaults": False, "ca": [{"state": "enabledForReportingButNotEnforced", "grantControls": {"builtInControls": ["mfa"]}, "conditions": {"users": {"includeUsers": ["All"]}}}]}})
sync(C1)
ok(st_of(C1, "m365_mfa_enforced") == "fail", "a policy only in report-only mode doesn't count")

# ---- Global Administrators: a group holding the role leaves the count unknown
mset(security={"tenant": T1, "data": {"admins": 2, "admin_groups": 1}})
sync(C1)
ok(st_of(C1, "m365_admin_count") == "unknown" and "1 group" in sec(C1)["checks"]["m365_admin_count"]["detail"], "a group in the Global Administrator role: unknown, not a false count")

# ---- once a day on the hourly sync; Sync now reads them again; old results expire
mset(security={"tenant": T1, "data": {"secure": [90, 100]}})
sync(C1, force=False)
ok(sec(C1)["secure"]["current"] != 90, "the hourly sync doesn't read the checks again within the day")
q("update client_m365 set security_at = now() - interval 21 hour where client_id=%s", C1)
sync(C1, force=False)
ok(sec(C1)["secure"]["current"] == 90, "...but does after 20 hours")
old = (datetime.now() - timedelta(days=3)).strftime("%Y-%m-%d %H:%M:%S")
q("update client_m365 set security_json = json_set(security_json, '$.at', %s) where client_id=%s", old, C1)
ok(phpo(f'var_export(Align\\M365\\Security::stored(Align\\M365\\Tenants::row({C1})));') == "NULL", "a result older than two days is ignored")
t = st.get(B + f"/clients/{C1}").text
ok("Not checked in the last two days" in text(t), "the card says it hasn't been checked lately")
ind = json.loads(phpo(f'[$on, $s] = Align\\M365\\Security::forClient({C1}); echo json_encode(Align\\M365\\Security::indicators($s, $on));'))
ok(ind["m365_admin_count"]["unknown"] and "Not checked" in ind["m365_admin_count"]["text"], "compliance shows nothing old, and says why")
mset(skus={"tenant": T1, "list": None})
r = st.post(B + f"/clients/{C1}/m365/sync", data={"_csrf": tok(st, f"/clients/{C1}/licenses")})
ok(sec(C1)["at"] != old, "Sync now reads the checks even when the licenses fail")
mset(skus={"tenant": T1, "list": []})

# ---- an app made before 2.6.1: it updates its own permissions; clients approve again; licenses keep syncing
mset(app={**mock()["app"], "roles": [ORG, USR]}, grants={T1: [ORG, USR]})
setting("m365c_permissions", "1")
mset(skus={"tenant": T1, "list": [{"skuId": "a1b2c3d4-0000-4000-8000-000000000001", "skuPartNumber": "SPB", "capabilityStatus": "Enabled", "consumedUnits": 2, "prepaidUnits": {"enabled": 3}}]})
res = sync(C1)
ok(res["error"] is None and q("select seats from licenses where client_id=%s and source='m365'", C1)[0]["seats"] == 3, "without the new permissions licenses still sync")
s = sec(C1)
ok(s["consent"] is True and st_of(C1, "m365_secure_score") == "unknown" and "Not approved yet" in s["checks"]["m365_secure_score"]["detail"], "the checks say they're waiting for approval")
t = st.get(B + f"/clients/{C1}/connectors").text
ok("Approve new permissions" in t, "the client's Connectors page asks for approval")
ok("needs to approve new permissions" in st.get(B + f"/clients/{C1}/licenses").text, "...and Licensing says so")
out = phpo('Align\\Mail\\Notifications::setState("health_day", "2000-01-01"); echo implode("|", Align\\Mail\\Notify::tick());')
ok("app permissions updated" in out and set(mock()["app"]["roles"]) == {ORG, USR, *SEC_ROLES} and phpo('echo Align\\Settings::get("m365c_permissions");') == "3",
   "the daily run updates the app's own permissions (once): " + out[-120:])
ok("app permissions updated" not in phpo('Align\\Mail\\Notifications::setState("health_day", "2000-01-01"); echo implode("|", Align\\Mail\\Notify::tick());'), "...not again")
r = st.post(B + f"/clients/{C1}/m365/connect", data={"_csrf": tok(st, f"/clients/{C1}/licenses")}, allow_redirects=False)
st.get(r.headers["Location"])
ok(sec(C1)["consent"] is False and st_of(C1, "m365_secure_score") != "unknown" and q("select status from client_m365 where client_id=%s", C1)[0]["status"] == "connected",
   "approving again (Connect on the same tenant) brings the checks back")

# ---- 2.6.2: Connect/Approve post a form answered with a redirect to Microsoft: the page's form-action allows it
csp = st.get(B + f"/clients/{C1}/connectors").headers.get("Content-Security-Policy", "")
ok("form-action 'self' " + M.rstrip("/") in csp, "the Connectors page's form-action allows Microsoft's sign-in: " + csp[csp.find("form-action"):][:80])
ok("form-action 'self';" in st.get(B + "/").headers.get("Content-Security-Policy", ""), "other pages keep form-action 'self' only")

# ---- 2.6.2: approving brings an older app's permissions up to date first (no waiting for the daily run)
mset(app={**mock()["app"], "roles": [ORG, USR]}, grants={T1: [ORG, USR]})
setting("m365c_permissions", "1")
r = st.post(B + f"/clients/{C1}/m365/connect", data={"_csrf": tok(st, f"/clients/{C1}/connectors")}, allow_redirects=False)
ok(set(mock()["app"]["roles"]) == {ORG, USR, *SEC_ROLES} and phpo('echo Align\\Settings::get("m365c_permissions");') == "3", "Approve updates the app's permissions before sending the admin to Microsoft")
st.get(r.headers["Location"])
ok(sec(C1)["consent"] is False and sec(C1).get("missing") == [], "so one approval grants everything")
mset(app={**mock()["app"], "roles": [ORG, USR]})
setting("m365c_permissions", "1")
st.get(B + "/integrations/microsoft-365")
ok(set(mock()["app"]["roles"]) == {ORG, USR, *SEC_ROLES} and phpo('echo Align\\Settings::get("m365c_permissions");') == "3", "an admin opening Integrations -> Microsoft 365 (clients) updates it too")

# ---- 2.6.2: approved, but Microsoft hasn't applied a permission to the sign-in yet: named, and "a few minutes"
mset(roles_lag=["Policy.Read.All"])
r = st.post(B + f"/clients/{C1}/m365/connect", data={"_csrf": tok(st, f"/clients/{C1}/connectors")}, allow_redirects=False)
r = st.get(r.headers["Location"])
s = sec(C1)
ok(s["missing"] == ["Policy.Read.All"] and s["consent"] is True, f"the token's roles name the permission not applied yet: {s.get('missing')}")
t = text(r.text)
ok("few minutes" in flash(r.text) and "Microsoft can take a few minutes to apply new permissions" in t and "Policy.Read.All" in t and "Approve new permissions" not in t,
   "right after approving: wait a few minutes and Sync now, not another approval request")
q("update client_m365 set connected_at = now() - interval 2 hour where client_id=%s", C1)
t = text(st.get(B + f"/clients/{C1}/connectors").text)
ok("Approve new permissions" in t and "not granted yet: Policy.Read.All" in t, "still missing later: approve again, naming the permission")
mset(roles_lag=[])
st.post(B + f"/clients/{C1}/m365/sync", data={"_csrf": tok(st, f"/clients/{C1}/connectors")})
ok(sec(C1)["consent"] is False and "Approve new permissions" not in st.get(B + f"/clients/{C1}/connectors").text, "once Microsoft applies it, Sync now clears it")
# ---- 2.6.2: if the app can't be updated: kept, audited, a warning when approving, and not retried for an hour
mset(app={**mock()["app"], "roles": [ORG, USR]})
setting("m365c_permissions", "1")
oid = phpo('echo Align\\Settings::get("m365c_app_object_id");')
setting("m365c_app_object_id", "not-a-guid")
r = st.post(B + f"/clients/{C1}/m365/connect", data={"_csrf": tok(st, f"/clients/{C1}/connectors")}, allow_redirects=False)
ok("object id is missing" in phpo('echo Align\\Settings::get("m365c_permissions_error");') and q("select count(*) n from audit_log where action='m365.permissions_failed'")[0]["n"] >= 1,
   "a failed update is kept and audited")
t = st.get(B + f"/clients/{C1}/connectors").text
ok("grants only the earlier ones" in flash(t), "Approve warns that it grants only the earlier permissions: " + flash(t)[:80])
setting("m365c_app_object_id", oid)
st.get(B + "/integrations/microsoft-365")
ok(phpo('echo Align\\Settings::get("m365c_permissions");') == "1", "within the hour it isn't tried again on every page view")
setting("m365c_permissions_failed_at", "0")
r = st.get(B + "/integrations/microsoft-365")
ok(phpo('echo Align\\Settings::get("m365c_permissions");') == "3" and phpo('echo Align\\Settings::get("m365c_permissions_error");') == "", "later it's tried again, and the error is cleared")

# ---- 2.6.2: in a real browser, Connect goes to Microsoft's sign-in (it used to just reload the page: CSP form-action)
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1400, "height": 900})
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
    # The mock Microsoft approves straight away and sends the browser back: arriving back with the result proves the
    # browser went there (before 2.6.2 it was stopped on the Connectors page and nothing happened)
    pg.goto(B + f"/clients/{C2}/connectors")
    seen = []
    pg.context.on("request", lambda rq: seen.append(rq.url))
    with pg.context.expect_page() as newp:   # 2.6.2: Microsoft opens in a new tab; this one stays
        pg.click("text=Connect Microsoft 365")
    np = newp.value
    np.wait_for_load_state()
    ok(any("/m365c-login/" in u and "adminconsent?" in u for u in seen) and "approved the app" in flash(np.content()),
       "clicking Connect Microsoft 365 opens Microsoft's approval page in a new tab (and the result shows there): " + flash(np.content())[:80])
    ok(pg.url.startswith(B + f"/clients/{C2}/connectors"), "the Connectors page stays open in its own tab")
    np.close(); pg.bring_to_front()
    # Back on this tab: it reloads by itself (headless Chrome may already have fired visibilitychange on bring_to_front)
    pg.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
    pg.wait_for_timeout(1500); pg.wait_for_load_state()
    ok("Tenant ID" in pg.content(), "coming back to it, the page reloads and shows the result (the tenant waiting to be confirmed)")
    b.close()

# ---- 2.6.3: without Entra ID P1 (security defaults, no Conditional Access): MFA from each account's own methods
# ($batch, 20 at a time) and unused accounts from the Microsoft 365 active users report, instead of unknown
q("delete from client_m365 where client_id=%s and status <> 'connected'", C1)
q("update client_m365 set status='connected', tenant_id=%s, mode='msp' where client_id=%s", T1, C1)
dom = q("select tenant_domain from client_m365 where client_id=%s", C1)[0]["tenant_domain"]
mset(grants={T1: [ORG, USR, *SEC_ROLES]}, roles_lag=[])
users = [{"id": "u1", "userType": "member", "isAdmin": True, "isMfaRegistered": True},
         {"id": "u2", "userType": "member", "isAdmin": True, "methods": ["passwordAuthenticationMethod", "emailAuthenticationMethod"]},
         {"id": "p3", "userType": "member", "methods": ["passwordAuthenticationMethod", "phoneAuthenticationMethod"]},
         {"id": "p4", "userType": "member", "methods": ["fido2AuthenticationMethod"]},
         {"id": "p5", "userType": "guest", "methods": ["passwordAuthenticationMethod"]},
         {"id": "p6", "userType": "member", "enabled": False, "methods": ["passwordAuthenticationMethod"]},
         {"id": "p7", "userType": "member", "licensed": False, "methods": ["passwordAuthenticationMethod"]},
         *[{"id": f"x{i}", "userType": "member", "isMfaRegistered": True} for i in range(20)]]
day = lambda n: (date.today() - timedelta(days=n)).isoformat()
activity = [{"upn": "u1@" + dom, "last": day(3)}, {"upn": "p3@" + dom, "last": day(120)}, {"upn": "p6@" + dom, "last": day(200)},
            {"upn": "x0@" + dom, "last": day(200), "teams": day(2)}, {"upn": "5f2c9a0e8b1d", "assigned": day(400)},
            {"upn": "gone@" + dom, "deleted": True, "last": day(300)}, {"upn": "nolic@" + dom, "products": "", "last": day(300)}]
mset(security={"tenant": T1, "data": {"defaults": True, "regs": "nop1", "signins": "nop1", "admins": 2, "users": users, "activity": activity}})
mset(calls=[])
sync(C1)
s = sec(C1)
ok(st_of(C1, "m365_mfa_users") == "fail" and "23 of 24 licensed users have an MFA method" in s["checks"]["m365_mfa_users"]["detail"],
   "no P1: MFA read from each account's methods (guests, blocked and unlicensed accounts left out; email isn't a second factor): " + s["checks"]["m365_mfa_users"]["detail"])
ok(st_of(C1, "m365_mfa_admins") == "fail" and "1 of 2 admins have an MFA method" in s["checks"]["m365_mfa_admins"]["detail"], "...and for admins (from their roles)")
ok(len([c for c in mock().get("calls", []) if "batch" in c]) >= 2, "read 20 accounts to a $batch request")
mset(security={"tenant": T1, "data": {"defaults": True, "regs": "nop1", "signins": "nop1", "admins": 3, "users": users + [{"id": "u3", "userType": "guest", "isAdmin": True}], "activity": activity, "throttle": True}}, throttled=False)
sync(C1)
ok("23 of 24 licensed users" in sec(C1)["checks"]["m365_mfa_users"]["detail"] and "1 of 2 admins" in sec(C1)["checks"]["m365_mfa_admins"]["detail"],
   "an account Microsoft throttles is tried again after Retry-After; a guest admin isn't counted")
ok(st_of(C1, "m365_stale") == "fail" and "2 of 4 licensed accounts with no Microsoft 365 activity for 90 days" in s["checks"]["m365_stale"]["detail"] and "names are hidden" in s["checks"]["m365_stale"]["detail"],
   "no P1: unused accounts from the activity report (Teams counts, blocked, deleted and unlicensed rows left out, a hidden name counted): " + s["checks"]["m365_stale"]["detail"])
rc = [c for c in mock().get("calls", []) if "report" in c]
ok(rc and rc[-1]["auth"] == "", "the report's download address gets no token")
ok(st_of(C1, "m365_mfa_enforced") == "pass", "security defaults still count for MFA enforced")
mset(security={"tenant": T1, "data": {"defaults": True, "regs": "nop1", "signins": "nop1", "admins": 2, "users": users,
     "activity": [{"upn": "u1@" + dom, "last": day(3)}, {"upn": "p6@" + dom, "last": day(200)}]}})
sync(C1)
ok(st_of(C1, "m365_stale") == "pass" and "names are hidden" not in sec(C1)["checks"]["m365_stale"]["detail"], "real names: a blocked account's licence isn't counted, and nothing else is unused")
mset(grants={T1: [ORG, *[r for r in SEC_ROLES if r not in ("38d9df27-64da-44fd-b7c5-a6fbac20248f", "230c1aed-a721-4c5d-9cb4-a90514e508ef")], USR]})
sync(C1)
s = sec(C1)
ok(st_of(C1, "m365_mfa_users") == "unknown" and "Not approved yet" in s["checks"]["m365_mfa_users"]["detail"] and st_of(C1, "m365_stale") == "unknown" and s["consent"] is True,
   "a client that hasn't approved the two new permissions: unknown, asking for approval")
ok("Approve new permissions" in st.get(B + f"/clients/{C1}/connectors").text, "...on its Connectors page")
mset(grants={T1: [ORG, USR, *SEC_ROLES]})
mset(security={"tenant": T1, "data": {"defaults": True, "regs": "nop1", "signins": "nop1", "admins": 2, "users": users, "activity": activity}})
h = phpo('echo json_encode((new ReflectionMethod("Align\\M365\\App", "graphDownload"))->getDocComment() !== false);')
ok(h == "true", "the download helper is documented")
done()

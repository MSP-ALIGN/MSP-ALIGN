"""2.6.1 Microsoft 365 security checks, against the mock Microsoft: each check (pass, fail, unknown with why: a licence
the tenant lacks, or permissions not approved yet), the users list followed across pages, security defaults and
Conditional Access; the client overview card; the health score's Security area (and its weight); suggested answers for
compliance controls and alignment standards linked to a check (the built-in ones linked by the migration); the app
gaining the new permissions by itself and clients approving them again, with licenses syncing meanwhile."""
import re, json, html as H
from datetime import date, datetime, timedelta, timezone
from lib import *

HOME = "aaaaaaaa-0000-4000-8000-000000000001"
T1 = "11111111-aaaa-4bbb-8ccc-000000000001"
C1, C2 = 1, 2
ORG, USR = "498476ce-e0fe-48b0-b801-37ba7e2685c6", "df021288-bdef-4463-88db-98f22de89214"
SEC_ROLES = ["bf394140-e372-4bf9-a898-299cfc7564e5", "246dd0d5-5bd0-4def-940b-0421030a5b68", "b0afded3-3588-46d8-8b3d-9842eff778da", "483bed4a-2ad3-4361-a73b-c83ccdbdc53c"]


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
ok(st_of(C1, "m365_stale") == "unknown" and "Entra ID P1" in s["checks"]["m365_stale"]["detail"], "unused accounts need Entra ID P1: unknown, not failed")
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
ok("app permissions updated" in out and set(mock()["app"]["roles"]) == {ORG, USR, *SEC_ROLES} and phpo('echo Align\\Settings::get("m365c_permissions");') == "2",
   "the daily run updates the app's own permissions (once): " + out[-120:])
ok("app permissions updated" not in phpo('Align\\Mail\\Notifications::setState("health_day", "2000-01-01"); echo implode("|", Align\\Mail\\Notify::tick());'), "...not again")
r = st.post(B + f"/clients/{C1}/m365/connect", data={"_csrf": tok(st, f"/clients/{C1}/licenses")}, allow_redirects=False)
st.get(r.headers["Location"])
ok(sec(C1)["consent"] is False and st_of(C1, "m365_secure_score") != "unknown" and q("select status from client_m365 where client_id=%s", C1)[0]["status"] == "connected",
   "approving again (Connect on the same tenant) brings the checks back")
done()

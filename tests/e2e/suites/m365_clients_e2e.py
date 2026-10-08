"""2.6.0 Microsoft 365 for clients, against the mock Microsoft (tests/mock-server.php): the MSP app created after a
device code sign-in (multi-tenant, read-only roles for clients, OwnedBy in its own tenant, owner of itself, a
certificate); a client connected through admin consent (staff) or a link (confirmed by staff); its subscriptions in
Licensing (names, seats, assigned, free ones left out), the price list and a license's own price; the hourly sync
(changes, retiring and restoring, refusing an empty answer, a revoked approval); duplicates; a client's own app;
certificate rotation (forced, when due, failing); disconnecting; the API's rules for these licenses; roles."""
import re, json, time, html as H
from datetime import date, timedelta
from lib import *

API = B + "/api/v1"
HOME = "aaaaaaaa-0000-4000-8000-000000000001"
T1, T2, T3 = "11111111-aaaa-4bbb-8ccc-000000000001", "22222222-aaaa-4bbb-8ccc-000000000002", "33333333-aaaa-4bbb-8ccc-000000000003"
OWN = "cccccccc-0000-4000-8000-00000000000c"
C1, C2, C3 = 1, 2, 3
SPB, EXO, FLOW = "a1b2c3d4-0000-4000-8000-000000000001", "a1b2c3d4-0000-4000-8000-000000000002", "a1b2c3d4-0000-4000-8000-000000000003"


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def phpo(code): r = php(code); return (r.stdout + r.stderr).strip()
def mock(): return requests.get(M + "/mock/m365c").json()
def mset(**kw): requests.post(M + "/mock/m365c-set", data=json.dumps(kw))
def sku(sid, part, seats, used, status="Enabled"): return {"skuId": sid, "skuPartNumber": part, "capabilityStatus": status, "consumedUnits": used, "prepaidUnits": {"enabled": seats, "suspended": 0, "warning": 0}}
def lic(cid, part): r = q("select * from licenses where client_id=%s and source='m365' and software_type=%s", cid, part); return r[0] if r else None
def sync_one(cid): return json.loads(phpo(f'echo json_encode(Align\\M365\\Tenants::syncClient({cid}));'))


def cleanup():
    q("delete from licenses where source='m365'")
    q("delete from licenses where name like 'ZzM365%%'")
    q("delete from client_m365"); q("delete from m365_prices")
    q("delete from settings where name like 'm365c\\_%%'")
    q("delete from api_keys where name like 'm365c %%'"); q("delete from api_rate"); q("delete from api_ip_rate")
    requests.get(M + "/mock/m365c-reset")


cleanup()
import atexit
atexit.register(cleanup)
setting("m365c_login_base", M + "/m365c-login")
setting("m365c_graph_base", M + "/m365c-graph/v1.0")
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
tok = lambda s, page: csrf(s, page)

# ---- the migration
ok(q("select count(*) n from information_schema.tables where table_schema=database() and table_name in ('client_m365','m365_prices')")[0]["n"] == 2, "migration 060 made client_m365 and m365_prices")
ok("'m365'" in q("select column_type t from information_schema.columns where table_schema=database() and table_name='licenses' and column_name='source'")[0]["t"], "licenses.source takes m365")
php('(require "' + ROOT + '/db/migrations/060_m365_clients.php")();')
ok(q("select count(*) n from information_schema.columns where table_schema=database() and table_name='licenses' and column_name in ('m365_sku_id','price_source')")[0]["n"] == 2, "the migration can run again")

# ---- the integration page and the setup sign-in (admins only)
r = st.get(B + "/integrations")
ok("Microsoft 365 (clients)" in r.text and "/integrations/microsoft-365" in r.text, "the Integrations page lists Microsoft 365 (clients)")
r = st.get(B + "/integrations/microsoft-365")
ok(r.status_code == 200 and not errs(r.text) and "Set up with Microsoft" in r.text and "Not set up" in r.text, "its page offers to set it up")
ok(tech.get(B + "/integrations/microsoft-365").status_code == 403, "techs can't open it")
ok(tech.post(B + "/integrations/microsoft-365/setup", data={"_csrf": tok(tech, "/clients")}).status_code == 403, "techs can't start the setup")
r = st.post(B + "/integrations/microsoft-365/setup", data={"_csrf": tok(st, "/integrations/microsoft-365")})
ok("ABCD-EFGH" in r.text and "microsoft.com/devicelogin" in r.text and "dc-1" not in r.text, "the setup shows the code and Microsoft's page, not the device code")
r = st.post(B + "/integrations/microsoft-365/setup/finish", data={"_csrf": tok(st, "/integrations/microsoft-365")})
ok("isn't finished yet" in flash(r.text) and "ABCD-EFGH" in r.text, "Continue before signing in keeps the code: " + flash(r.text))
requests.get(M + "/mock/m365c-approve")
r = st.post(B + "/integrations/microsoft-365/setup/finish", data={"_csrf": tok(st, "/integrations/microsoft-365")})
ok("You can connect clients now" in flash(r.text), "after the sign-in the app is created: " + flash(r.text))
s = {k: (q("select value from settings where name=%s", k) or [{"value": None}])[0]["value"] for k in ["m365c_mode", "m365c_app_id", "m365c_tenant", "m365c_tenant_name", "m365c_cert_expires"]}
ok(s["m365c_mode"] == "auto" and s["m365c_app_id"] == "bbbbbbbb-0000-4000-8000-00000000000b" and s["m365c_tenant"] == HOME and s["m365c_tenant_name"] == "Example MSP", f"its ids are saved: {s}")
ok(s["m365c_cert_expires"] and date.fromisoformat(s["m365c_cert_expires"]) > date.today() + timedelta(days=360), "the certificate lasts a year")
ok(phpo('echo Align\\Settings::hasSecret("m365c_key_pem") && Align\\Settings::hasSecret("m365c_cert_pem") ? "yes" : "no";') == "yes"
   and q("select count(*) n from settings where name='m365c_key_pem' and value like '%%PRIVATE KEY%%'")[0]["n"] == 0, "the key and certificate are stored encrypted")
app = mock()["app"]
ok(app["signInAudience"] == "AzureADMultipleOrgs" and {"498476ce-e0fe-48b0-b801-37ba7e2685c6", "df021288-bdef-4463-88db-98f22de89214"} <= set(app["roles"]) and "18a4783c-866b-4cc7-a460-3d5e5662c884" not in app["roles"],
   "the app is multi-tenant, and clients are asked only for read-only permissions (never OwnedBy; 2.6.1 adds the security ones)")
ok(st.post(B + "/integrations/microsoft-365/setup", data={"_csrf": tok(st, "/integrations/microsoft-365")}).text.count("ABCD-EFGH") == 0, "setting up again is refused while the app is in use")
ok(app["granted"] == ["18a4783c-866b-4cc7-a460-3d5e5662c884"] and app["owners"] and app["owners"][0].endswith("/directoryObjects/" + app["sp"]), "it may change only itself, and owns itself")
ok(app["redirectUris"] and app["redirectUris"][0].endswith("/m365/consent"), "its redirect URI is Align's consent page")
ok(len(mock()["keys"]) == 1, "it has one certificate")
ok(q("select count(*) n from audit_log where action='m365.app_created'")[0]["n"] >= 1, "creating the app is audited")
r = st.get(B + "/integrations/microsoft-365")
ok("Ready" in r.text and "bbbbbbbb-0000-4000-8000-00000000000b" in r.text and "Replace certificate now" in r.text, "the page shows the app and its certificate")

# ---- connecting a client (staff): its subscriptions in Licensing
q("insert into licenses (client_id, source, name, category, pricing, seats, unit_price) values (%s, 'manual', 'ZzM365 Microsoft 365 Business Premium', 'productivity', 'per_seat', 9, 20)", C1)
mset(skus={"tenant": T1, "list": [sku(SPB, "SPB", 10, 8), sku(EXO, "EXCHANGESTANDARD", 5, 5), sku(FLOW, "FLOW_FREE", 10000, 3)]})
t = st.get(B + f"/clients/{C1}/connectors").text
ok('id="m365"' in t and "Connect Microsoft 365" in t and "Link for the client" in t and "Linked systems" in t, "the client's Connectors page offers to connect and lists its linked systems")
ok(f'href="/clients/{C1}/connectors"' in t and f'href="/clients/{C1}/connectors"' in tech.get(B + f"/clients/{C1}").text, "Connectors is in the client menu for admins and techs")
ok("Connect it on Connectors" in st.get(B + f"/clients/{C1}/licenses").text, "Licensing points to Connectors")
vt = viewer.get(B + f"/clients/{C1}/licenses").text
ok("Connect Microsoft 365" not in vt and "/connectors" not in vt, "viewers get no buttons and no Connectors link")
ok(viewer.get(B + f"/clients/{C1}/connectors").status_code == 403, "viewers can't open Connectors")
r = st.post(B + f"/clients/{C1}/m365/connect", data={"_csrf": tok(st, f"/clients/{C1}/licenses")}, allow_redirects=False)
loc = r.headers.get("Location", "")
ok(r.status_code == 302 and "/m365c-login/organizations/v2.0/adminconsent?" in loc and "client_id=bbbbbbbb" in loc and "redirect_uri=" in loc, "Connect sends you to Microsoft's approval page")
r = st.get(loc)
row = q("select * from client_m365 where client_id=%s", C1)[0]
ok(f"/clients/{C1}/connectors" in r.url, "after approving, staff are back on the client's Connectors page")
ok(row["status"] == "connected" and row["tenant_id"] == T1 and row["tenant_name"] == "Northwind Dental" and row["tenant_domain"] == "northwind.example" and row["consent_nonce"] is None,
   "the tenant is connected with its name and domain; the link is spent")
spb, exo = lic(C1, "SPB"), lic(C1, "EXCHANGESTANDARD")
ok(spb and spb["name"] == "Microsoft 365 Business Premium" and spb["seats"] == 10 and spb["seats_used"] == 8 and spb["vendor"] == "Microsoft" and spb["category"] == "productivity",
   "a subscription becomes a license: readable name, seats bought, seats assigned")
ok(exo and exo["seats"] == 5 and lic(C1, "FLOW_FREE") is None, "free subscriptions are left out")
pl = {p["sku_part"]: p for p in q("select * from m365_prices")}
ok(set(pl) == {"SPB", "EXCHANGESTANDARD", "FLOW_FREE"} and pl["FLOW_FREE"]["skip"] == 1 and pl["SPB"]["skip"] == 0, "every subscription goes on the price list, free ones left out")
t = st.get(B + f"/clients/{C1}/licenses").text
ok("synced from <b>Northwind Dental</b>" in t and "Microsoft 365</span>" in t and "needs price" in t and "Sync now" not in t, "Licensing shows a line about the tenant, the Microsoft 365 badge and that prices are missing")
ok("Counted twice?" in t and "ZzM365 Microsoft 365 Business Premium" in t, "a license added by hand that looks the same is offered for retiring")
tc = st.get(B + f"/clients/{C1}/connectors").text
ok("Northwind Dental" in tc and "Sync now" in tc and "may now be counted twice" in tc, "Connectors shows the tenant and Sync now, and points to the duplicates on Licensing")
tt = text(tc)
ok("Linked systems" in tt and "· PSA" in tt and "· RMM" in tt and "· Backups" in tt and "Last hourly sync (all clients)" in tt, "Linked systems lists the PSA, the RMM and the backup product, with the last sync")
orig = q("select external_id, match_method from client_links where client_id=%s and provider='ninjaone'", C1)
q("insert into client_links (client_id, provider, external_id, match_method) values (%s, 'ninjaone', 'zz-gone', 'manual') on duplicate key update external_id='zz-gone'", C1)
tt = text(st.get(B + f"/clients/{C1}/connectors").text)
ok("Link broken" in tt and "zz-gone" not in tt and "not synced yet" in tt, "a link to an organization Align doesn't have shows as broken")
if orig:
    q("update client_links set external_id=%s, match_method=%s where client_id=%s and provider='ninjaone'", orig[0]["external_id"], orig[0]["match_method"], C1)
else:
    q("delete from client_links where client_id=%s and provider='ninjaone'", C1)
vt = viewer.get(B + f"/clients/{C1}").text
ok('id="m365-security"' in vt and "/connectors" not in vt, "viewers see the security card without links to Connectors")
ok(q("select count(*) n from audit_log where action='m365.connected'")[0]["n"] >= 1, "connecting is audited")

# ---- the price list and a license's own price
f = {"_csrf": tok(st, "/integrations/microsoft-365"), "prices[SPB][name]": "Microsoft 365 Business Premium", "prices[SPB][unit_price]": "22.00", "prices[SPB][billing_cycle]": "monthly",
     "prices[EXCHANGESTANDARD][name]": "Exchange Online (Plan 1)", "prices[EXCHANGESTANDARD][unit_price]": "4", "prices[EXCHANGESTANDARD][billing_cycle]": "monthly",
     "prices[FLOW_FREE][name]": "Power Automate Free", "prices[FLOW_FREE][unit_price]": "", "prices[FLOW_FREE][billing_cycle]": "monthly", "prices[FLOW_FREE][skip]": "1"}
ok(tech.post(B + "/integrations/microsoft-365/prices", data={**f, "_csrf": tok(tech, "/clients")}).status_code == 403, "techs can't change the price list")
r = st.post(B + "/integrations/microsoft-365/prices", data=f)
spb = lic(C1, "SPB")
ok("Price list saved" in flash(r.text) and float(spb["unit_price"]) == 22.0 and spb["price_source"] == "list" and float(lic(C1, "EXCHANGESTANDARD")["unit_price"]) == 4.0, "saving the price list prices the licenses")
ok(abs(float(phpo(f'echo array_sum(array_column(array_filter(Align\\Licensing\\Licenses::load({C1}), fn($l) => $l["source"] === "m365"), "monthly"));')) - (10 * 22 + 5 * 4)) < 0.01,
   "the client's monthly cost counts seats x list price")
r = st.post(B + f"/licenses/{spb['id']}", data={"_csrf": tok(st, f"/clients/{C1}/licenses"), "action": "save", "unit_price": "20", "billing_cycle": "monthly", "category": "productivity", "pricing": "per_seat",
                                                "name": "Hacked name", "seats": "999", "seats_used": "1"})
spb = lic(C1, "SPB")
ok(float(spb["unit_price"]) == 20.0 and spb["price_source"] == "custom", "a price set on the license is the client's own")
ok(spb["name"] == "Microsoft 365 Business Premium" and spb["seats"] == 10 and spb["seats_used"] == 8, "the name and seats from Microsoft can't be changed in Align")
st.post(B + "/integrations/microsoft-365/prices", data={**f, "_csrf": tok(st, "/integrations/microsoft-365"), "prices[SPB][unit_price]": "23"})
ok(float(lic(C1, "SPB")["unit_price"]) == 20.0, "a new list price leaves a license with its own price alone")
st.post(B + f"/licenses/{spb['id']}", data={"_csrf": tok(st, f"/clients/{C1}/licenses"), "action": "save", "unit_price": "20", "billing_cycle": "monthly", "category": "productivity", "pricing": "per_seat", "use_list_price": "1"})
ok(float(lic(C1, "SPB")["unit_price"]) == 23.0 and lic(C1, "SPB")["price_source"] == "list", "\"use the price list's price again\" puts it back on the list")
r = st.post(B + f"/licenses/{spb['id']}", data={"_csrf": tok(st, f"/clients/{C1}/licenses"), "action": "delete"})
ok(lic(C1, "SPB") is not None and "can be retired but not deleted" in flash(r.text), "a Microsoft 365 license can't be deleted")

# ---- the hourly sync: seat changes, a subscription removed and back, an empty answer, a revoked approval
mset(skus={"tenant": T1, "list": [sku(SPB, "SPB", 12, 11), sku(FLOW, "FLOW_FREE", 10000, 3)]})
out = phpo('echo Align\\M365\\Tenants::syncAll();')
ok(lic(C1, "SPB")["seats"] == 12 and lic(C1, "SPB")["seats_used"] == 11 and lic(C1, "EXCHANGESTANDARD")["retired_reason"] == "m365", "the sync updates seats and retires a subscription that's gone: " + out)
mset(skus={"tenant": T1, "list": [sku(SPB, "SPB", 12, 11), sku(EXO, "EXCHANGESTANDARD", 5, 4)]})
phpo('echo Align\\M365\\Tenants::syncAll();')
ok(lic(C1, "EXCHANGESTANDARD")["retired_at"] is None and float(lic(C1, "EXCHANGESTANDARD")["unit_price"]) == 4.0, "it comes back when it's back, with its list price")
mset(skus={"tenant": T1, "list": [sku(SPB, "SPB", 12, 11, "Suspended"), sku(EXO, "EXCHANGESTANDARD", 5, 4)]})
phpo('echo Align\\M365\\Tenants::syncAll();')
ok(lic(C1, "SPB")["retired_reason"] == "m365", "a suspended subscription is retired")
mset(skus={"tenant": T1, "list": []})
r = php(f'print_r(Align\\M365\\Tenants::syncClient({C1}));')
ok("returned no subscriptions" in r.stdout and lic(C1, "EXCHANGESTANDARD")["retired_at"] is None, "an empty answer changes nothing and is reported")
ok("Microsoft 365 licenses not syncing" in st.get(B + "/").text, "the dashboard lists the failing client")
mset(skus={"tenant": T1, "list": [sku(SPB, "SPB", 12, 11), sku(EXO, "EXCHANGESTANDARD", 5, 4)]}, revoke=T1)
r = php(f'print_r(Align\\M365\\Tenants::syncClient({C1}));')
ok("hasn't approved the app" in r.stdout, "a removed approval says to connect again: " + r.stdout[-200:])
r = st.post(B + f"/clients/{C1}/m365/sync", data={"_csrf": tok(st, f"/clients/{C1}/licenses")})
ok("sync failed" in flash(r.text), "Sync now shows the error too")
mset(consented=[T1])
r = tech.post(B + f"/clients/{C1}/m365/sync", data={"_csrf": tok(tech, f"/clients/{C1}/licenses")})
ok("Synced from Microsoft 365" in flash(r.text) and q("select last_error from client_m365 where client_id=%s", C1)[0]["last_error"] is None and lic(C1, "SPB")["retired_at"] is None,
   "approved again, a tech's Sync now works and clears the error")

# ---- duplicates
d = q("select id from licenses where client_id=%s and name='ZzM365 Microsoft 365 Business Premium'", C1)[0]["id"]
r = tech.post(B + f"/clients/{C1}/m365/dupes", data={"_csrf": tok(tech, f"/clients/{C1}/licenses"), "retire[]": [str(d), "999999"]})
ok(q("select retired_reason from licenses where id=%s", d)[0]["retired_reason"] == "align" and q("select dupes_checked from client_m365 where client_id=%s", C1)[0]["dupes_checked"] == 1,
   "the ticked duplicate is retired and the check is done (another id is ignored)")
ok("Counted twice?" not in st.get(B + f"/clients/{C1}/licenses").text, "it isn't offered again")

# ---- a link for the client's admin: approved without a staff session, then confirmed by staff
mset(consent_tenant=T2, skus={"tenant": T2, "list": [sku(SPB, "SPB", 3, 3)]})
t = st.post(B + f"/clients/{C2}/m365/link", data={"_csrf": tok(st, f"/clients/{C2}/licenses")}).text  # the page it redirects to shows it
m = re.search(r'id="m365-link" value="([^"]+)"', t)
link = H.unescape(m.group(1)) if m else ""
ok(link.startswith(M + "/m365c-login/organizations/v2.0/adminconsent?"), "the link is shown once")
ok('id="m365-link"' not in st.get(B + f"/clients/{C2}/connectors").text, "...and not again")
anon = requests.Session()
r = anon.get(link)
ok(r.status_code == 200 and "Thank you" in r.text and "Contoso Legal" in r.text, "the client's admin gets a thank-you page")
row = q("select * from client_m365 where client_id=%s", C2)[0]
ok(row["status"] == "pending" and row["tenant_id"] is None and row["pending_tenant_id"] == T2 and lic(C2, "SPB") is None, "approved through a link, it waits for staff (nothing synced)")
ok("already used" in anon.get(link).text, "the link works once")
ok("waiting to be confirmed" in st.get(B + f"/clients/{C2}/licenses").text, "Licensing says a tenant is waiting")
t = st.get(B + f"/clients/{C2}/connectors").text
ok("Contoso Legal" in t and "Yes, connect it" in t and T2 in t and "contoso.example" in t, "staff are asked to confirm, with the tenant's id and domain")
ok("Confirm a Microsoft 365 tenant" in st.get(B + "/").text, "the dashboard says there's one to confirm")
ok(f"/clients/{C2}/connectors" not in viewer.get(B + "/").text, "viewers' dashboard doesn't link to Connectors")
tech.post(B + f"/clients/{C2}/m365/confirm", data={"_csrf": tok(tech, f"/clients/{C2}/licenses")})
row = q("select * from client_m365 where client_id=%s", C2)[0]
ok(row["status"] == "connected" and row["tenant_id"] == T2 and row["pending_tenant_id"] is None and lic(C2, "SPB")["seats"] == 3, "confirmed, it's connected and synced")

# A link opened by a signed-in tech still waits: only their own Connect connects straight away
mset(consent_tenant=T2)
t = st.post(B + f"/clients/{C2}/m365/link", data={"_csrf": tok(st, f"/clients/{C2}/licenses")}).text
link2 = H.unescape(re.search(r'id="m365-link" value="([^"]+)"', t).group(1))
tech.get(link2)
row = q("select * from client_m365 where client_id=%s", C2)[0]
ok(row["status"] == "connected" and row["pending_tenant_id"] == T2, "a link opened by staff waits for confirmation too, and the connection keeps working")
tech.post(B + f"/clients/{C2}/m365/reject", data={"_csrf": tok(tech, f"/clients/{C2}/licenses")})
row = q("select * from client_m365 where client_id=%s", C2)[0]
ok(row["status"] == "connected" and row["tenant_id"] == T2 and row["pending_tenant_id"] is None, "\"No, forget it\" drops only the waiting tenant")

# The tenant in the callback is only a query parameter: changed to another client's, it never connects
r = tech.post(B + f"/clients/{C2}/m365/connect", data={"_csrf": tok(tech, f"/clients/{C2}/licenses")}, allow_redirects=False)
back = tech.get(r.headers["Location"], allow_redirects=False).headers["Location"]
tech.get(back.replace("tenant=" + T2, "tenant=" + T1))
row = q("select * from client_m365 where client_id=%s", C2)[0]
ok(row["tenant_id"] == T2 and row["pending_tenant_id"] == T1 and "another client" in (row["pending_note"] or ""), "another client's tenant swapped into the callback only waits, flagged")
tech.post(B + f"/clients/{C2}/m365/reject", data={"_csrf": tok(tech, f"/clients/{C2}/licenses")})
r = tech.post(B + f"/clients/{C2}/m365/connect", data={"_csrf": tok(tech, f"/clients/{C2}/licenses")}, allow_redirects=False)
back = tech.get(r.headers["Location"], allow_redirects=False).headers["Location"]
tech.get(back.replace("tenant=" + T2, "tenant=" + HOME))
ok(q("select pending_tenant_id from client_m365 where client_id=%s", C2)[0]["pending_tenant_id"] is None and q("select tenant_id from client_m365 where client_id=%s", C2)[0]["tenant_id"] == T2,
   "the MSP's own tenant is refused")
bad = re.sub(r"state=[^&]+", "state=" + "1.%s.%d.%s" % ("a" * 32, int(time.time()) + 999, "b" * 64), link)
r = anon.get(bad)
ok("isn't valid" in text(r.text), "a made-up state is refused")
ok(anon.get(B + "/m365/consent?state=x&tenant=" + T1).status_code == 200 and q("select count(*) n from client_m365")[0]["n"] == 2, "the public page changes nothing without a valid state")

# ---- a client's own app (admins)
mset(skus={"tenant": T3, "list": [sku(SPB, "SPB", 4, 2), sku(EXO, "EXCHANGESTANDARD", 1, 1)]})
fo = {"tenant_id": T3, "app_id": OWN, "secret": "wrong", "secret_expires": (date.today() + timedelta(days=20)).isoformat()}
r = st.post(B + f"/clients/{C3}/m365/own", data={**fo, "tenant_id": T2, "secret": "own-secret", "_csrf": tok(st, f"/clients/{C3}/licenses")})
ok("already connected to another client" in flash(r.text), "a tenant already connected to another client is refused")
ok(tech.post(B + f"/clients/{C3}/m365/own", data={**fo, "_csrf": tok(tech, f"/clients/{C3}/licenses")}).status_code == 403, "techs can't save a client's app secret")
r = st.post(B + f"/clients/{C3}/m365/own", data={**fo, "_csrf": tok(st, f"/clients/{C3}/licenses")})
ok("secret is wrong" in flash(r.text) and not q("select 1 from client_m365 where client_id=%s", C3), "a wrong secret is refused, nothing saved: " + flash(r.text))
r = st.post(B + f"/clients/{C3}/m365/own", data={**fo, "secret": "own-secret", "_csrf": tok(st, f"/clients/{C3}/licenses")})
row = q("select * from client_m365 where client_id=%s", C3)[0]
ok(row["mode"] == "own" and row["status"] == "connected" and lic(C3, "SPB")["seats"] == 4 and row["secret_enc"].startswith("v1:") and "own-secret" not in row["secret_enc"], "connected with its own app; the secret is encrypted")
ok("own-secret" not in st.get(B + f"/clients/{C3}/connectors").text and "expires" in text(st.get(B + f"/clients/{C3}/connectors").text), "the secret isn't shown back; its expiry is flagged")
ok("Microsoft 365 app secret expires" in st.get(B + "/").text, "the dashboard flags the expiring secret")

# ---- certificate rotation: forced, when due, and failing
old = mock()["keys"][0]["keyId"]
cur = lambda: phpo('echo Align\\Settings::get("m365c_cert_key_id");')
r = st.post(B + "/integrations/microsoft-365/rotate", data={"_csrf": tok(st, "/integrations/microsoft-365")})
keys = [k["keyId"] for k in mock()["keys"]]
ok("New certificate added" in flash(r.text) and len(keys) == 2 and old in keys and cur() == old, "Replace now adds a new certificate; Align keeps signing with the current one: " + flash(r.text))
ok("Synced" in flash(st.post(B + f"/clients/{C1}/m365/sync", data={"_csrf": tok(st, f"/clients/{C1}/licenses")}).text), "client tenants keep working meanwhile")
r = st.post(B + "/integrations/microsoft-365/rotate", data={"_csrf": tok(st, "/integrations/microsoft-365")})
ok("less than an hour ago" in flash(r.text) and len(mock()["keys"]) == 2, "it doesn't switch within the hour")
setting("m365c_next_since", (date.today() - timedelta(days=1)).isoformat() + " 00:00:00")
r = st.post(B + "/integrations/microsoft-365/rotate", data={"_csrf": tok(st, "/integrations/microsoft-365")})
keys = [k["keyId"] for k in mock()["keys"]]
ok("Switched to the new certificate" in flash(r.text) and len(keys) == 1 and old not in keys and cur() == keys[0], "later it switches, then removes the old one: " + flash(r.text))
ok(q("select count(*) n from audit_log where action='m365.rotated'")[0]["n"] >= 2, "both steps are audited")
ok("Synced" in flash(st.post(B + f"/clients/{C1}/m365/sync", data={"_csrf": tok(st, f"/clients/{C1}/licenses")}).text), "client tenants work with the new certificate")
ok(phpo('var_export(Align\\M365\\App::rotateIfDue());') == "NULL", "nothing to do while it has a year left")
setting("m365c_cert_expires", (date.today() + timedelta(days=10)).isoformat())
tick = lambda: phpo('Align\\Mail\\Notifications::setState("health_day", "2000-01-01"); echo implode("|", Align\\Mail\\Notify::tick());')
out = tick()
ok("m365: new certificate added" in out and len(mock()["keys"]) == 2, "the daily timer adds a new one when 30 days or fewer are left")
setting("m365c_next_since", (date.today() - timedelta(days=1)).isoformat() + " 00:00:00")
out = tick()
ok("m365: switched" in out and len(mock()["keys"]) == 1 and date.fromisoformat(phpo('echo Align\\Settings::get("m365c_cert_expires");')) > date.today() + timedelta(days=300),
   "...and switches to it on the next daily run")
good = phpo('echo Align\\Settings::get("m365c_app_object_id");')
setting("m365c_app_object_id", "dddddddd-0000-4000-8000-00000000000d")
r = st.post(B + "/integrations/microsoft-365/rotate", data={"_csrf": tok(st, "/integrations/microsoft-365")})
ok("couldn't be replaced" in flash(r.text) and phpo('echo Align\\Settings::get("m365c_rotate_error");') != "", "a failed replacement is reported and kept")
ok("Microsoft 365 certificate couldn" in st.get(B + "/").text and "Microsoft 365 certificate couldn" not in tech.get(B + "/").text, "admins see it on the dashboard")
ok("Synced" in flash(st.post(B + f"/clients/{C1}/m365/sync", data={"_csrf": tok(st, f"/clients/{C1}/licenses")}).text), "...while the current certificate keeps working")
setting("m365c_app_object_id", good)
st.post(B + "/integrations/microsoft-365/rotate", data={"_csrf": tok(st, "/integrations/microsoft-365")})
ok(phpo('var_export(Align\\Settings::get("m365c_rotate_error"));') == "NULL", "the next good replacement clears the error")

# ---- the REST API on these licenses
k = phpo('[$i,$t]=Align\\Api\\Keys::create("m365c api", Align\\Api\\Keys::allScopes(), null, null, 5000, null, 1); echo $t;')
setting("api_enabled", "1")
hdr = {"Authorization": "Bearer " + k, "Content-Type": "application/json"}
spb = lic(C1, "SPB")
d = requests.get(API + f"/licenses/{spb['id']}", headers=hdr).json()["data"]
ok(d["source"] == "m365" and d["seats"] == 12, "the API shows the source m365")
r = requests.patch(API + f"/licenses/{spb['id']}", headers=hdr, data=json.dumps({"seats": 1}))
ok(r.status_code == 422 and "Microsoft 365" in r.text, "the API refuses changing what Microsoft owns")
r = requests.patch(API + f"/licenses/{spb['id']}", headers=hdr, data=json.dumps({"unit_price": 19.5}))
ok(r.status_code == 200 and lic(C1, "SPB")["price_source"] == "custom", "a price set through the API is the client's own")
ok(requests.delete(API + f"/licenses/{spb['id']}", headers=hdr).status_code == 409, "the API can't delete it")

# ---- disconnecting and reconnecting
r = tech.post(B + f"/clients/{C1}/m365/disconnect", data={"_csrf": tok(tech, f"/clients/{C1}/licenses")})
ok(not q("select 1 from client_m365 where client_id=%s", C1) and lic(C1, "SPB")["retired_reason"] == "m365" and "retired" in flash(r.text), "disconnecting retires its Microsoft 365 licenses")
mset(consent_tenant=T1)
st.get(st.post(B + f"/clients/{C1}/m365/connect", data={"_csrf": tok(st, f"/clients/{C1}/licenses")}, allow_redirects=False).headers["Location"])
ok(q("select status from client_m365 where client_id=%s", C1)[0]["status"] == "connected" and lic(C1, "SPB")["retired_at"] is None, "connecting again brings them back")

# ---- forgetting the app; an app the MSP made by hand
r = st.post(B + "/integrations/microsoft-365/forget", data={"_csrf": tok(st, "/integrations/microsoft-365")})
ok(not q("select 1 from settings where name in ('m365c_app_id','m365c_key_pem','m365c_cert_pem') and value is not null and value <> ''") and "still in your Microsoft tenant" in flash(r.text),
   "Remove from Align forgets the app and its key")
r = php(f'print_r(Align\\M365\\Tenants::syncClient({C1}));')
ok("isn't set up" in r.stdout, "clients then can't sync until it's set up again")
r = st.post(B + "/integrations/microsoft-365/manual", data={"_csrf": tok(st, "/integrations/microsoft-365"), "app_id": "nope", "tenant_id": T2, "secret": "x", "secret_expires": "2030-01-01"})
ok("GUIDs" in flash(r.text), "a bad app id is refused")
r = st.post(B + "/integrations/microsoft-365/manual", data={"_csrf": tok(st, "/integrations/microsoft-365"), "app_id": OWN, "tenant_id": T2, "secret": "own-secret", "secret_expires": (date.today() + timedelta(days=200)).isoformat()})
ok(phpo('echo Align\\M365\\App::mode();') == "manual" and "Saved" in flash(r.text), "an app made by hand can be saved instead")
q("update client_m365 set tenant_id=%s, mode='msp' where client_id=%s", T3, C1)
res = sync_one(C1)
ok(res["error"] is None, f"it reads client tenants with its secret: {res}")
ok("Replace certificate now" not in st.get(B + "/integrations/microsoft-365").text, "no certificate to replace for a hand-made app")

# ---- help
t = text(st.get(B + "/help").text)
ok("Microsoft 365 licenses from each client" in t and "Connect a client's Microsoft 365" in t, "Help has the What's new entry and the guide")
done()

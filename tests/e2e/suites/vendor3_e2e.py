"""2.10.0 Vendors, step 3: domain registrations from public RDAP (registrar and expiry; the registrar's name cleaned and
only it read; a registry that fails keeps the last good answer; unregistered and unknown endings said so; the
registrable domain worked out from email domains and the website; due domains re-read, gone ones forgotten), shown on
the client's Vendors page (matched to its vendors, or added as one), on Renewals and by vendor; the client portal's
Vendors page (only who to call: no account numbers, contacts, notes or costs; Budget & licensing access only); the QBR
pack's vendors section (switchable; costs only with costs on; portal users with budget access); the read-only REST API
for vendors and templates (vendors:read, the key's client limit)."""
import re, json, subprocess, atexit, requests, html as H
from datetime import date, timedelta
from lib import *

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def cid_of(psa): return q("select id from clients where psa_id=%s", psa)[0]["id"]
def rd(d): return (q("select * from domain_rdap where domain=%s", d) or [None])[0]

C1, C2, C3 = cid_of("1"), cid_of("2"), cid_of("3")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
tok = lambda s, p: csrf(s, p)
PW = "PortalPassword123!"


def cleanup():
    q("delete from client_email_domains where domain like '%%.test' or domain in ('example.org')")
    q("delete from domain_rdap where domain like '%%.test' or domain='example.org'")
    q("delete from client_vendors where source='manual' and client_id in (%s,%s,%s)", C1, C2, C3)
    q("delete from portal_users where email like '%%@vendor3.example'")
    q("delete from api_keys where name like 'v3 %%'")
    q("delete from login_attempts")
    mock("/mock/rdap-set", {"domains": {}})


atexit.register(cleanup)
cleanup()

# ---- registrable domains
out = phpv("echo json_encode(array_map([Align\\Domains\\Rdap::class, 'registrable'], ['https://www.shop.example.com/x', 'a.example.co.uk', 'Example.ORG.', 'localhost', '', 'mail.foo.com.au', 'x.y.z.io']));")
ok(json.loads(out) == ["example.com", "example.co.uk", "example.org", None, None, "foo.com.au", "z.io"], "the registrable domain of a host or URL: " + out)
out = phpv("echo json_encode(array_map([Align\\Domains\\Rdap::class, 'registrable'], ['co.lake.ca.us', 'k12.ca.us', 'example.us']));")
ok(json.loads(out) == [None, None, "example.us"], ".us locality names are left out: " + out)
m = phpv("$v=fn($n)=>['id'=>1,'name'=>$n,'category'=>'other']; foreach ([['GoDaddy.com, LLC','GoDaddy'],['Name.com, Inc.','Namecheap'],['Google LLC','Google Workspace'],['Tucows Domains Inc.','Tucows'],['Network Solutions, LLC','Network Cabling Co']] as [$r,$n]) echo Align\\Domains\\Rdap::matchVendor($r, [$v($n)]) ? 'y' : 'n';")
ok(m == "ynnyn", "a registrar matches a vendor by its whole distinctive name, not a shared first word: " + m)

# ---- what the seed's sync read (client 1's website domain, from the mock registry)
r1 = rd("cedarridgedental.example")
ok(r1 and r1["status"] == "ok" and r1["registrar"] == "GoDaddy.com, LLC" and str(r1["expires_on"]) == (date.today() + timedelta(days=40)).isoformat(),
   "the sync reads a domain's registrar (cleaned) and expiry date: %s" % (r1,))
ok(not q("select 1 from domain_rdap where registrar like 'Should Not%%'"), "only the registrar's name is read (not the registrant's)")
vp = tech.get(B + f"/clients/{C1}/vendors").text
dom = vp[vp.find('id="domains"'):]
ok('id="domains-table"' in vp and "cedarridgedental.example" in dom and re.search(r'fa-store[^>]*></i>GoDaddy', dom) and "GoDaddy.com, LLC" in dom and "in 40 days" in text(dom),
   "the Vendors page shows the domain, matched to the client's vendor GoDaddy, and its expiry")
rp = tech.get(B + "/renewals?days=90").text
ok(re.search(r"Domain expires:.{0,40}cedarridgedental\.example", text(rp)) and re.search(r'vendor=godaddy">GoDaddy</a>', rp), "Renewals shows the domain's expiry, by vendor too")
ok(re.search(r'fa-globe[^"]*"></i><b>Domain expires', rp) and "not renewing" not in text(rp[rp.find("cedarridgedental"):][:400]), "...as a domain (whether it renews by itself isn't claimed)")

# ---- more domains for client 2: no expiry, a failing registry, unregistered, an ending without RDAP
for d in ("noexpiry.test", "failing.test", "unregistered.test", "example.org"):
    q("insert into client_email_domains (client_id, domain, origin) values (%s, %s, 'manual')", C2, d)
q("insert into domain_rdap (domain, registrar, expires_on, status, checked_at) values ('failing.test', 'Old Registrar', '2030-01-01', 'ok', NOW() - INTERVAL 10 DAY)")
r = tech.post(B + f"/clients/{C2}/domains/check", data={"_csrf": tok(tech, f"/clients/{C2}/vendors"), "back": f"/clients/{C2}/vendors"})
ok("Read 4 domains" in flash(r.text), "Check now reads the client's domains: " + flash(r.text)[:80])
ok(rd("noexpiry.test")["status"] == "ok" and rd("noexpiry.test")["registrar"] == "Example Registrar, Inc." and rd("noexpiry.test")["expires_on"] is None, "a registry without an expiry date: the registrar only")
f = rd("failing.test")
ok(f["status"] == "unknown" and f["registrar"] == "Old Registrar" and str(f["expires_on"]) == "2030-01-01" and "couldn't be reached" in f["detail"], "a failing registry keeps the last good answer")
ok(rd("unregistered.test")["status"] == "missing" and rd("example.org")["status"] == "unknown" and "No public registration service for .org" in rd("example.org")["detail"],
   "an unregistered domain and an ending without RDAP are said so")
p2 = tech.get(B + f"/clients/{C2}/vendors").text
d2 = text(p2[p2.find('id="domains"'):])
ok("Not registered" in d2 and "Unknown" in d2 and "Add as vendor" in d2, "the card says which aren't registered or known, and offers registrars as vendors")
tech.post(B + f"/clients/{C2}/vendors", data={"_csrf": tok(tech, f"/clients/{C2}/vendors"), "name": "Example Registrar", "category": "registrar", "back": f"/clients/{C2}/vendors"})
p2 = tech.get(B + f"/clients/{C2}/vendors").text
ok(re.search(r'fa-store[^>]*></i>Example Registrar', p2[p2.find('id="domains"'):]), "once added, the registrar is matched to the client's vendor")
ok(phpv("echo Align\\Domains\\Rdap::shortName('GoDaddy.com, LLC'), '|', Align\\Domains\\Rdap::shortName('Tucows Domains Inc.'), '|', Align\\Domains\\Rdap::shortName('Gandi SAS');") == "GoDaddy.com|Tucows Domains|Gandi SAS",
   "a registrar's company suffix is left off its vendor name")

# ---- refreshDue: recent ones wait, old ones are read, gone ones forgotten
out = phpv("echo Align\\Domains\\Rdap::refreshDue();")
ok(out == "nothing due", "domains read recently wait: " + out)
q("update domain_rdap set checked_at = NOW() - INTERVAL 4 DAY where domain='noexpiry.test'")
out = phpv("echo Align\\Domains\\Rdap::refreshDue();")
ok(out == "1 domain read", "a domain read 4 days ago is read again: " + out)
q("update domain_rdap set checked_at = NOW() - INTERVAL 30 HOUR, expires_on = CURDATE() + INTERVAL 20 DAY where domain='noexpiry.test'")
ok(phpv("echo Align\\Domains\\Rdap::refreshDue();") == "1 domain read", "one expiring soon is read daily")
q("delete from client_email_domains where domain='unregistered.test'")
phpv("Align\\Domains\\Rdap::refreshDue();")
ok(rd("unregistered.test"), "a domain no client has any more is kept a while (a connection may come back)")
q("update domain_rdap set checked_at = NOW() - INTERVAL 31 DAY where domain='unregistered.test'")
phpv("Align\\Domains\\Rdap::refreshDue();")
ok(not rd("unregistered.test"), "...and forgotten after a month")
viewer.post(B + f"/clients/{C2}/domains/check", data={"_csrf": tok(viewer, f"/clients/{C2}"), "back": f"/clients/{C2}/vendors"})
ok('domains/check' not in viewer.get(B + f"/clients/{C2}/vendors").text, "viewers don't get Check now")

# ---- client portal: Vendors (Budget & licensing only), who to call and nothing else
q("update client_vendors set account_number='SECRET-ACCT', contact_name='Secret Rep', align_notes='Secret note', services='Fiber 1 Gbps' where psa_id='10'")
def mkportal(email, secret, budget):
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{C1},"email"=>"{email}","name"=>"Pat Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        f'"can_roadmap"=>1,"can_budget"=>{budget},"can_devices"=>1,"can_documents"=>1,"can_approve"=>0,"can_contacts"=>0,"can_submit"=>0,'
        f'"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{secret}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
def plogin(email, secret):
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(secret)})
    return s
mkportal("budget@vendor3.example", "MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U", 1)
mkportal("nobudget@vendor3.example", "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ", 0)
ps = plogin("budget@vendor3.example", "MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U")
pv = ps.get(B + "/portal/vendors").text
ok('id="portal-vendors"' in pv and "Comcast Business" in pv and "Fiber 1 Gbps" in pv and "+1 800-555-0101 ext. 2" in pv and 'href="/portal/vendors"' in pv,
   "the portal's Vendors page lists the client's vendors and how to reach them")
ok(all(x not in pv for x in ("SECRET-ACCT", "Secret Rep", "Secret note", "Circuit 12/ABCD")) and "javascript:" not in pv, "...without account numbers, contacts, notes or unsafe links")
pn = plogin("nobudget@vendor3.example", "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ")
r = pn.get(B + "/portal/vendors")
ok(r.status_code == 403 and 'href="/portal/vendors"' not in pn.get(B + "/portal").text, "a portal user without Budget & licensing doesn't get it")
ok("Domain expires" not in ps.get(B + "/portal").text, "domain dates don't show in the portal")

# ---- QBR pack: the vendors section
qb = tech.get(B + f"/clients/{C1}/report/qbr").text
ok("Your vendors" in qb and "Comcast Business" in qb and "SECRET-ACCT" not in qb and "Secret Rep" not in qb, "the QBR pack has a vendors section, without account numbers or contacts")
ok('name="s_vendors"' in qb or "s_vendors" in qb, "...with a switch")
ok("Your vendors" not in tech.get(B + f"/clients/{C1}/report/qbr?s_vendors=0").text, "switched off, it's left out")
qc = tech.get(B + f"/clients/{C1}/report/qbr?costs=0").text
vsec = qc[qc.find("Your vendors"):][:4000]
ok("Your vendors" in qc and "Monthly" not in vsec, "with costs off it stays, without costs")
q("delete from client_vendors where client_id=%s and source='manual'", C3)
q4 = tech.get(B + f"/clients/{C3}/report/qbr").text
ok("Your vendors" not in q4 and "s_vendors" not in q4, "a client without vendors gets no section and no switch")
pq = ps.get(B + "/portal/report/qbr").text
ok("Your vendors" in pq and "SECRET-ACCT" not in pq, "a portal user with budget access gets it in the portal's QBR")
ok("Your vendors" not in pn.get(B + "/portal/report/qbr").text, "...one without doesn't")
q("update client_vendors set account_number='CB-1001', contact_name='Pat Rep', align_notes=NULL, services=NULL where psa_id='10'")

# ---- REST API (read-only)
def mk(name, scopes, clients=None):
    code = f'[$i,$t]=Align\\Api\\Keys::create({json.dumps(name)}, json_decode({json.dumps(json.dumps(scopes))},true), ' + \
        (f'json_decode({json.dumps(json.dumps(clients))},true)' if clients is not None else 'null') + ', null, 5000, null, 1); echo $t;'
    return phpv(code)
def call(key, path): return requests.get(B + "/api/v1" + path, headers={"Authorization": "Bearer " + key}, timeout=60)
full = mk("v3 all", ["vendors:read", "clients:read"])
lim = mk("v3 limited", ["vendors:read"], [C2])
none = mk("v3 none", ["clients:read"])
r = call(full, f"/vendors?client_id={C1}")
d = r.json()
v10 = [x for x in d.get("data", []) if x["psa_id"] == "10"]
ok(r.status_code == 200 and v10 and v10[0]["name"] == "Comcast Business" and v10[0]["source"] == "itflow" and v10[0]["template_name"] == "Comcast Business", "GET /vendors lists a client's vendors: %s" % r.status_code)
tv = call(full, f"/vendors?client_id={C2}").json()["data"]
er = [x for x in tv if x["name"] == "Example Registrar"]
ok(er and er[0]["category"] == "registrar" and er[0]["source"] == "manual", "...including ones added in Align")
ok(call(full, "/vendors?category=internet").json()["meta"]["total"] >= 2 and all(x["category"] == "internet" for x in call(full, "/vendors?category=internet").json()["data"]), "?category= filters by category as shown")
one = call(full, f"/vendors/{v10[0]['id']}").json()["data"]
ok(one["account_number"] == "CB-1001" and "support_phone" in one and isinstance(one["from_template"], list), "GET /vendors/{id} returns one vendor")
ok(call(lim, f"/vendors/{v10[0]['id']}").status_code == 404 and call(lim, f"/vendors?client_id={C1}").status_code == 404
   and all(x["client_id"] == C2 for x in call(lim, "/vendors").json()["data"]), "a key limited to other clients sees only its own clients' vendors")
ok(call(none, "/vendors").status_code == 403, "a key without vendors:read is refused")
tp = call(full, "/vendor-templates").json()["data"]
ok(any(t["name"] == "Comcast Business" and t["psa_template_id"] == "5" and t["clients"] >= 2 for t in tp), "GET /vendor-templates lists the templates with how many clients use them")
tl = call(lim, "/vendor-templates").json()["data"]
ok(tl and all(t["notes"] is None and t["clients"] is None for t in tl), "...a key limited to some clients gets them without the notes and client counts")
ok(requests.post(B + "/api/v1/vendors", headers={"Authorization": "Bearer " + full, "Content-Type": "application/json"}, data="{}").status_code in (404, 405), "vendors can't be written through the API")
spec = requests.get(B + "/api/v1/openapi.json", timeout=60).json()
ok("/api/v1/vendors" in spec["paths"] and "Vendor" in spec["components"]["schemas"] and "/api/v1/vendor-templates" in spec["paths"], "the OpenAPI spec describes them")
done()

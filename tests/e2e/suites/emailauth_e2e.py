"""2.7.4 Email authentication without false negatives, against the mock resolvers (tests/mock-server.php /doh/a and
/doh/b standing in for Cloudflare and Google DNS-over-HTTPS, /dns for the server's own resolver): a record only one
public resolver sees still counts; a SERVFAIL at one is answered by the other; both down falls back to the server's
resolver; long records split in parts are joined. DKIM at the domain's own selectors (asked of both resolvers) and the
common ones (one resolver each). Email domains for any client (no Microsoft 365 or Google Workspace needed): added,
suggested from the website, refused when not a domain, techs only, audited; several domains per client, the worst
counting; the domain a connection brings in left out and brought back; removed domains' results dropped; the health
score and the overview card; the migration running again."""
import re, json, html as H
from lib import *

C1, C2 = 1, 2
A, Bn = M + "/doh/a", M + "/doh/b"


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def phpo(code): r = php(code); return (r.stdout + r.stderr).strip()
def gset(**kw): requests.post(M + "/mock/gws-set", data=json.dumps(kw))
def doh_calls(): return requests.get(M + "/mock/gws").json().get("doh_calls", [])
def res(cid, dom): r = q("select result_json from client_email_auth where client_id=%s and domain=%s", cid, dom); return json.loads(r[0]["result_json"]) if r else None
def stored(cid): return json.loads(phpo(f"echo json_encode(Align\\Domains\\EmailAuth::stored({cid}));") or "null")
KEY = "v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAx"


def cleanup():
    q("delete from client_email_auth"); q("delete from client_email_domains"); q("delete from client_m365 where tenant_id like 'eeeeeeee-%%'")
    q("delete from settings where name in ('dns_mock_url','dns_doh_urls')")
    q("update clients set website=NULL where id=%s", C2)
    requests.get(M + "/mock/gws-reset")


cleanup()
import atexit
atexit.register(cleanup)
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
tok = lambda s, p: csrf(s, p)
setting("dns_mock_url", M + "/dns")
setting("dns_doh_urls", f"{A},{Bn}")

# ---- the migration
ok(q("select count(*) n from information_schema.tables where table_schema=database() and table_name='client_email_domains'")[0]["n"] == 1
   and [r["c"] for r in q("select column_name c from information_schema.key_column_usage where table_schema=database() and table_name='client_email_auth' and constraint_name='PRIMARY' order by ordinal_position")] == ["client_id", "domain"],
   "migration 065 adds client_email_domains and keys results by client and domain")
r = php('(require "' + ROOT + '/db/migrations/065_email_domains.php")();')
ok(r.returncode == 0 and not (r.stdout + r.stderr).strip(), "the migration can run again: " + (r.stdout + r.stderr)[:200])

# ---- a domain for a client without Microsoft 365 or Google Workspace, suggested from its website
q("update clients set website='https://www.harbor-mail.test/contact' where id=%s", C2)
t = tech.get(B + f"/clients/{C2}/connectors").text
ok('id="email-auth"' in t and 'value="harbor-mail.test"' in t, "the Connectors page offers to add the domain, suggested from the website")
LONG = "v=spf1 include:_spf.example-a.test include:_spf.example-b.test " + " ".join(f"ip4:198.51.100.{i}" for i in range(1, 30)) + " -all"
gset(dns={"harbor-mail.test": [LONG], "_dmarc.harbor-mail.test": ["v=DMARC1; p=quarantine; rua=mailto:d@harbor-mail.test"],
          "mimecast20190104._domainkey.harbor-mail.test": [KEY]})
r = tech.post(B + f"/clients/{C2}/email-domains", data={"_csrf": tok(tech, f"/clients/{C2}/connectors"), "domain": "https://www.Harbor-Mail.test/", "back": "connectors"})
ok("Added harbor-mail.test" in flash(r.text) and q("select 1 from client_email_domains where client_id=%s and domain='harbor-mail.test'", C2) and q("select 1 from audit_log where action='email_domain.add'"),
   "a tech adds it (address cleaned to the domain), audited, checked right away: " + flash(r.text)[:120])
e = res(C2, "harbor-mail.test")
ok(e["checks"]["email_spf"]["status"] == "pass" and len(LONG) > 255, "a long SPF record split in 255-byte parts is joined and passes")
ok(e["checks"]["email_dmarc"]["status"] == "pass", "DMARC from the public resolvers")
ok(e["checks"]["email_dkim"]["status"] == "fail" and "If it signs with another selector" in e["checks"]["email_dkim"]["detail"],
   "DKIM at a selector of its own isn't found among the common ones: " + e["checks"]["email_dkim"]["detail"])
calls = doh_calls()
common = [n for _, n in calls if n.startswith("k1._domainkey.")]
ok(len(common) == 1, f"common selectors are asked of one resolver only ({len(common)} for k1)")
r = tech.post(B + f"/clients/{C2}/email-domains/selectors", data={"_csrf": tok(tech, f"/clients/{C2}/connectors"), "domain": "harbor-mail.test", "dkim_selectors": "Mimecast20190104, bad/one", "back": "connectors"})
e = res(C2, "harbor-mail.test")
ok(q("select dkim_selectors from client_email_domains where domain='harbor-mail.test'")[0]["dkim_selectors"] == "mimecast20190104"
   and e["checks"]["email_dkim"]["status"] == "pass" and "mimecast20190104" in e["checks"]["email_dkim"]["detail"], "with its selector set, DKIM passes (invalid selectors dropped)")

# ---- resolvers disagreeing, failing, down
gset(doh={"a": {"missing": ["harbor-mail.test"]}}, clear_calls=True)
phpo(f"Align\\Domains\\EmailAuth::refreshClient({C2});")
ok(res(C2, "harbor-mail.test")["checks"]["email_spf"]["status"] == "pass", "a record the first resolver misses but the second sees (in Google's unquoted form) passes: no false negative")
gset(doh={"a": {"fail": ["_dmarc.harbor-mail.test"]}})
phpo(f"Align\\Domains\\EmailAuth::refreshClient({C2});")
ok(res(C2, "harbor-mail.test")["checks"]["email_dmarc"]["status"] == "pass", "SERVFAIL at one resolver: the other answers")
gset(doh={"a": {"down": True}, "b": {"down": True}}, clear_calls=True)
phpo(f"Align\\Domains\\EmailAuth::refreshClient({C2});")
e = res(C2, "harbor-mail.test")
ok(e["checks"]["email_spf"]["status"] == "pass" and e["checks"]["email_dkim"]["status"] == "pass", "both resolvers down: the server's own resolver answers")
ok(len(doh_calls()) == 2, f"...and an unreachable resolver is asked only once per run, not for every name ({len(doh_calls())} questions)")
gset(doh={"a": {"down": True}, "b": {"down": True}}, dns_fail=["harbor-mail.test"])
phpo(f"Align\\Domains\\EmailAuth::refreshClient({C2});")
ok(res(C2, "harbor-mail.test")["checks"]["email_spf"]["status"] == "unknown", "no resolver at all: unknown, never failed")
gset(doh={}, dns_fail=[], dns={"harbor-mail.test": ["v=spf1 include:a ?all"]})
phpo(f"Align\\Domains\\EmailAuth::refreshClient({C2});")
ok(res(C2, "harbor-mail.test")["checks"]["email_spf"]["status"] == "fail", "a real problem both resolvers agree on still fails")
gset(dns={"harbor-mail.test": [LONG]})
phpo(f"Align\\Domains\\EmailAuth::refreshClient({C2});")

# ---- several domains: the worst counts, each listed
gset(dns={"harbor-old.test": ["v=spf1 -all"], "_dmarc.harbor-old.test": ["v=DMARC1; p=none"]})
tech.post(B + f"/clients/{C2}/email-domains", data={"_csrf": tok(tech, f"/clients/{C2}/connectors"), "domain": "harbor-old.test", "back": "connectors"})
s = stored(C2)
ok(s["checks"]["email_dmarc"]["status"] == "fail" and "harbor-old.test" in s["checks"]["email_dmarc"]["detail"] and set(s["domains"]) == {"harbor-mail.test", "harbor-old.test"},
   "with two domains the client's result is the worst, naming the domain: " + s["checks"]["email_dmarc"]["detail"])
ok(s["checks"]["email_spf"]["status"] == "pass" and "All 2 domains pass" in s["checks"]["email_spf"]["detail"], "...and a check both pass says so")
h = json.loads(phpo(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C2}));") or "{}")
ok(h.get("email_dmarc", {}).get("ok") is False and h.get("email_spf", {}).get("ok") is True, "they count in the client's security checks without Microsoft 365 or Google Workspace")
t = text(tech.get(B + f"/clients/{C2}/connectors").text)
ok("harbor-old.test" in t and "added here" in t and "Check now" in t, "the card lists each domain")
t = st.get(B + f"/clients/{C2}").text
ok('id="email-auth"' in t and f"/clients/{C2}/connectors#email-auth" in t, "the overview shows the card, linking to the domains")
r = tech.post(B + f"/clients/{C2}/email-domains/remove", data={"_csrf": tok(tech, f"/clients/{C2}/connectors"), "domain": "harbor-old.test", "back": "connectors"})
ok(not q("select 1 from client_email_domains where domain='harbor-old.test'") and not q("select 1 from client_email_auth where domain='harbor-old.test'")
   and stored(C2)["checks"]["email_dmarc"]["status"] == "pass", "removing a domain drops its result")

# ---- the domain a connection brings in: left out and brought back; its selectors kept
q("insert into client_m365 (client_id, status, tenant_id, tenant_name, tenant_domain) values (%s, 'connected', 'eeeeeeee-aaaa-4bbb-8ccc-000000000001', 'T', 'tenant-mail.test')", C1)
gset(dns={"tenant-mail.test": ["v=spf1 include:spf.protection.outlook.com -all"], "selector1._domainkey.tenant-mail.test": [KEY]})
phpo("echo Align\\Domains\\EmailAuth::refreshDue(true);")
ok(res(C1, "tenant-mail.test")["checks"]["email_dkim"]["status"] == "pass", "the Microsoft 365 domain is checked as before")
tech.post(B + f"/clients/{C1}/email-domains/remove", data={"_csrf": tok(tech, f"/clients/{C1}/connectors"), "domain": "tenant-mail.test", "back": "connectors"})
ok(q("select skip from client_email_domains where client_id=%s and domain='tenant-mail.test'", C1)[0]["skip"] == 1 and not res(C1, "tenant-mail.test"),
   "the connection's domain is left out, not deleted, and its result goes")
ok("left out" in text(tech.get(B + f"/clients/{C1}/connectors").text), "...shown as left out with a way back")
phpo("echo Align\\Domains\\EmailAuth::refreshDue(true);")
ok(not res(C1, "tenant-mail.test"), "the daily check leaves it out too")
tech.post(B + f"/clients/{C1}/email-domains/remove", data={"_csrf": tok(tech, f"/clients/{C1}/connectors"), "domain": "tenant-mail.test", "restore": "1", "back": "connectors"})
ok(res(C1, "tenant-mail.test") and q("select 1 from audit_log where action='email_domain.restore'"), "...and brought back")

# ---- a stale domain counts as unknown, not left out
q("update client_email_auth set result_json=json_set(result_json, '$.at', %s) where client_id=%s and domain='tenant-mail.test'", "2020-01-01 00:00:00", C1)
q("insert into client_email_domains (client_id, domain) values (%s, 'second-mail.test')", C1)
gset(dns={"second-mail.test": ["v=spf1 -all"], "_dmarc.second-mail.test": ["v=DMARC1; p=reject"], "k1._domainkey.second-mail.test": [KEY]})
phpo(f"Align\\Domains\\EmailAuth::refresh({C1}, 'second-mail.test', null);")
s = stored(C1)
ok(s["checks"]["email_spf"]["status"] == "unknown" and "tenant-mail.test" in s["checks"]["email_spf"]["detail"], "a domain not checked for two days makes the client's checks unknown, naming it")
ok(s["checks"]["email_dkim"]["status"] == "unknown", "...even where the other domain passes (k1, a common selector)")

# ---- a connection's selectors are forgotten with the connection; Microsoft's selector reached through a CNAME
gset(cname={"selector1._domainkey.tenant-mail.test": "selector1-tenant-mail-test._domainkey.tenantmail.onmicrosoft.test"}, dns={"selector1._domainkey.tenant-mail.test": [KEY]}, doh={"a": {"down": True}})
q("delete from client_email_auth where client_id=%s and domain='tenant-mail.test'", C1)
phpo("echo Align\\Domains\\EmailAuth::refreshDue();")
ok(res(C1, "tenant-mail.test")["checks"]["email_dkim"]["status"] == "pass", "Microsoft 365's DKIM through its CNAME, answered by the second resolver")
gset(doh={})
tech.post(B + f"/clients/{C1}/email-domains/selectors", data={"_csrf": tok(tech, f"/clients/{C1}/connectors"), "domain": "tenant-mail.test", "dkim_selectors": "custom1", "back": "connectors"})
ok(q("select origin from client_email_domains where client_id=%s and domain='tenant-mail.test'", C1)[0]["origin"] == "connection", "selectors for the connection's domain are kept as the connection's")
q("delete from client_m365 where client_id=%s", C1)
phpo("echo Align\\Domains\\EmailAuth::refreshDue();")
ok(not q("select 1 from client_email_domains where client_id=%s and domain='tenant-mail.test'", C1) and not res(C1, "tenant-mail.test")
   and res(C1, "second-mail.test"), "disconnected: the connection's domain and its selectors go; a domain added by hand stays")

# ---- refused input and roles
ok(phpo("var_export([Align\\Domains\\EmailAuth::selectorOk('a.'), Align\\Domains\\EmailAuth::selectorOk('-x'), Align\\Domains\\EmailAuth::selectorOk('s1.mail'), Align\\Domains\\EmailAuth::selectorOk('x_y-1')]);").replace("\n", "").replace(" ", "")
   == "array(0=>false,1=>false,2=>true,3=>true,)", "selectors that can't resolve (a trailing dot, a leading dash) are refused")
r = tech.post(B + f"/clients/{C2}/email-domains", data={"_csrf": tok(tech, f"/clients/{C2}/connectors"), "domain": "not a domain; drop", "back": "connectors"})
ok("Enter a domain name" in flash(r.text), "something that isn't a domain is refused")
r = tech.post(B + f"/clients/{C2}/email-domains/selectors", data={"_csrf": tok(tech, f"/clients/{C2}/connectors"), "domain": "someone-else.test", "dkim_selectors": "x", "back": "connectors"})
ok("isn't one of this client's" in flash(r.text) and not q("select 1 from client_email_domains where domain='someone-else.test'"), "selectors only for the client's own domains")
n = q("select count(*) n from client_email_domains")[0]["n"]
viewer.post(B + f"/clients/{C2}/email-domains", data={"_csrf": tok(viewer, f"/clients/{C2}"), "domain": "viewer.test"})
ok(q("select count(*) n from client_email_domains")[0]["n"] == n, "viewers can't add domains")
ok("/email-domains" not in viewer.get(B + f"/clients/{C2}").text, "...or see the forms")
done()

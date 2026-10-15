"""Fabrikam PSA (community connector, beta, read-only): the Integrations card and setup page (admins only), "Not in use"
while another PSA is this install's, the Test button and a refused key, and the provider's reads: clients, contacts
and assets across pages with exact whole-number ids (loose ids, bad emails, unsafe websites, unreal dates and
placeholder serials dropped, text cleaned), wrong answers (an HTML page, a reply without items, an outage, a page number
that doesn't move on) refused as errors, and every write refused.

The test install's PSA is ITFlow, so this suite calls the provider directly instead of syncing it.
`align make:connector` copies this suite for a new connector: change the expected values to your mock's data."""
import re, json, atexit, requests, html as H
from lib import *

# template-only: begin
dev_connectors("Align\\Templates\\Psa\\Connector\\FabrikamPsa")
# template-only: end

KEY = "fabrikampsa"
NAME = "Fabrikam PSA"


def mock(body=None): return requests.post(M + f"/mock/{KEY}-set", json=body or {}).json()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))


def call(expr):
    """Runs $p->EXPR on the connector's provider: the decoded result, or 'ERR: message'."""
    r = php(f'$p = Align\\Integrations\\Registry::get("{KEY}")->provider(); try {{ echo json_encode({expr}); }} catch (Throwable $e) {{ echo "ERR: " . $e->getMessage(); }}')
    out = r.stdout.strip()
    return out if out.startswith("ERR: ") or not out else json.loads(out)


st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)


def cleanup():
    mock({})
    q("delete from settings where name like %s", KEY + "\\_%")


atexit.register(cleanup)
cleanup()

# ---- the card and page
t = st.get(B + "/integrations").text
ok(NAME in t and "Beta" in t, "the Integrations page lists the connector, marked beta")
p = st.get(B + f"/integrations/{KEY}").text
ok(f'name="{KEY}_url"' in p and f'name="{KEY}_api_key"' in p and "read-only" in p and not errs(p), "its page asks for the API address and key, with setup steps")
ok('name="psa_two_way"' not in p and 'name="psa_writeback"' not in p, "a read-only PSA offers no settings that write to it")
ok(tech.get(B + f"/integrations/{KEY}", allow_redirects=False).status_code in (302, 403), "techs can't open it")

# ---- set up (settings saved directly: its form would also save the PSA settings ITFlow uses here)
setting(KEY + "_url", M + f"/{KEY}")
setting(KEY + "_api_key", f"{KEY}-key", True)
p = st.get(B + f"/integrations/{KEY}").text
ok("Not in use" in p and "ITFlow" in p and f"{KEY}-key" not in p and not errs(p), "set up beside ITFlow it shows Not in use, and the key isn't shown")
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("Connected. 2 clients readable." in text(r.text), "Test reads the clients: " + flash(r.text)[:200])

# ---- reads
c = {x["id"]: x for x in call("$p->clients()")}
ok(set(c) == {"501", "502"}, "only exact whole-number client ids are kept, across pages (leading zero, decimal, negative dropped): %s" % sorted(c))
ok(c["501"]["website"] == "harborview.example" and c["501"]["email"] == "office@harborview.example" and c["501"]["archived"] is False, "a client's website and email")
ok(c["502"]["name"] == "Northwind Supply" and c["502"]["website"] is None and c["502"]["email"] is None and c["502"]["archived"] is True,
   "control characters removed; an unsafe website and a bad email dropped; inactive is archived")
k = {x["id"]: x for x in call("$p->contacts()")}
ok(set(k) == {"9001", "9002"} and k["9001"]["client_id"] == "501" and k["9001"]["primary"] is True and k["9001"]["email"] == "dana@harborview.example",
   "contacts with a valid id, client and name, under their client")
ok(k["9002"]["email"] is None and k["9002"]["archived"] is True, "a bad email is dropped; inactive is archived")
a = {x["id"]: x for x in call("$p->assets()")}
ok(set(a) == {"7001", "7002"}, "assets with valid ids")
ok(a["7001"]["serial"] == "FABPSA01" and a["7001"]["purchase_date"] == "2023-02-28" and a["7001"]["warranty_expire"] is None,
   "an asset's serial and purchase date; a date that doesn't exist is dropped")
ok(a["7002"]["serial"] is None and a["7002"]["archived"] is True and a["7002"]["description"] == "line one line two", "a placeholder serial is dropped; inactive is archived")
ok(call('$p->mapAssetType(["type" => "Workstation", "make" => "Dell", "model" => "OptiPlex 7090", "name" => "HV-PC-01", "os" => "Windows 11 Pro"])')[0] == "Desktop",
   "asset types map to Align's")
ok(call("$p->asset('7001')")["name"] == "HV-PC-01" and call("$p->asset('999')") is None, "one asset by id; a missing one is null")
ok(call("$p->locations()") == [] and call("$p->invoices()") == [] and call("$p->supports('tickets')") is False, "what it doesn't read is empty and not offered")
for w in ["$p->updateAsset('501', '7001', ['name' => 'x'])", "$p->createContact('501', ['name' => 'x'])", "$p->createTicket('501', 'x', 'y')"]:
    ok(str(call(w)).startswith("ERR: ") and "read-only" in str(call(w)), "a write is refused: " + w.split("(")[0])

# ---- wrong answers are errors, never "no records"
for mode, why in [("html", "other than a list"), ("noitems", "other than a list"), ("down", "couldn't be read"), ("loop", "doesn't move forward")]:
    mock({"mode": mode})
    r = call("$p->clients()")
    ok(isinstance(r, str) and why in r, f"a {mode} answer is an error: {str(r)[:120]}")
mock({})
setting(KEY + "_api_key", "wrong-key", True)
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("refused the API key" in text(r.text), "a wrong key is explained")
done()

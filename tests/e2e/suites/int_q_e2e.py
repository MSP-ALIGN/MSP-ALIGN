"""2.2.1 review of outbound HTTP and the integration connectors: the address policy (https only, never loopback or
cloud metadata), headers, redirects and retries in HttpClient, the connector form's address and key checks, and
remote data from ITFlow, NinjaOne, Veeam, Dell and Lenovo handled defensively (types, paging, ids)."""
from lib import *
import json, threading, subprocess, http.server, urllib.parse

PHP = 'require "' + BOOTSTRAP + '"; '
# The test config allows http (the mocks): this turns that off for one php run, as on a production server
SECURE = "$p = new ReflectionProperty(Align\\Config::class, 'data'); $d = $p->getValue(); $d['allow_insecure_integrations'] = false; $p->setValue(null, $d); "


def phpv(code, timeout=120):
    try:
        r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True, timeout=timeout)
        return (r.stdout + r.stderr).strip()
    except subprocess.TimeoutExpired:
        return "TIMEOUT"


def tryc(code): return "try { " + code + " } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(); }"


class Srv:
    """A throwaway local HTTP server: routes map a path to fn(query, body) -> (status, headers, data); hits are recorded."""
    def __init__(self):
        self.routes, self.hits = {}, []
        outer = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def _do(self):
                n = int(self.headers.get("Content-Length") or 0)
                body = self.rfile.read(n) if n else b""
                u = urllib.parse.urlsplit(self.path)
                outer.hits.append({"method": self.command, "path": u.path, "query": urllib.parse.parse_qs(u.query), "headers": dict(self.headers), "body": body})
                fn = outer.routes.get(u.path)
                status, hdrs, data = fn(urllib.parse.parse_qs(u.query), body) if fn else (404, {}, {"error": "not found"})
                if not isinstance(data, (bytes, str)):
                    data = json.dumps(data)
                data = data.encode() if isinstance(data, str) else data
                self.send_response(status)
                for k, v in hdrs.items():
                    self.send_header(k, v)
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)
            do_GET = do_POST = do_PATCH = do_PUT = _do
            def log_message(self, *a): pass

        self.srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.url = "http://127.0.0.1:%d" % self.srv.server_address[1]
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()

    def reset(self, routes):
        self.routes, self.hits = routes, []

    def close(self):
        self.srv.shutdown(); self.srv.server_close()


S = Srv()
U = S.url
PORT = S.srv.server_address[1]
JSON = {"Content-Type": "application/json"}

# ---- address policy: https only unless the config allows http (a development copy)
out = phpv(SECURE + tryc(f"(new Align\\Http\\HttpClient(5, 1))->request('GET', '{M}/v2/organizations'); echo 'SENT';"))
ok("Only https://" in out and "SENT" not in out, "with allow_insecure_integrations off, an http:// request is refused before sending: " + out[:120])

# ---- never loopback on a real server, whatever the spelling
for u in [f"https://127.0.0.1:{PORT}/", f"https://0x7f.1:{PORT}/", f"https://2130706433:{PORT}/", f"https://[::1]:{PORT}/", f"https://localhost:{PORT}/", f"https://[::ffff:127.0.0.1]:{PORT}/"]:
    out = phpv(SECURE + tryc(f"(new Align\\Http\\HttpClient(3, 1))->request('GET', '{u}'); echo 'SENT';"))
    ok("loopback" in out and "SENT" not in out, f"loopback {u} is refused on a production config: " + out[-90:])

# ---- never cloud metadata or link-local, even on a development copy
for u in ["http://169.254.169.254/latest/meta-data/", "http://2852039166/", "http://0xa9fea9fe/", "http://0251.0376.0251.0376/", "http://[fd00:ec2::254]/",
          "http://[::ffff:169.254.169.254]/", "http://[64:ff9b::a9fe:a9fe]/", "http://100.100.100.200/", "http://[fe80::1]/"]:
    out = phpv(tryc(f"(new Align\\Http\\HttpClient(2, 1))->request('GET', '{u}'); echo 'SENT';"))
    ok("metadata" in out and "SENT" not in out, f"metadata/link-local {u} is refused: " + out[-90:])
out = phpv(tryc("(new Align\\Http\\HttpClient(2, 1))->request('GET', 'http://0.0.0.0:%d/'); echo 'SENT';" % PORT))
ok("not a usable address" in out, "0.0.0.0 is refused: " + out[-80:])

# ---- private ranges stay allowed (an on-premises ITFlow or Veeam console): only the policy is checked here, no connection
out = phpv(SECURE + "foreach (['https://10.0.0.5/', 'https://192.168.1.20:8443/', 'https://172.16.4.4/', 'https://[fd12:3456::1]/', 'https://itflow.example.com/'] as $u) { echo Align\\Http\\HttpClient::check($u), ' '; }")
ok(out.split() == ["10.0.0.5", "192.168.1.20", "172.16.4.4", "[fd12:3456::1]", "itflow.example.com"], "private addresses and names pass the policy: " + out[:120])

# ---- a header with a line break (a pasted key, a token from a server) is never sent
S.reset({"/h": lambda qs, b: (200, JSON, {"ok": True})})
out = phpv(tryc(f"(new Align\\Http\\HttpClient(5, 1))->request('GET', '{U}/h', ['X-Key' => \"a\\r\\nX-Injected: 1\"]); echo 'SENT';"))
ok("line break" in out and not S.hits, "a header value with CR/LF is refused and nothing reaches the server: " + out[-80:])

# ---- a user name or password in the URL is refused (it would be sent as a login)
S.reset({"/h": lambda qs, b: (200, JSON, {"ok": True})})
out = phpv(tryc(f"(new Align\\Http\\HttpClient(5, 1))->request('GET', 'http://user:pw@127.0.0.1:{PORT}/h'); echo 'SENT';"))
ok("user name" in out and not S.hits, "a URL with user:password@ is refused: " + out[-80:])

# ---- redirects are an error, not an empty "success"
S.reset({"/moved": lambda qs, b: (302, {"Location": "http://169.254.169.254/latest/meta-data/"}, "")})
out = phpv(tryc(f"$r = (new Align\\Http\\HttpClient(5, 1))->request('GET', '{U}/moved'); echo 'STATUS ', $r['status'];"))
ok("HTTP 302" in out and "redirect" in out and "STATUS" not in out and len(S.hits) == 1, "a 302 throws (and is never followed): " + out[-80:])

# ---- a POST is not sent again after a 5xx (ITFlow may already have made the asset, Microsoft sent the email)
S.reset({"/create": lambda qs, b: (500, JSON, {"error": "boom"})})
out = phpv(tryc(f"(new Align\\Http\\HttpClient(5, 3))->request('POST', '{U}/create', ['Content-Type' => 'application/json'], '{{}}');"))
ok("HTTP 500" in out and len(S.hits) == 1, f"POST after a 500: sent once (was {len(S.hits)})")
S.reset({"/list": lambda qs, b: (503, JSON, {"error": "busy"})})
out = phpv(tryc(f"(new Align\\Http\\HttpClient(5, 2))->request('GET', '{U}/list');"))
ok("HTTP 503" in out and len(S.hits) == 2, f"a GET is still retried after a 5xx ({len(S.hits)} attempts)")

# ---- the connector form: addresses and keys
admin = login("admin@example.com", "LongPassword123!")
old_url = q("select value from settings where name='itflow_url'")[0]["value"]
for bad in ["https://169.254.169.254", "https://[fd00:ec2::254]", "https://0251.0376.0251.0376"]:
    r = admin.post(B + "/integrations/itflow", data={"_csrf": csrf(admin, "/integrations/itflow"), "itflow_url": bad})
    ok("metadata" in flash(r.text) and "Nothing was saved" in flash(r.text) and q("select value from settings where name='itflow_url'")[0]["value"] == old_url,
       f"ITFlow URL {bad} refused on save: " + flash(r.text)[:110])
for bad in ["https://user:pw@itflow.example.com", "https://itflow.example.com/?x=1", "https://itflow.example.com/#top"]:
    r = admin.post(B + "/integrations/itflow", data={"_csrf": csrf(admin, "/integrations/itflow"), "itflow_url": bad})
    ok("without a user name" in flash(r.text) and q("select value from settings where name='itflow_url'")[0]["value"] == old_url,
       f"ITFlow URL {bad} refused (only scheme, host, port and path): " + flash(r.text)[:110])

old_id = q("select value from settings where name='ninja_client_id'")[0]["value"]
old_secret = q("select value, is_secret from settings where name='ninja_client_secret'")
r = admin.post(B + "/integrations/ninjaone", data={"_csrf": csrf(admin, "/integrations/ninjaone"), "ninja_client_id": old_id + "\r\nX-Injected: 1"})
ok("line breaks" in flash(r.text) and q("select value from settings where name='ninja_client_id'")[0]["value"] == old_id, "a client ID with a line break is refused: " + flash(r.text)[:90])
r = admin.post(B + "/integrations/ninjaone", data={"_csrf": csrf(admin, "/integrations/ninjaone"), "ninja_client_id": old_id, "ninja_client_secret": "abc\r\ndef"})
ok("line breaks" in flash(r.text) and q("select value, is_secret from settings where name='ninja_client_secret'") == old_secret, "a secret with a line break is refused: " + flash(r.text)[:90])
# (put back whatever an old build saved)
q("update settings set value=%s where name='ninja_client_id'", old_id)
if old_secret:
    q("update settings set value=%s, is_secret=%s where name='ninja_client_secret'", old_secret[0]["value"], old_secret[0]["is_secret"])

# ---- Veeam: a reply that isn't a list is an error, not "no jobs" (the sync would delete every job and machine)
S.reset({"/api/v3/infrastructure/backupServers/jobs": lambda qs, b: (200, {"Content-Type": "text/html"}, "<html><body>Sign in</body></html>")})
out = phpv(tryc(f"echo 'COUNT ' . count((new Align\\Integrations\\VeeamSpc('{U}', 'k'))->serverJobs());"))
ok("other than a list" in out and "COUNT" not in out, "Veeam HTML page: the read fails instead of returning nothing: " + out[-90:])
S.reset({"/api/v3/organizations/companies": lambda qs, b: (200, JSON, {"meta": {"pagingInfo": {"total": 0}}, "data": [{"instanceUid": "u%d" % i} for i in range(500)]})})
out = phpv(tryc(f"echo 'COUNT ', count((new Align\\Integrations\\VeeamSpc('{U}', 'k'))->companies());"))
ok("same page twice" in out and len(S.hits) == 2, f"Veeam ignoring offset: stopped after {len(S.hits)} requests: " + out[-80:])
S.reset({"/api/v3/organizations/companies": lambda qs, b: (200, JSON, {"data": ["junk", 7, {"instanceUid": "c1", "name": "Example Co"}]})})
out = phpv(tryc(f"echo 'COUNT ', count((new Align\\Integrations\\VeeamSpc('{U}', 'k'))->companies());"))
ok(out == "COUNT 1", "Veeam rows that aren't records are dropped: " + out[-80:])

# ---- NinjaOne: ids must be whole numbers, a reply must be a list, the token must be text
TOKEN = {"/ws/oauth/token": lambda qs, b: (200, JSON, {"access_token": "t", "expires_in": 3600})}
S.reset({**TOKEN, "/v2/organizations": lambda qs, b: (200, JSON, [] if "after" in qs else [{"id": [1], "name": "List id"}, {"id": "7", "name": "Example Org A"}, {"id": 5, "name": "Example Org B"}, "junk", {"id": 1.5}])})
out = phpv(tryc(f"echo 'COUNT ', count((new Align\\Integrations\\NinjaOne('{U}', 'id', 'secret'))->organizations());"))
ok(out == "COUNT 2", "NinjaOne rows without a whole-number id are dropped (no TypeError): " + out[-90:])
S.reset({**TOKEN, "/v2/devices-detailed": lambda qs, b: (200, JSON, {"error": "maintenance"})})
out = phpv(tryc(f"echo 'COUNT ', count((new Align\\Integrations\\NinjaOne('{U}', 'id', 'secret'))->devicesDetailed());"))
ok("other than a list" in out, "NinjaOne non-list reply is an error, not zero devices: " + out[-90:])
S.reset({"/ws/oauth/token": lambda qs, b: (200, JSON, {"access_token": ["t"]}), "/v2/organizations": lambda qs, b: (200, JSON, [])})
out = phpv(tryc(f"(new Align\\Integrations\\NinjaOne('{U}', 'id', 'secret'))->test();"))
ok("did not return an access token" in out, "NinjaOne token that isn't text is refused cleanly: " + out[-90:])
out = phpv(tryc("echo Align\\Integrations\\NinjaOne::mapDevice(['id' => 1, 'system' => ['serialNumber' => 12345678, 'manufacturer' => ['x']], 'nodeClass' => ['y']])['serial'];"))
ok(out == "12345678", "NinjaOne serial sent as a number is kept, odd field types don't throw: " + out[-90:])

# ---- ITFlow: paging that never ends, ids that aren't numbers, messages that echo the key
S.reset({"/api/v1/clients/read.php": lambda qs, b: (200, JSON, {"success": "True", "data": [{"client_id": i, "client_name": "Example %d" % i} for i in range(100)]})})
out = phpv(tryc(f"echo 'COUNT ', count((new Align\\Integrations\\Itflow('{U}', 'KEY123'))->clients());"), timeout=90)
ok("same clients for two different pages" in out and len(S.hits) == 2, f"ITFlow ignoring offset: stopped after {len(S.hits)} requests: " + out[-90:])
S.reset({"/api/v1/clients/read.php": lambda qs, b: (200, JSON, {"success": "True", "data": ["junk", {"client_id": 1, "client_name": "Example"}]})})
out = phpv(tryc(f"echo 'COUNT ', count((new Align\\Integrations\\Itflow('{U}', 'KEY123'))->clients());"))
ok(out == "COUNT 1", "ITFlow rows that aren't records are dropped: " + out[-80:])
S.reset({"/api/v1/tickets/read.php": lambda qs, b: (200, JSON, {"success": "True", "data": [{"ticket_id": 999, "ticket_subject": "Someone else's"}]})})
out = phpv(tryc(f"echo json_encode((new Align\\Integrations\\Itflow('{U}', 'KEY123'))->ticket(5));"))
ok(out == "null", "ITFlow ticket(5) ignores a reply about another ticket: " + out[-80:])
S.reset({"/api/v1/assets/create.php": lambda qs, b: (200, JSON, {"success": "True", "data": [{"insert_id": [3]}]})})
out = phpv(tryc(f"echo 'ID ', (new Align\\Integrations\\Itflow('{U}', 'KEY123'))->createAsset(4, ['asset_name' => 'Example PC']);"))
ok("did not return its ID" in out and "ID 1" not in out, "ITFlow insert_id that isn't a number never becomes asset 1: " + out[-90:])
S.reset({"/api/v1/clients/read.php": lambda qs, b: (401, JSON, {"success": "False", "message": "Bad api_key KEY123\r\nX-Note: injected"})})
out = phpv(tryc(f"(new Align\\Integrations\\Itflow('{U}', 'KEY123'))->test();"))
ok("KEY123" not in out and "[API key]" in out and "\n" not in out, "ITFlow error text has the key taken out and is one line: " + out[-100:])

# ---- Dell: only tags that were asked about, only safe serials in the list, odd types don't throw
def dell_ent(qs, b):
    asked = (qs.get("servicetags") or [""])[0].split(",")
    rows = [{"serviceTag": t, "shipDate": "2023-02-01", "entitlements": [{"startDate": "2023-02-01", "endDate": "2026-02-01", "serviceLevelDescription": "ProSupport"}]} for t in asked if t and t != "ODDTYPE"]
    rows.append({"serviceTag": "VICTIM1", "entitlements": [{"endDate": "2099-01-01"}]})
    rows.append({"serviceTag": "ODDTYPE", "productLineDescription": ["x"], "entitlements": [{"serviceLevelDescription": {"a": 1}, "endDate": ["2030-01-01"]}]})
    return (200, JSON, rows)
S.reset({"/auth/oauth/v2/token": lambda qs, b: (200, JSON, {"access_token": "dell-token"}), "/PROD/sbil/eapi/v5/asset-entitlements": dell_ent})
out = phpv(tryc(f"$r = (new Align\\Integrations\\Warranty\\Dell('id', 'secret', '{U}'))->lookup(['ABC1234', 'AB,CD', 'ODDTYPE']); "
                "echo json_encode(array_map(fn($x) => [$x->status, $x->end, $x->description, $x->message], $r));"))
try:
    res = json.loads(out)
except Exception:
    res = {}
sent = [h["query"].get("servicetags", [""])[0] for h in S.hits if h["path"].endswith("asset-entitlements")]
ok(sorted(res) == ["AB,CD", "ABC1234", "ODDTYPE"] and "VICTIM1" not in res, "Dell: results only for the serials asked about: " + out[:160])
ok(sent and "AB,CD" not in sent[0] and "CD" not in sent[0].split(","), "Dell: a serial with a comma isn't sent (it would ask about another tag): " + str(sent))
ok(res.get("ABC1234", [None])[0] == "ok" and res.get("ODDTYPE", [None])[0] == "not_found" and res.get("ODDTYPE", [0, 0, 0])[2] is None, "Dell: odd field types are ignored, no TypeError")

# ---- Lenovo: odd field types don't throw
S.reset({"/v2.5/warranty": lambda qs, b: (200, JSON, {"Product": {"a": 1}, "Shipped": ["2024-01-01"], "Warranty": ["junk", {"Name": ["x"], "Start": "2024-01-01", "End": "2027-01-01"}]})})
out = phpv(tryc(f"$r = (new Align\\Integrations\\Warranty\\Lenovo('cid', '{U}'))->lookup(['PF0ABC12']); echo $r['PF0ABC12']->status, ' ', $r['PF0ABC12']->end, ' ', json_encode($r['PF0ABC12']->description);"))
ok(out == "ok 2027-01-01 null", "Lenovo: odd field types are ignored, the dates still read: " + out[-90:])

# ---- an integration on the same machine: allow_local_integrations allows loopback, https still required (2.2.1)
LOCAL = SECURE + "$d = $p->getValue(); $d['allow_local_integrations'] = true; $p->setValue(null, $d); "
out = phpv(SECURE + tryc("echo Align\\Http\\HttpClient::check('https://127.0.0.1:8443/');"))
ok("allow_local_integrations" in out, "loopback is refused and the message names the setting that allows it: " + out[-120:])
out = phpv(LOCAL + tryc("echo 'host ', Align\\Http\\HttpClient::check('https://127.0.0.1:8443/');"))
ok(out.endswith("host 127.0.0.1"), "with allow_local_integrations, a console on this machine can be used: " + out[-80:])
out = phpv(LOCAL + tryc("echo Align\\Http\\HttpClient::check('http://127.0.0.1:8443/');"))
ok("Only https://" in out, "...but it still has to be https: " + out[-80:])
out = phpv(LOCAL + tryc("echo Align\\Http\\HttpClient::check('https://169.254.169.254/');"))
ok("metadata" in out, "...and the metadata address stays refused: " + out[-80:])
# no_proxy: a host on it goes direct, so the connected address is checked as usual
out = phpv("putenv('https_proxy=http://proxy.example:3128'); putenv('no_proxy=.internal.example,localhost');"
           " $m = new ReflectionMethod(Align\\Http\\HttpClient::class, 'viaProxy');"
           " echo json_encode([$m->invoke(null, 'https', 'psa.internal.example'), $m->invoke(null, 'https', 'api.dell.com'), $m->invoke(null, 'https', 'localhost')]);")
ok(out == "[false,true,false]", "a host in no_proxy isn't treated as going through the proxy: " + out[-80:])

S.close()
done()

"""2.2.1 review of the core: database transactions, the audit log and its chain, the router and the URL helper."""
from lib import *
import json, subprocess

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def verify(): return subprocess.run(["php", ALIGN, "audit:verify"], env=ENV, capture_output=True, text=True)
def last_id(): return q("select coalesce(max(id), 0) m from audit_log")[0]["m"]

ok(verify().returncode == 0, "(audit chain intact before the tests)")

# ---- DB::transaction inside another one: a savepoint, not "There is already an active transaction"
n0 = last_id()
out = phpv("Align\\DB::transaction(function () { Align\\Audit::log('core.nested', 'logged inside a transaction'); }); echo 'done';")
ok(out == "done" and q("select id from audit_log where action='core.nested' and id > %s", n0), "an audit entry written inside a transaction is kept (it was silently lost, e.g. 'client created from contract'): " + out[:120])
ok(verify().returncode == 0, "...and the chain still verifies")

q("delete from settings where name in ('core_db_outer', 'core_db_inner')")
out = phpv("Align\\DB::transaction(function () { Align\\DB::run(\"INSERT INTO settings (name, value, is_secret) VALUES ('core_db_outer', '1', 0)\");"
           " try { Align\\DB::transaction(function () { Align\\DB::run(\"INSERT INTO settings (name, value, is_secret) VALUES ('core_db_inner', '1', 0)\"); throw new RuntimeException('inner'); }); }"
           " catch (RuntimeException $e) { echo 'caught'; } });")
names = {r["name"] for r in q("select name from settings where name in ('core_db_outer', 'core_db_inner')")}
ok(out == "caught" and names == {"core_db_outer"}, f"a failed inner transaction undoes only its own changes; the outer one commits ({out[:120]}, {sorted(names)})")
q("delete from settings where name in ('core_db_outer', 'core_db_inner')")

out = phpv("try { Align\\DB::transaction(function () { Align\\Audit::log('core.rolledback', 'x'); throw new RuntimeException('undo'); }); } catch (RuntimeException $e) { echo 'undone'; }")
ok(out == "undone" and not q("select id from audit_log where action='core.rolledback'") and verify().returncode == 0,
   "an audit entry inside a transaction that rolls back goes with it, and the chain still verifies (regression guard)")

# Inside a transaction the entry is written after the commit (DB::afterCommit), so the chain's head row isn't
# locked in the middle of a longer transaction (MariaDB 11.8's snapshot isolation could roll the whole thing back)
out = phpv("Align\\DB::transaction(function () { Align\\Audit::log('core.deferred', 'x');"
           " echo Align\\DB::value(\"SELECT COUNT(*) FROM audit_log WHERE action = 'core.deferred'\"); });"
           " echo '|', Align\\DB::value(\"SELECT COUNT(*) FROM audit_log WHERE action = 'core.deferred'\");")
ok(out.startswith("0|") and int(out.split("|")[1]) >= 1 and verify().returncode == 0, "inside a transaction the audit entry is written once it commits, not before: " + out[:80])

# ---- Audit::log: values always fit their columns as stored, so the entry is kept and its hash matches
n0 = last_id()
phpv("Align\\Audit::log('core.' . chr(255) . 'x', 'bad ' . chr(195)); Align\\Audit::log(str_repeat('a', 150), 'long action');")
ok(q("select id from audit_log where action='core.?x' and detail='bad ?' and id > %s", n0), "invalid UTF-8 is stored as '?' instead of losing the entry")
ok(q("select id from audit_log where action=%s and id > %s", "a" * 100, n0), "an action longer than its column is cut to 100 characters instead of losing the entry")
ok(verify().returncode == 0, "...and both entries verify")

phpv("Align\\Api\\Context::$key = ['name' => 'Core test key', 'id' => 999]; Align\\Audit::log('core.via', str_repeat('x', 6000));")
d = q("select detail from audit_log where action='core.via' order by id desc limit 1")
ok(d and d[0]["detail"].endswith(' — via API key "Core test key" (#999)') and len(d[0]["detail"]) <= 5000,
   "a change through the API names the key even when the detail is long (the name was cut off)")
ok(verify().returncode == 0, "...and it verifies")

# ---- the audit page's quick check only builds on a result verify() sealed
phpv("Align\\AuditChain::verify();")
r = json.loads(phpv("echo json_encode(Align\\AuditChain::quick());") or "{}")
ok(r.get("ok") and r.get("full") is False, "a result sealed by the full check is used (the page checks only newer entries)")
victim = q("select id, detail from audit_log where action='core.nested' order by id desc limit 1")[0]
q("update audit_log set detail=%s where id=%s", "tampered", victim["id"])
head = q("select last_id, last_hash from audit_chain")[0]
setting("audit_verified", json.dumps({"ok": True, "checked": 1, "broken_at": None, "reason": None, "head_id": head["last_id"],
                                      "head_hash": head["last_hash"], "at": "2026-01-01 00:00:00"}))
r = json.loads(phpv("echo json_encode(Align\\AuditChain::quick());") or "{}")
ok(r.get("ok") is False and r.get("broken_at") == victim["id"], f"a made-up 'checked up to the newest entry' result doesn't hide an edited entry from the audit page ({r.get('reason')})")
q("update audit_log set detail=%s where id=%s", victim["detail"], victim["id"])
ok(verify().returncode == 0, "put back: intact")

# ---- entries added while the chain is being checked are not reported as tampering
phpv("Align\\AuditChain::verify();")
writers = [subprocess.Popen(["php", "-r", PHP + "$end = microtime(true) + 4; for ($i = 0; $i < 400 && microtime(true) < $end; $i++) { Align\\Audit::log('core.race', '#' . $i); }"],
                            env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) for _ in range(3)]
out = phpv("$bad = 0; $n = 0; $end = microtime(true) + 4; while (microtime(true) < $end) { $r = Align\\AuditChain::quick(); $n++; if (!$r['ok']) { $bad++; $why = $r['reason']; } }"
           " echo $n, ' checks, ', $bad, ' failed', $bad ? ': ' . $why : '';")
[w.wait() for w in writers]
ok(out.endswith(" 0 failed") and not out.startswith("0 "), "checks running while entries are added never report tampering (they read the markers and entries from one snapshot): " + out[:160])
ok(verify().returncode == 0, "...and the log is intact afterwards")

# ---- url(): never a path a browser reads as another host
out = json.loads(phpv("echo json_encode([url(chr(92) . '/evil.example'), url(chr(9) . '//evil.example'), url('/' . chr(92) . 'evil.example'), url('//evil.example'), url('/clients/3', ['tab' => 'a b'])]);") or "[]")
ok(out == ["/evil.example", "/evil.example", "/evil.example", "/evil.example", "/clients/3?tab=a+b"],
   f"url() drops leading backslashes and control characters as well as slashes, so redirect() can't send anyone to another site: {out}")

# ---- router
r = requests.get(B + "/settings/api/openapiXjson", allow_redirects=False)
ok(r.status_code == 404, f"the text of a route is matched literally ('.' isn't any character): {r.status_code}")
r = requests.request("PUT", B + "/login", allow_redirects=False)
ok(r.status_code == 405 and r.headers.get("Allow") == "GET, POST", f"a method a page doesn't take answers 405 with Allow: {r.status_code} {r.headers.get('Allow')}")
r = requests.post(B + "/terms", allow_redirects=False)
ok(r.status_code == 405 and r.headers.get("Allow") == "GET", f"POST to a page that only shows: 405, Allow: GET ({r.headers.get('Allow')})")
r = requests.head(B + "/login", allow_redirects=False)
ok(r.status_code == 405 and r.headers.get("Allow") == "GET, POST", "HEAD isn't routed to GET handlers (they can record views)")
r = requests.get(B + "/login/", allow_redirects=False)
ok(r.status_code == 200, "a trailing slash still finds the page (regression guard)")
ok(requests.get(B + "/clients/abc", allow_redirects=False).status_code == 404, "{id} takes digits only (regression guard)")

# ---- every route checks who's asking before it does anything (regression guard for new routes)
GUARD = r"""
$r = require APP_ROOT . '/src/routes.php';
$public = ['AuthController::loginForm', 'AuthController::login', 'AuthController::twoFactorForm', 'AuthController::twoFactor', 'AuthController::logout',
  'AuthController::ping', 'LegalController::terms', 'LegalController::license', 'LegalController::licenseText', 'LegalController::thirdParty',
  'BrandingController::logo', 'BrandingController::background', 'MeetingController::feed', 'PortalController::terms', 'PortalController::loginForm',
  'PortalController::login', 'PortalController::forgotForm', 'PortalController::forgot', 'PortalController::twoFactorForm', 'PortalController::twoFactor',
  'PortalController::logout', 'PortalController::ping', 'PortalController::inviteForm', 'PortalController::invite'];
$bad = [];
$n = 0;
foreach ((new ReflectionProperty($r, 'routes'))->getValue($r) as [$m, $path, $h]) {
    if ($h instanceof Closure) { continue; }
    $n++;
    [$c, $fn] = $h;
    $key = (new ReflectionClass($c))->getShortName() . '::' . $fn;
    if (in_array($key, $public, true)) { continue; }
    $rm = new ReflectionMethod($c, $fn);
    $body = preg_replace('#//[^\n]*#', '', implode('', array_slice(file($rm->getFileName()), $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1)));
    $guard = in_array((new ReflectionClass($c))->getShortName(), ['WelcomeController', 'SignController'], true)
        ? '/self::load\(\$token\)/' : '/Auth::require(Role)?\(|PortalAuth::require\(|self::requireRequests\(/';
    $act = '/DB::|Audit::|View::|redirect\(|header\(|echo |readfile\(|self::render\(/';
    $g = preg_match($guard, $body, $gm, PREG_OFFSET_CAPTURE) ? $gm[0][1] : PHP_INT_MAX;
    $a = preg_match($act, $body, $am, PREG_OFFSET_CAPTURE) ? $am[0][1] : PHP_INT_MAX;
    if ($g === PHP_INT_MAX || $a < $g) { $bad[] = "$m $path ($key)"; }
}
echo json_encode(['routes' => $n, 'bad' => $bad]);
"""
res = json.loads(phpv(GUARD) or "{}")
ok(res.get("routes", 0) > 250 and res.get("bad") == [], f"each of {res.get('routes')} routes signs in / checks the role (or its secret link) before anything else: {res.get('bad')}")

ok(verify().returncode == 0, "audit chain intact at the end")
done()

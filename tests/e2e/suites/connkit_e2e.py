"""2.22.0 Community connector kit: `align make:connector` (in a throwaway copy of the code) makes each kind of
connector from its template (classes, mock API and test suite renamed, the Registry and run.sh edited, every PHP file
valid, nothing left that names the template), and the new connector works against its own copied mock API; it refuses
a folder that isn't a git clone, an existing file, a taken or bad key, a bad name or option, and --dry-run writes
nothing. The extension points are guarded: only connector classes under Align\\Templates are taken from the config, a
connector can't add checks outside its own prefix or reuse a built-in one, one whose checks throw doesn't break the
others, a link table that isn't a plain name is ignored, and SaasStore keeps only valid records and results."""
import os, re, json, shutil, subprocess, atexit, time, tempfile, requests
from lib import *

COPY = tempfile.mkdtemp(prefix="align-connkit-")
CFG2 = WORK + "/connkit.php"
def free_port():
    import socket
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


PORT = free_port()
MOCK2 = f"http://127.0.0.1:{PORT}"
server = None


def cleanup():
    if server:
        server.terminate()
    shutil.rmtree(COPY, ignore_errors=True)
    for k in ("acmemonitor", "acmedesk", "acmevault", "acmeshield", "zzkit"):
        q("delete from settings where name like %s", k + "\\_%")
        q("delete from connector_results where provider=%s", k)
        q("delete from connector_records where provider=%s", k)
        q("delete from client_links where provider=%s", k)
    if os.path.exists(CFG2):
        os.unlink(CFG2)


atexit.register(cleanup)

for d in ("src", "tests", "bin", "scripts"):
    shutil.copytree(f"{ROOT}/{d}", f"{COPY}/{d}", ignore=shutil.ignore_patterns("__pycache__", "*.log"))
shutil.copy(f"{ROOT}/VERSION", f"{COPY}/VERSION")


def make(*a, env=None):
    # ALIGN_CONFIG marks a development copy, as for bin/align (root is refused without it)
    return subprocess.run(["php", f"{COPY}/bin/align", "make:connector", *a], capture_output=True, text=True, env=env or {"PATH": ENV["PATH"], "ALIGN_CONFIG": CONFIG})


def read(p): return open(f"{COPY}/{p}").read()


def cphp(code, classes=()):
    """PHP in the copy with the test database and, optionally, connector templates switched on."""
    open(CFG2, "w").write(open(CONFIG).read().rstrip().removesuffix("];") + ', "dev_connectors" => [' + ", ".join(json.dumps(c) for c in classes) + "]];\n")
    r = subprocess.run(["php", "-r", f'require "{COPY}/src/bootstrap.php"; ' + code], capture_output=True, text=True, env={**ENV, "ALIGN_CONFIG": CFG2})
    out = r.stdout.strip()
    try:
        return json.loads(out)
    except ValueError:
        return out + r.stderr


# ---- refusals first (nothing may be written)
reg0, run0 = read("src/Integrations/Registry.php"), read("tests/e2e/run.sh")
r = make("rmm", "Acme Monitor")
ok(r.returncode == 1 and "git clone" in r.stderr and not os.path.exists(f"{COPY}/src/Providers/Rmm/AcmeMonitor.php"), "a folder that isn't a git clone (an install) is refused")
os.makedirs(f"{COPY}/.git")
open(f"{COPY}/config.php", "w").write("<?php return [];")
r = make("rmm", "Acme Monitor")
ok(r.returncode == 1 and "git clone" in r.stderr, "a folder with a config.php (an install) is refused")
os.unlink(f"{COPY}/config.php")
if os.geteuid() == 0:
    r = make("rmm", "Acme Monitor", env={"PATH": ENV["PATH"]})
    ok(r.returncode == 1 and "not root" in r.stderr, "root is refused unless ALIGN_CONFIG is set")
for args, why in [(["rmm", "Tactical RMM"], "already used"), (["rmm", "Acme", "--key=ninjaone"], "already used"), (["saas", "Acme", "--key=huntress"], "already used"),
                  (["rmm", "Acme", "--key=Bad-Key"], "lowercase letters"), (["rmm", "Ac'me"], "product name must"), (["rmm", "<b>x</b>"], "product name must"),
                  (["rmm", "Acme", "--force"], "doesn't take"), (["cloud", "Acme"], "rmm, psa, backup or saas"), (["rmm", "Fabrikam Two"], "made-up product"),
                  (["rmm", "List"], "wouldn't be valid PHP"), (["rmm", "Acme", "--key=graph"], "already used"), (["rmm", "Acme", "--key=trmm"], "already used"),
                  (["rmm", "Acme", "--key=mock"], "already used"), (["backup", "Veeam Plus"], "confused with"), (["rmm", "Tactical"], "confused with"),
                  (["saas", "Huntress Shield"], "confused with")]:
    r = make(*args)
    ok(r.returncode == 1 and why in r.stderr, f"refused: {' '.join(args)} ({r.stderr.strip()[:90]})")
r = make("rmm", "Acme Monitor", "--dry-run")
ok(r.returncode == 0 and "src/Providers/Rmm/AcmeMonitor.php" in r.stdout and "Nothing written" in r.stdout and not os.path.exists(f"{COPY}/src/Providers/Rmm/AcmeMonitor.php"),
   "--dry-run lists the files and writes nothing")
ok(read("src/Integrations/Registry.php") == reg0 and read("tests/e2e/run.sh") == run0, "refusals and the dry run left the Registry and run.sh alone")

# ---- each kind
KINDS = [("rmm", "Acme Monitor", "AcmeMonitor", "acmemonitor", ["src/Providers/Rmm/AcmeMonitor.php", "src/Integrations/Connectors/AcmeMonitor.php"]),
         ("psa", "Acme Desk", "AcmeDesk", "acmedesk", ["src/Providers/Psa/AcmeDesk.php", "src/Integrations/Connectors/AcmeDesk.php"]),
         ("backup", "Acme Vault", "AcmeVault", "acmevault", ["src/Providers/Backup/AcmeVault.php", "src/Integrations/Connectors/AcmeVault.php"]),
         ("saas", "Acme Shield", "AcmeShield", "acmeshield", ["src/AcmeShield/Api.php", "src/Integrations/Connectors/AcmeShield.php"])]
for kind, name, cls, key, php_files in KINDS:
    r = make(kind, name)
    files = php_files + [f"tests/mock/{key}.php", f"tests/e2e/suites/{key}_e2e.py"]
    ok(r.returncode == 0 and all(os.path.exists(f"{COPY}/{f}") for f in files), f"{kind}: makes {', '.join(files)}: {(r.stdout + r.stderr)[-160:]}")
    lint = [subprocess.run(["php", "-l", f"{COPY}/{f}"], capture_output=True, text=True).returncode for f in php_files + [f"tests/mock/{key}.php"]]
    ok(lint == [0] * len(lint), f"{kind}: every PHP file is valid")
    ok(not any(re.search("fabrikam|template-only|Align\\\\Templates", read(f), re.I) for f in files), f"{kind}: nothing names the template any more")
    conn = read(php_files[1])
    ok(f"namespace Align\\Integrations\\Connectors;" in conn and f"final class {cls} extends" in conn and f"return '{key}';" in conn and f"return '{name}';" in conn
       and f"'{key}_api_key'" in conn, f"{kind}: the connector has the new namespace, class, key, name and setting names")
    ok(f"Connectors\\{cls}::class, // community connector (beta)" in read("src/Integrations/Registry.php"), f"{kind}: it's added to the Registry")
    suite = read(f"tests/e2e/suites/{key}_e2e.py")
    ok(f'KEY = "{key}"' in suite and "dev_connectors" not in suite and 'f"/mock/{KEY}-set"' in suite, f"{kind}: the suite tests the new key, without the template-only lines")
    ok(f"str_starts_with($path, '/{key}/')" in read(f"tests/mock/{key}.php"), f"{kind}: the mock answers the new key's paths")
run = read("tests/e2e/run.sh")
ok(all(run.count(f" {k[3]}_e2e ") == 2 for k in KINDS), "the suites are added to run.sh (the list and the last group)")
g = subprocess.run(["bash", f"{COPY}/tests/e2e/run.sh", "--check-groups"], capture_output=True, text=True)
ok(g.returncode == 0, "run.sh's groups still match its suite list: " + (g.stdout + g.stderr).strip()[:120])
r = make("rmm", "Acme Monitor")
ok(r.returncode == 1 and "already" in r.stderr, "running it again is refused (the files and key exist)")

# ---- the new connectors work against their copied mock APIs
server = subprocess.Popen(["php", "-S", f"127.0.0.1:{PORT}", f"{COPY}/tests/mock-server.php"], cwd=COPY, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
for _ in range(50):
    try:
        requests.get(MOCK2 + "/", timeout=1)
        break
    except requests.RequestException:
        time.sleep(0.1)
ok(server.poll() is None, f"the copy's mock server runs on port {PORT}")
for _, _, _, key, _ in KINDS:
    setting(key + "_url", f"{MOCK2}/{key}")
    setting(key + "_api_key", f"{key}-key", True)
got = cphp('$r = []; foreach (["acmemonitor", "acmedesk", "acmevault", "acmeshield"] as $k) { $c = Align\\Integrations\\Registry::get($k); '
           '$r[$k] = $c ? [get_class($c), $c->configured(), $c->test()] : null; } echo json_encode($r);')
ok(isinstance(got, dict) and got["acmemonitor"] == ["Align\\Integrations\\Connectors\\AcmeMonitor", True, "Connected. 2 organizations readable."],
   "the RMM connector is registered and reads its mock: %s" % (got.get("acmemonitor") if isinstance(got, dict) else got))
ok(isinstance(got, dict) and got["acmedesk"][2] == "Connected. 2 clients readable." and got["acmevault"][2] == "Connected. 2 customers readable."
   and got["acmeshield"][2] == "Connected. 3 tenants readable.", "so do the PSA, backup and security ones: %s" % str(got)[:300])
dev = cphp('echo json_encode(array_column(Align\\Integrations\\Registry::get("acmemonitor")->provider()->devices(), "device_type", "id"));')
ok(dev == {"dev-srv-01": "Server", "dev-lt-02": "Laptop", "dev-vm-03": "Virtual server", "dev-stray-04": "Desktop"}, "the RMM's devices come through its mapping: %s" % dev)
lab = cphp("echo json_encode(array_keys(Align\\Health\\SecurityChecks::labels()));")
ok(isinstance(lab, list) and {"acmeshield_mfa", "acmeshield_devices", "acmeshield_scan"} <= set(lab), "the security connector's checks join Align's, under its own prefix")
ok(cphp('echo json_encode(Align\\Compliance\\Evidence::systems()["acmeshield_"] ?? null);') == "Acme Shield", "and the evidence check lists it")

# ---- guards on the extension points (test classes in the copy only)
os.makedirs(f"{COPY}/src/Templates/Test", exist_ok=True)
GUARD = '''<?php
declare(strict_types=1);
namespace Align\\Templates\\Test;
/** Test connector for connkit_e2e: %s */
final class %s extends \\Align\\Integrations\\Connector implements \\Align\\Integrations\\ProvidesChecks, \\Align\\Integrations\\HasLinkTable
{
    /** Key. */ public function key(): string { return '%s'; }
    /** Name. */ public function name(): string { return '%s'; }
    /** Icon. */ public function icon(): string { return 'fas fa-flask'; }
    /** Group. */ public function category(): string { return 'Security'; }
    /** Summary. */ public function summary(): string { return 'Test'; }
    /** Always set up. */ public function configured(): bool { return true; }
    /** Setup. */ public function setup(): string { return ''; }
    /** Prefix. */ public function checkPrefix(): string { return '%s'; }
    /** System. */ public function checkSystem(): string { return 'Test system'; }
    /** Labels. */ public function checkLabels(): array { return %s; }
    /** Indicators. */ public function checkIndicators(int $clientId): array { %s }
    /** Results. */ public function checkResults(array $clientIds): array { return []; }
    /** Link table. */ public function linkTable(): array { return %s; }
}
'''
for cls, key, prefix, labels, ind, table in [
    ("Taken", "zztaken", "huntress_", "['huntress_agents' => 'Replaced!', 'huntress_x' => 'Added']", "return [];", "['connector_records', 'record_id']"),
    ("Outside", "zzout", "zzout_", "['zzout_ok' => 'Its own', 'm365_mfa_users' => 'Hijacked', 'zzout_' . str_repeat('x', 40) => 'Too long']", "return ['zzout_ok' => ['label' => 'Its own', 'ok' => true, 'unknown' => false, 'text' => 'fine', 'suggest' => 'met'], 'email_spf' => ['label' => 'x', 'ok' => true, 'unknown' => false, 'text' => 'hijack', 'suggest' => 'met']];", "['connector_records', 'record_id']"),
    ("Throws", "zzthrow", "zzthrow_", "['zzthrow_a' => 'Throws']", "throw new \\RuntimeException('boom');", "['bad table; drop', 'record_id']"),
    ("Squat", "zzsquat", "zzout_", "['zzout_more' => 'Squatted']", "return ['zzout_ok' => ['label' => 'x', 'ok' => false, 'unknown' => false, 'text' => 'squat', 'suggest' => 'not_met']];", "['connector_records', 'record_id']"),
    ("Odd", "zzodd", "zzodd_", "['zzodd_a' => 'Odd shapes', 'zzodd_b' => 'Odd b']", "return ['zzodd_a' => 'not an array', 'zzodd_b' => ['ok' => 'yes', 'text' => ['x']], 'zzodd_c' => ['ok' => true]];", "['users', 'id']")]:
    open(f"{COPY}/src/Templates/Test/{cls}.php", "w").write(GUARD % (cls, cls, key, cls, prefix, labels, ind, table))
T = ["Align\\Templates\\Test\\Taken", "Align\\Templates\\Test\\Outside", "Align\\Templates\\Test\\Throws", "Align\\Templates\\Test\\Squat", "Align\\Templates\\Test\\Odd", "Align\\DB", "Align\\Templates\\Rmm\\FabrikamRmm",
     "Align\\Templates\\Nope", "Align\\Integrations\\Connectors\\Veeam"]
keys = cphp("echo json_encode(array_keys(Align\\Integrations\\Registry::all()));", T)
ok(isinstance(keys, list) and {"zztaken", "zzout", "zzthrow"} <= set(keys) and keys.count("veeam") == 1 and len(keys) == len(set(keys)),
   "only connector classes under Align\\Templates are taken from the config, and a key isn't listed twice")
lab = cphp("echo json_encode(Align\\Health\\SecurityChecks::labels());", T)
ok(isinstance(lab, dict) and lab.get("huntress_agents") != "Replaced!" and "huntress_x" not in lab, "a connector using a built-in prefix adds and replaces nothing")
ok(isinstance(lab, dict) and lab.get("zzout_ok") == "Its own" and lab.get("m365_mfa_users") != "Hijacked" and not any(k.startswith("zzout_x") for k in lab),
   "a connector's checks outside its prefix (or over 40 characters) are ignored")
C1 = q("select id from clients where name='Cedar Ridge Family Dental, Inc.'")[0]["id"]
ind = cphp(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));", T)
ok(isinstance(ind, dict) and ind.get("zzout_ok", {}).get("text") == "fine" and ind.get("email_spf", {}).get("text") != "hijack" and "zzthrow_a" not in ind and "email_spf" in ind,
   "indicators: its own come through, a hijacked key doesn't, and one connector throwing doesn't stop the rest")
ok(lab.get("zzout_more") is None and ind.get("zzout_ok", {}).get("text") == "fine", "a connector using another's key as its prefix adds and replaces nothing")
ok("zzodd_a" not in ind and ind.get("zzodd_b") == {"label": "Odd b", "ok": False, "unknown": False, "text": "", "suggest": "not_met"} and "zzodd_c" not in ind,
   "indicators of the wrong shape are dropped or made safe ('yes' isn't a pass); unlabelled keys are dropped: %s" % ind.get("zzodd_b"))
ok(cphp('echo json_encode(Align\\Providers\\ClientLinks::autoMatch("zzthrow"));', T) == 0, "a link table that isn't a plain table name is ignored (no query)")
ok(cphp('echo json_encode(Align\\Providers\\ClientLinks::autoMatch("zzodd"));', T) == 0, "a table without provider and name columns is ignored")
ok("zztaken_" not in cphp("echo json_encode(Align\\Compliance\\Evidence::systems());", T) and "huntress_" in cphp("echo json_encode(Align\\Compliance\\Evidence::systems());", T),
   "the evidence check lists only connectors with a valid prefix of their own")

# ---- SaasStore keeps only valid records and results
st_ = cphp('$n = Align\\Integrations\\SaasStore::replaceRecords("zzkit", [["id" => "a-1", "name" => "One", "data" => ["x" => 1]], ["id" => "", "name" => "No id"], '
           '["id" => str_repeat("y", 65), "name" => "Long"], ["id" => "a-2", "name" => "  "], ["id" => "a\\n3", "name" => "Control"]]); '
           '$m = Align\\Integrations\\SaasStore::replaceResults("zzkit", "zzkit_", [["record_id" => "a-1", "check_key" => "zzkit_ok", "status" => "pass", "detail" => "fine"], '
           '["record_id" => "a-1", "check_key" => "zzkit_two", "status" => "unknown"], ["record_id" => "a-1", "check_key" => "zzkit_three", "status" => "fail"], '
           '["record_id" => "a-1", "check_key" => "m365_mfa_users", "status" => "pass"], ["record_id" => "a-1", "check_key" => "zzkit_bad", "status" => "great"], '
           '["record_id" => "nope", "check_key" => "zzkit_ok", "status" => "fail"]]); '
           'try { Align\\Integrations\\SaasStore::replaceResults("zzkit", "zzkit_", [["record_id" => "a-1", "check_key" => "zzkit_ok", "status" => "pass"], '
           '["record_id" => "a-1", "check_key" => "zzkit_Up", "status" => "fail"], ["record_id" => "x", "check_key" => "zzkit_ok", "status" => "fail"], ["record_id" => "a-1", "check_key" => "zzkit_x", "status" => "?"]]); $g = "partly wiped"; } '
           'catch (RuntimeException $x) { $g = $x->getMessage(); } '
           'try { Align\\Integrations\\SaasStore::replaceRecords("zzkit", []); $e = "kept"; } catch (RuntimeException $x) { $e = $x->getMessage(); } '
           'try { Align\\Integrations\\SaasStore::replaceResults("zzkit", "zzkit_", [["record_id" => "a-1", "check_key" => "Zzkit_ok", "status" => "pass"]]); $f = "wiped"; } catch (RuntimeException $x) { $f = $x->getMessage(); } '
           'echo json_encode([$n, $m, $e, Align\\Integrations\\SaasStore::records("zzkit"), $f, Align\\DB::value("SELECT COUNT(*) FROM connector_results WHERE provider = \'zzkit\'"), $g]);')
ok(isinstance(st_, list) and st_[0] == 1 and st_[1] == 3 and "Nothing was changed" in st_[2] and [r["id"] for r in st_[3]] == ["a-1"] and st_[3][0]["data"] == {"x": 1},
   "SaasStore keeps only valid records and results, and refuses an empty list over stored records: %s" % (st_ if not isinstance(st_, list) else st_[:3]))
ok(isinstance(st_, list) and "Nothing was changed" in st_[4] and "Only 1 of 4" in st_[6] and int(st_[5]) == 3,
   "and refuses results that are all, or mostly, unusable instead of wiping the stored ones: %s" % (st_[4:] if isinstance(st_, list) else ""))
done()

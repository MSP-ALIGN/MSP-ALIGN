"""2.2.1 review (sync jobs, backup monitoring, service levels): locks and interrupted runs, error text from sync steps,
untrusted PSA / backup / ticket data, the two-way asset sync (a save racing a poll, an asset missing from one read,
an asset moving client), backup re-sorting one at a time, and the SLA comparison period."""
from lib import *
import os, json, subprocess, time, datetime as dt

ADMIN = login("admin@example.com", "LongPassword123!")
TECH = login("tech@example.com", TECH_PASSWORD)
VIEWER = login("viewer@example.com", "ViewerPassword123!")
LOG = "/tmp/itflow-updates.log"
SECRET = "zzq-secret-detail"

# A provider that answers like the real one except for the methods given (each gets the real provider last)
FAKE = r'''
function zzq_fake_of(string $iface, object $inner, array $over): object {
    $code = 'return new class($inner, $over) implements \\' . $iface . ' { public function __construct(private $i, private array $o) {}';
    foreach ((new ReflectionClass($iface))->getMethods() as $m) {
        $ps = []; $args = [];
        foreach ($m->getParameters() as $p) {
            $ps[] = ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName() . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
            $args[] = '$' . $p->getName();
        }
        $n = $m->getName(); $a = implode(', ', $args); $rt = $m->getReturnType();
        $code .= " public function $n(" . implode(', ', $ps) . ')' . ($rt ? ': ' . $rt : '')
            . " { return isset(\$this->o['$n']) ? (\$this->o['$n'])(" . ($a !== '' ? "$a, " : '') . "\$this->i) : \$this->i->$n($a); }";
    }
    return eval($code . ' };');
}
function zzq_fake(array $over): Align\Providers\Psa\PsaProvider {
    return zzq_fake_of(Align\Providers\Psa\PsaProvider::class, Align\Providers\Providers::psa(), $over);
}
'''


def run_php(code):
    return php(FAKE + code)


def poll():
    return subprocess.run(["php", ALIGN, "psa:poll"], env=ENV, capture_output=True, text=True)


def mock_edit(asset_id, fields, at="2026-01-01 00:00:00"):
    requests.post(M + "/mock/itflow-edit", json={"asset_id": asset_id, "fields": fields, "at": at})


def log_size():
    return os.path.getsize(LOG) if os.path.exists(LOG) else 0


def log_since(n):
    if not os.path.exists(LOG):
        return ""
    with open(LOG) as f:
        f.seek(n)
        return f.read()


def dev(asset_id):
    r = q("select * from devices where psa_asset_id=%s and source='psa'", str(asset_id))
    return r[0] if r else None


def last_audit_id():
    return q("select coalesce(max(id),0) m from audit_log")[0]["m"]


_sq_names = ("psa_two_way", "psa_create_assets", "psa_sla_sync")
_sq_saved = q("select * from settings where name in %s", _sq_names)
def _sq_restore():
    q("delete from settings where name in %s", _sq_names)
    for r in _sq_saved:
        q("insert into settings (" + ",".join(r) + ") values (" + ",".join(["%s"] * len(r)) + ")", *r.values())
import atexit; atexit.register(_sq_restore)
setting("psa_two_way", "1"); setting("psa_create_assets", "1")
poll(); poll()  # settle whatever earlier suites left

# ---- sync runs: one at a time, interrupted runs, the page
q("insert into sync_runs (started_at, status, triggered_by) values (now() - interval 3 hour, 'running', 'schedule')")
stuck = q("select max(id) id from sync_runs")[0]["id"]
t = ADMIN.get(B + f"/sync/{stuck}").text
ok("· interrupted</small>" in t and not errs(t), "a run cut off mid-way shows as interrupted on its own page, not as running")
q("select get_lock('mountaineer_align_sync', 0)")
before = q("select count(*) n from sync_runs")[0]["n"]
r = subprocess.run(["php", ALIGN, "sync", "--quiet"], env=ENV, capture_output=True, text=True)
ok(r.returncode != 0 and "already running" in r.stderr and q("select count(*) n from sync_runs")[0]["n"] == before,
   "a second sync while one holds the lock stops at once and records nothing")
r = TECH.post(B + "/sync", data={"_csrf": csrf(TECH, "/sync")})
ok("A sync is already running." in flash(r.text), "\"Run sync now\" is refused while a sync runs")
q("select release_lock('mountaineer_align_sync')")
a0 = last_audit_id()
r = VIEWER.post(B + "/sync", data={"_csrf": csrf(VIEWER, "/sync")})
ok(r.status_code == 403 and not q("select id from audit_log where id>%s and action='sync.manual'", a0), "a viewer can't start a sync")
align("sync", "--quiet")
row = q("select status, log from sync_runs where id=%s", stuck)[0]
ok(row["status"] == "failed" and "[interrupted]" in (row["log"] or ""), "the next sync marks a run that was cut off as failed, with a note in its log")
ok(q("select status from sync_runs order by id desc limit 1")[0]["status"] in ("success", "partial"), "...and records its own run as usual")
q("insert into sync_runs (started_at, finished_at, status, triggered_by, summary, log) values (now(), now(), 'success', 'cli', %s, %s)",
  json.dumps({"<script>zzq</script>": "<img src=x onerror=zzq()>"}), "<b>zzq-log</b>")
esc = q("select max(id) id from sync_runs")[0]["id"]
t = ADMIN.get(B + f"/sync/{esc}").text
ok("<script>zzq" not in t and "<img src=x" not in t and "<b>zzq-log" not in t and "&lt;script&gt;zzq" in t, "step names, results and the log are escaped on the run page")
q("delete from sync_runs where id=%s", esc)

# ---- error text from sync steps never carries database errors
pdo = f"throw new PDOException('SQLSTATE[42S02]: {SECRET} table missing')"
r = run_php("$m = []; Align\\Sync\\PsaAssetSync::run(zzq_fake(['locations' => fn($i) => " + pdo + "]), function ($s) use (&$m) { $m[] = $s; }); echo json_encode($m);")
ok(SECRET not in r.stdout and "internal error" in r.stdout, "a database error reading locations shows in the sync log as an internal error: " + r.stdout[:160])
r = run_php("try { Align\\Sync\\PsaAssetSync::run(zzq_fake(['assets' => fn($i) => " + pdo + "])); } catch (Throwable $e) { echo 'threw'; }")
lr = q("select last_result from psa_poll_state where id=1")[0]["last_result"] or ""
fa = q("select detail from audit_log where action='sync.psa_poll_failed' order by id desc limit 1")
ok("threw" in r.stdout and SECRET not in lr and "internal error" in lr, "a failed poll stores safe text as its last result: " + lr[:120])
ok(fa and SECRET not in fa[0]["detail"], "...and audits it without the database error")
poll()
ok(not (q("select last_result from psa_poll_state where id=1")[0]["last_result"] or "").startswith("ERROR"), "the next good poll clears it")
r = run_php("$s = new Align\\Sync\\SyncRunner('cli'); echo (new ReflectionMethod($s, 'syncPsaClients'))->invoke($s, zzq_fake(['contacts' => fn($i) => " + pdo + "]));")
ok(SECRET not in r.stdout and "internal error" in r.stdout, "contact details not updated: the step result says internal error: " + r.stdout[-120:])
sw = dev(9001)
q("update devices set display_name='SW-Core-zzq', updated_at=now() where id=%s", sw["id"])
q("insert into psa_sync_state (device_id, field, base_value, align_changed_at, pending) values (%s,'name',%s,now(),1) on duplicate key update base_value=values(base_value), align_changed_at=now(), pending=1",
  sw["id"], sw["display_name"])
r = run_php(f"$d = Align\\Sync\\PsaAssetSync::loadDevice({sw['id']}); $a = Align\\DB::one('SELECT * FROM psa_assets WHERE psa_asset_id = ?', ['9001']);"
            " echo json_encode(Align\\Sync\\PsaAssetSync::reconcileDevice($d, $a, zzq_fake(['updateAsset' => fn($c, $x, $f, $i) => " + pdo + "])));")
st = q("select last_error, pending from psa_sync_state where device_id=%s and field='name'", sw["id"])
ok(SECRET not in r.stdout and st and st[0]["pending"] == 1 and SECRET not in (st[0]["last_error"] or "") and "internal error" in (st[0]["last_error"] or ""),
   "a push that fails with a database error is queued with safe text (shown on the device page): " + r.stdout[:140])
q("update devices set display_name=%s where id=%s", sw["display_name"], sw["id"])
q("update psa_sync_state set pending=0, last_error=null, align_changed_at=null, base_value=%s where device_id=%s and field='name'", sw["display_name"], sw["id"])

# ---- PSA data is untrusted: sizes, types, control characters, dates
r = run_php("$r = (new ReflectionMethod(Align\\Sync\\PsaAssetSync::class, 'cacheRow'))->invoke(null, ['id' => '77', 'client_id' => '1', 'name' => str_repeat('N', 300) . \"\\x07\","
            " 'type' => ['not', 'text'], 'serial' => 12345, 'updated_at' => 'not a date', 'purchase_date' => '2026-02-30', 'warranty_expire' => '2027-01-15', 'status' => \"Deployed\\r\\n\","
            " 'location_id' => '11', 'client_id_x' => 1], date('Y-m-d H:i:s'), ['11' => \"Closet\\x00A\"]); echo json_encode($r);")
c = json.loads(r.stdout or "{}")
ok(c.get("name") == "N" * 255 and c.get("type") is None and c.get("serial") == "12345" and c.get("updated_at") is None and c.get("purchase_date") is None
   and c.get("warranty_expire") == "2027-01-15" and c.get("status") == "Deployed" and c.get("location_name") == "Closet A",
   "an asset record is cut to its columns, loses control characters, and keeps only real dates: " + r.stdout[:200] + r.stderr[:200])
r = run_php("echo json_encode((new ReflectionMethod(Align\\Sync\\PsaAssetSync::class, 'cacheRow'))->invoke(null, ['id' => str_repeat('9', 70), 'client_id' => str_repeat('1', 70)], 'x'));")
c = json.loads(r.stdout or "{}")
ok(c.get("psa_asset_id") == "" and c.get("psa_client_id") == "", "ids too long for their column are dropped, never cut (a cut client id could be another client's)")
fw = dev(9000)
long_os = "FortiOS " + "x" * 242
mock_edit(9000, {"asset_os": long_os})
r = poll()
fw2 = dev(9000)
ok(r.returncode == 0 and "assets read" in r.stdout and fw2["firmware"] == long_os[:190],
   "an OS longer than the firmware column no longer stops every poll; it is cut to 190: " + (r.stdout + r.stderr)[-160:])
n = log_size(); poll()
ok("9000" not in log_since(n), "...and the cut value isn't pushed back to the PSA as an Align edit")
mock_edit(9000, {"asset_os": "FortiOS 7.2.8"}); poll()
ok(dev(9000)["firmware"] == "FortiOS 7.2.8", "restored")
name1 = q("select name from clients where id=1")[0]["name"]
r = run_php("$s = new Align\\Sync\\SyncRunner('cli'); echo (new ReflectionMethod($s, 'syncPsaClients'))->invoke($s, zzq_fake(['clients' => fn($i) => array_map(fn($c) => $c['id'] === '1' ? ['name' => \"Zz\\x07\\r\\nInjected: x\" . str_repeat('Z', 300)] + $c : $c, $i->clients())]));")
nm = q("select name from clients where id=1")[0]["name"]
ok(len(nm) == 255 and "\r" not in nm and "\n" not in nm and "\x07" not in nm and nm.startswith("Zz"), "a PSA client name is cut to its column and loses control characters (CR/LF too): " + (r.stdout + r.stderr)[-160:])
run_php("$s = new Align\\Sync\\SyncRunner('cli'); (new ReflectionMethod($s, 'syncPsaClients'))->invoke($s, Align\\Providers\\Providers::psa());")
ok(q("select name from clients where id=1")[0]["name"] == name1, "restored: " + name1)
RMM = "$k = array_key_first(Align\\Providers\\Providers::rmmConfigured()); $inner = Align\\Providers\\Providers::rmm($k); $s = new Align\\Sync\\SyncRunner('cli'); $m = new ReflectionMethod($s, 'syncRmmDevices');"
r = run_php(RMM + " $all = $inner->devices(); $first = $all[0]['id']; $all[0]['display_name'] = 'Zz' . str_repeat('D', 300); $all[0]['last_contact'] = 'not a date'; $all[0]['os_build'] = str_repeat('9', 80);"
            " try { echo $m->invoke($s, zzq_fake_of(Align\\Providers\\Rmm\\RmmProvider::class, $inner, ['devices' => fn($i) => $all])); } catch (Throwable $e) { echo 'threw'; } echo '|', $k, '|', $first;")
parts = r.stdout.split("|")
rd = q("select display_name, last_contact, os_build from devices where rmm_provider=%s and rmm_device_id=%s", parts[1], parts[2]) if len(parts) == 3 else []
ok(rd and len(rd[0]["display_name"]) == 255 and rd[0]["display_name"].startswith("Zz") and rd[0]["last_contact"] is None and rd[0]["os_build"] == "9" * 60,
   "an RMM device with a name too long, an odd date or a long build is stored cut down instead of failing every device: " + (r.stdout + r.stderr)[:160])
run_php(RMM + " $m->invoke($s, $inner);")
ok(len(parts) == 3 and not q("select 1 from devices where rmm_provider=%s and rmm_device_id=%s and display_name like 'ZzDDD%%'", parts[1], parts[2]), "restored")
q("delete from psa_tickets where id like 'zzq-%%'")
r = run_php("$seen = []; $rows = [['id' => 'zzq-t1', 'client_id' => '1', 'created_at' => 'garbage', 'subject' => 'Bad date'],"
            " ['id' => 'zzq-t2', 'client_id' => '1', 'created_at' => date('Y-m-d H:i:s'), 'subject' => \"Line\\x00one\\nzzq\" . str_repeat('s', 600), 'first_response_at' => 'nope',"
            " 'response_stage' => 999, 'status_id' => '99999999999', 'number' => '0', 'priority' => ['High']], ['id' => str_repeat('7', 70), 'client_id' => '1', 'created_at' => date('Y-m-d H:i:s')]];"
            " (new ReflectionMethod(Align\\Service\\Sla::class, 'store'))->invokeArgs(null, [$rows, ['1' => 1], date('Y-m-d H:i:s', strtotime('-36 months')), date('Y-m-d H:i:s'), &$seen]); echo json_encode(array_keys($seen));")
t2 = q("select * from psa_tickets where id='zzq-t2'")
ok(t2 and len(t2[0]["subject"]) == 500 and "\x00" not in t2[0]["subject"] and "\n" not in t2[0]["subject"] and t2[0]["first_response_at"] is None
   and t2[0]["response_stage"] == 127 and t2[0]["status_id"] == 2147483647 and t2[0]["number"] == "0" and t2[0]["priority"] is None and t2[0]["client_id"] == 1,
   "a ticket with odd values is stored cleaned up instead of failing the whole page: " + (r.stdout + r.stderr)[:200])
ok(not q("select 1 from psa_tickets where id='zzq-t1'") and not q("select 1 from psa_tickets where length(id) > 64"), "a ticket with no real created date, or an id too long, is skipped")
q("delete from psa_tickets where id like 'zzq-%%'")

# ---- two-way sync: a save that waited for a poll uses what the poll did
q("delete from devices where display_name='ZZQ-RACE-PC'")
q("insert into devices (source, client_id, display_name, system_name, device_type, device_class, psa_sync, created_at) values ('manual', 1, 'ZZQ-RACE-PC', 'ZZQ-RACE-PC', 'Desktop', 'desktop', 1, now())")
race = q("select id from devices where display_name='ZZQ-RACE-PC'")[0]["id"]
q("select get_lock('mountaineer_align_itflow', 0)")
n = log_size()
p = subprocess.Popen(["php", "-r", f'require "{BOOTSTRAP}"; echo json_encode(Align\\Sync\\PsaAssetSync::pushDevice({race}));'], env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
time.sleep(2)
q("update devices set psa_asset_id='zzq-made-by-poll' where id=%s", race)  # what a poll holding the lock did meanwhile
q("select release_lock('mountaineer_align_itflow')")
out, err = p.communicate(timeout=60)
res = json.loads(out or "{}")
ok(res.get("status") != "ok" and "Created" not in res.get("message", "") and q("select psa_asset_id from devices where id=%s", race)[0]["psa_asset_id"] == "zzq-made-by-poll"
   and '"create"' not in log_since(n), "a save that waited for the poll reads the device again: no second asset is created in the PSA: " + (out + err)[:160])
q("delete from devices where id=%s", race)

# ---- an asset missing from one read and then back: the PSA's status wins, nothing is pushed
sw = dev(9001)
r = run_php("echo Align\\Sync\\PsaAssetSync::run(zzq_fake(['assets' => fn($i) => array_values(array_filter($i->assets(), fn($a) => $a['id'] !== '9001'))]));")
ok(dev(9001)["retired_at"] is not None, "an asset the PSA no longer returns retires its device: " + r.stdout[-120:])
n = log_size()
r = poll()
d = dev(9001)
ok(d["retired_at"] is None and d["removed_at"] is None and "9001" not in log_since(n),
   "when the asset is back, the device comes back too, and Align never pushes \"Retired\" to it: " + log_since(n)[:160])

# ---- an asset moving to another client: the device follows, and the poll is audited
poll()
a0 = last_audit_id()
mock_edit(9006, {"asset_client_id": 1}); poll()
moved = dev(9006)["client_id"]
au = q("select detail from audit_log where id>%s and action='sync.psa_poll'", a0)
ok(moved == 1 and au, "a device imported from the PSA follows its asset to another client, and the poll that moved it is in the audit log: " + str(au[:1]))
mock_edit(9006, {"asset_client_id": 2}); poll()
ok(dev(9006)["client_id"] == 2, "moved back")

# ---- backups: re-sorting into clients one at a time
q("delete from backup_jobs where uid='zzq-job-long'"); q("delete from backup_job_clients where job_uid='zzq-job-long'")
co = q("select l.external_id from client_links l join backup_companies b on b.provider=l.provider and b.uid=l.external_id where l.client_id=1 limit 1")
if co:
    q("insert into backup_jobs (uid, provider, company_uid, source, name, status, is_enabled, last_run, duration_sec, synced_at) values ('zzq-job-long','veeam',%s,'server','ZZQ long job','success',1,now(),93600,now())", co[0]["external_id"])
    q("insert into backup_job_clients (job_uid, client_id, how) values ('zzq-job-long', 1, 'company')")
    t = TECH.get(B + "/clients/1/backups").text
    ok("26h 00m" in t and not errs(t), "a job that ran over a day shows 26h 00m, not 2h 00m")
    q("delete from backup_jobs where uid='zzq-job-long'"); q("delete from backup_job_clients where job_uid='zzq-job-long'")
else:
    ok(False, "client 1 is linked to a backup company (test data)")
q("select get_lock('msp_align_backup_assign', 0)")
p = subprocess.Popen(["php", "-r", f'require "{BOOTSTRAP}"; Align\\Sync\\BackupSync::assign(); echo "sorted";'], env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
time.sleep(2)
waiting = p.poll() is None
q("select release_lock('msp_align_backup_assign')")
out, err = p.communicate(timeout=60)
ok(waiting and "sorted" in out, "re-sorting backups into clients waits while another re-sort runs (a manual assignment can't be overwritten by a stale one): " + (out + err)[:120])
w2 = q("select uid from backup_workloads where client_id=2 limit 1")
if w2:
    r = TECH.post(B + "/clients/1/backups/exempt", data={"_csrf": csrf(TECH, "/clients/1/backups"), "action": "add", "kind": "workload", "ref": w2[0]["uid"], "reason": "zzq"})
    ok(r.status_code == 404 and not q("select 1 from backup_exemptions where item_uid=%s", w2[0]["uid"]), "another client's protected machine can't be marked not required from this client's page")
r = VIEWER.post(B + "/clients/1/backups/exempt", data={"_csrf": csrf(VIEWER, "/clients/1/backups"), "action": "add", "kind": "device", "ref": "1", "reason": "zzq"})
ok(r.status_code == 403, "a viewer can't mark anything not required")

# ---- service levels: the period before is counted by the calendar
setting("psa_sla_sync", "1")
was = q("select value from settings where name='psa_sla_supported'")
setting("psa_sla_supported", "1")
q("delete from psa_tickets where id like 'zzq-%%'")
def tk(i, at): q("insert into psa_tickets (id, psa_client_id, client_id, number, subject, created_at, synced_at) values (%s,'1',1,%s,'ZZQ ticket',%s,now())", i, i, at)
tk("zzq-sla-0", dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S"))
def prior(): return json.loads(php('$r = Align\\Service\\Sla::report(1, "quarter"); echo json_encode($r ? $r["prior"]["tickets"] : null);').stdout or "null")
base = prior()
today = dt.date.today()
cur = dt.date(today.year, ((today.month - 1) // 3) * 3 + 1, 1)
def back3(d): return dt.date(d.year - (1 if d.month <= 3 else 0), (d.month - 4) % 12 + 1, 1)
pq = back3(back3(cur))  # the quarter before the last full one
tk("zzq-sla-a", (pq - dt.timedelta(days=1)).strftime("%Y-%m-%d") + " 23:30:00")  # last evening of the quarter before that
tk("zzq-sla-b", pq.strftime("%Y-%m-%d") + " 00:30:00")                            # first night of the prior quarter
got = prior()
ok(base is not None and got == base + 1, f"the quarter before is exactly that quarter (from {pq}): {base} -> {got}")
q("delete from psa_tickets where id like 'zzq-%%'")
if was:
    setting("psa_sla_supported", was[0]["value"])
else:
    q("delete from settings where name='psa_sla_supported'")

done()

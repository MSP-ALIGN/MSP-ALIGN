"""2.32.1 Bug fixes. Two people adding a client with the same name at the same moment make one client (Clients → Add,
the CSV import and a lead's contract take one lock per name). The Budgets page reads every client's budget in a few
queries, with the same results as one client at a time. The CSV contact check reads the file's clients' contacts
once, with the same plan as before. Lenovo warranty lookups stop at 200 serials per sync (never-checked first); the
next sync goes on. Negative amounts show the minus sign first (-$45; locale_e2e has the details). Everything here is
made up (Zz F2321 names, *.example addresses)."""
import json
import time
import subprocess
from lib import *

tech = login("tech@example.com", TECH_PASSWORD)
PHPB = f'require "{BOOTSTRAP}"; '


def run(code, timeout=180):
    """PHP with the app loaded; stdout and stderr together (a fatal error shows up in it)."""
    r = subprocess.run(["php", "-r", PHPB + code], env=ENV, capture_output=True, text=True, timeout=timeout)
    return (r.stdout + r.stderr).strip()


def start(code):
    """The same, started in the background (for two at once)."""
    return subprocess.Popen(["php", "-r", PHPB + code], env=ENV, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)


def count(name): return q("select count(*) n from clients where name=%s", name)[0]["n"]


q("delete from clients where name like 'Zz F2321%%'")

# ---- 2. One client per name: a request that holds the name lock (as a second person adding the same name) makes
# Clients → Add wait, and then it finds the client instead of adding it again
RACE = "Zz F2321 Race Clinic"
HOLD = ('Align\\Clients\\NameLock::with(%s, function () { echo "held\\n"; fflush(STDOUT); sleep(3);'
        ' if (!Align\\Clients\\NameLock::taken(%s)) { Align\\DB::insert("clients", ["name" => %s, "source" => "manual"]); } });')
holder = start(HOLD % (json.dumps(RACE), json.dumps(RACE), json.dumps(RACE)))
ok(holder.stdout.readline().strip() == "held", "a second request holds the lock for the name")
t0 = time.time()
r = tech.post(B + "/clients", data={"_csrf": csrf(tech, "/clients"), "name": RACE, "meeting_cadence": "annual"})
waited = time.time() - t0
holder.wait(timeout=30)
ok(waited > 1.5 and "already exists" in flash(r.text), "Clients → Add waited for it (%.1fs) and then said the name is taken: %s" % (waited, flash(r.text)[:100]))
ok(count(RACE) == 1, "one client with that name, not two: %d" % count(RACE))
r = tech.post(B + "/clients", data={"_csrf": csrf(tech, "/clients"), "name": "Zz F2321 Plain Clinic", "meeting_cadence": "annual"})
ok("Added Zz F2321 Plain Clinic" in flash(r.text) and count("Zz F2321 Plain Clinic") == 1 and not errs(r.text), "adding a new name still works: " + flash(r.text)[:80])

# the CSV import: two imports of the same new client at the same moment (and one waiting for a held lock)
IMP = "Zz F2321 Import Clinic"
APPLY = ('[$a, $u, $n] = Align\\Import\\CsvImport::apply("clients", [["action" => "add", "name" => %s, "values" => ["industry" => "Dental"]]], null); echo "added $a";' % json.dumps(IMP))
holder = start(HOLD % (json.dumps(IMP), json.dumps(IMP), json.dumps(IMP)))
ok(holder.stdout.readline().strip() == "held", "a request holds the lock for the imported name")
p1, p2 = start(APPLY), start(APPLY)
o1, o2 = p1.communicate(timeout=60)[0].strip(), p2.communicate(timeout=60)[0].strip()
holder.wait(timeout=30)
ok(o1 == "added 0" and o2 == "added 0" and count(IMP) == 1, "two imports waiting on it add nothing; one client: %s / %s / %d" % (o1, o2, count(IMP)))
IMP2 = "Zz F2321 Import Two Clinic"
APPLY2 = APPLY.replace(json.dumps(IMP), json.dumps(IMP2))
p1, p2 = start(APPLY2), start(APPLY2)
o = sorted([p1.communicate(timeout=60)[0].strip(), p2.communicate(timeout=60)[0].strip()])
ok(o == ["added 0", "added 1"] and count(IMP2) == 1, "two imports of the same new client at once make one client: %s %d" % (o, count(IMP2)))


def hold(name, secs=3, insert=True):
    """A second request holding the lock for name for secs seconds, adding that client at the end (unless insert is
    False). Returns once the lock is held."""
    n = json.dumps(name, ensure_ascii=False)
    add = f'if ($ins && !Align\\Clients\\NameLock::taken({n})) {{ Align\\DB::insert("clients", ["name" => {n}, "source" => "manual"]); }}'
    h = start(f'$ins = {"true" if insert else "false"}; Align\\Clients\\NameLock::with({n}, function () use ($ins) {{ echo "held\\n"; fflush(STDOUT); sleep({secs}); {add} }});')
    ok(h.stdout.readline().strip() == "held", "a second request holds the lock for " + name)
    return h


# accents, case and trailing spaces take the same lock, as the collation compares the names
h = hold("Zz F2321 Café Clinic")
r = tech.post(B + "/clients", data={"_csrf": csrf(tech, "/clients"), "name": "Zz F2321 Cafe Clinic", "meeting_cadence": "annual"})
h.wait(timeout=30)
n = q("select count(*) n from clients where name like 'Zz F2321 Caf%%'")[0]["n"]
ok("already exists" in flash(r.text) and n == 1, "Café and Cafe added at the same moment make one client: %d · %s" % (n, flash(r.text)[:80]))
out = run('echo json_encode(Align\\Clients\\NameLock::key("Zz F2321 Café Clinic") === Align\\Clients\\NameLock::key("zz f2321 CAFE clinic  "));')
ok(out == "true", "Café and cafe take the same lock key: " + out[:100])

# a lead's signed contract adding a client that someone adds at the same moment: linked to that one
q("insert into contracts (title, lead_company) values ('Zz F2321 Agreement', 'Zz F2321 Contract Clinic')")
K = q("select id from contracts where title='Zz F2321 Agreement' order by id desc limit 1")[0]["id"]
h = hold("Zz F2321 Contract Clinic")
out = run('echo json_encode(Align\\Contracts\\Contracts::createClient(["id" => %d, "lead_company" => "Zz F2321 Contract Clinic"]));' % K)
h.wait(timeout=30)
cl = q("select id from clients where name='Zz F2321 Contract Clinic'")
ok(len(cl) == 1 and out.endswith("true]") and q("select client_id from contracts where id=%s", K)[0]["client_id"] == cl[0]["id"],
   "a contract's new client added at the same moment as another: one client, and the contract is linked to it: " + out[:120])

# RMM organizations made into clients: one added by hand meanwhile is left alone; one on its own is made
q("delete from rmm_orgs where org_id like 'zzf2321%%'")
q("insert into rmm_orgs (provider, org_id, name) values ('ninjaone', 'zzf2321a', 'Zz F2321 Rmm Clinic'), ('ninjaone', 'zzf2321b', 'Zz F2321 Rmm Two Clinic')")
h = hold("Zz F2321 Rmm Clinic")
out = run('echo json_encode(Align\\Providers\\ClientLinks::createClientsFromOrgs("ninjaone", ["zzf2321a"]));')
h.wait(timeout=30)
ok(out == "[]" and count("Zz F2321 Rmm Clinic") == 1, "an RMM organization isn't made into a second client with the same name: %s %d" % (out[:100], count("Zz F2321 Rmm Clinic")))
out = run('echo json_encode(Align\\Providers\\ClientLinks::createClientsFromOrgs("ninjaone", ["zzf2321b"]));')
ok(out == '["Zz F2321 Rmm Two Clinic"]' and count("Zz F2321 Rmm Two Clinic") == 1, "a new organization is still made into a client, and named in the result: " + out[:100])
q("delete from client_links where external_id like 'zzf2321%%'")
q("delete from rmm_orgs where org_id like 'zzf2321%%'")

# the import page, when a name stays busy for longer than the wait: nothing is imported, and it says so
r = tech.post(B + "/clients/import", data={"_csrf": csrf(tech, "/clients/import"), "kind": "clients"}, files={"file": ("busy.csv", b"Name\nZz F2321 Busy Clinic\n", "text/csv")})
tok = re.search(r'name="token" value="([a-f0-9]{32})"', r.text)
ok(tok is not None, "a clients file with one new client is checked")
if tok:
    h = hold("Zz F2321 Busy Clinic", secs=13, insert=False)
    r = tech.post(B + "/clients/import/run", data={"_csrf": csrf(tech, "/clients/import"), "token": tok.group(1)})
    h.wait(timeout=30)
    ok("Someone is adding this client right now" in flash(r.text) and "Nothing was imported" in flash(r.text) and count("Zz F2321 Busy Clinic") == 0 and not errs(r.text),
       "a name busy for too long: the import stops with a message: " + flash(r.text)[:140])
out = run('Align\\Clients\\NameLock::with(["Zz F2321 B", "Zz F2321 A", "zz f2321 a "], function () { echo Align\\DB::value("SELECT IS_USED_LOCK(?) IS NOT NULL", [Align\\Clients\\NameLock::key("ZZ F2321 A")]); });'
          ' echo "|", Align\\DB::value("SELECT IS_USED_LOCK(?) IS NULL", [Align\\Clients\\NameLock::key("Zz F2321 A")]);')
ok(out == "1|1", "names are locked case-insensitively and released afterwards: " + out[:200])

# ---- 3. Budgets page: every client's budget from a few queries, the same as one client at a time
C = q("insert into clients (name, source) values ('Zz F2321 Budget Clinic', 'manual')") or q("select id from clients where name='Zz F2321 Budget Clinic'")[0]["id"]
for amt in ("120.50", "75"):  # two lines with the same name and category: their order must not change
    q("insert into budget_lines (client_id, name, category, amount, frequency) values (%s, 'Zz F2321 Backup', 'other', %s, 'monthly')", C, amt)
q("insert into budget_lines (client_id, name, category, amount, frequency, start_date) values (%s, 'Zz F2321 Firewall', 'hardware', 1800, 'one_time', curdate())", C)
q("insert into psa_billing (client_id, method, computed_at, monthly, invoices, months) values (%s, 'invoices', now(), 950, 3, 3)", C)
q("insert into roadmap_items (client_id, title, status, cost, target_quarter) values (%s, 'Zz F2321 Wi-Fi refresh', 'approved', 4200, %s)", C, time.strftime("%Y-%m-01"))
q("insert into licenses (client_id, name, seats, unit_price, pricing, billing_cycle) values (%s, 'Zz F2321 Mail', 12, 6, 'per_seat', 'monthly')", C)
for nm, col in (("Zz F2321 Archived Clinic", "is_archived"), ("Zz F2321 Excluded Clinic", "planning_excluded")):
    q(f"insert into clients (name, source, {col}) values (%s, 'manual', 1)", nm)
    q("insert into budget_lines (client_id, name, category, amount, frequency) select id, 'Zz F2321 Hidden line', 'other', 999, 'monthly' from clients where name=%s", nm)
HIDDEN = [x["id"] for x in q("select id from clients where name in ('Zz F2321 Archived Clinic', 'Zz F2321 Excluded Clinic')")]
BUD = r'''
use Align\Budget\Budget; use Align\DB;
$by = [];
foreach ((new Align\Lifecycle\Lifecycle())->devices() as $d) { if ($d['client_id']) { $by[$d['client_id']][] = $d; } }
$ids = array_map('intval', array_column(DB::all('SELECT id FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'), 'id'));
$q0 = DB::$queries; $old = []; foreach ($ids as $id) { $old[$id] = Budget::build($id, $by[$id] ?? []); } $oldQ = DB::$queries - $q0;
$q0 = DB::$queries; $pre = Budget::preloadAll(); $new = []; foreach ($ids as $id) { $new[$id] = Budget::build($id, $by[$id] ?? [], $pre[$id] ?? Budget::EMPTY_PRELOAD); } $newQ = DB::$queries - $q0;
$y = Align\Roadmap\Plan::quarters()[Align\Roadmap\Plan::currentIndex()]['year'];
$grand = array_sum(array_map(fn($b) => $b['years'][$y]['total'], $old));
echo json_encode(['same' => json_encode($old) === json_encode($new), 'clients' => count($ids), 'hidden' => array_values(array_intersect(array_keys($pre), [%s])), 'oldQ' => $oldQ, 'newQ' => $newQ,
  'lines' => count($new[%d]['lines']), 'grand' => money($grand), 'run' => money(array_sum(array_column($old, 'runRate')))]);
''' % (",".join(map(str, HIDDEN)), C)
out = run(BUD)
try:
    b = json.loads(out)
except ValueError:
    b = {}
ok(b.get("same") is True and b.get("clients", 0) >= 2, "every client's budget is the same read all at once as one at a time: " + out[:200])
ok(b.get("hidden") == [] and len(HIDDEN) == 2, "archived and planning-excluded clients' lines aren't read: %s" % b.get("hidden"))
ok(b.get("lines", 0) >= 5, "the test client has its lines (manual, managed services, licensing, project): %s" % b.get("lines"))
ok(b and b["newQ"] <= 4 and b["oldQ"] >= 3 * b["clients"], "four queries for all clients instead of about four per client: %s then %s" % (b.get("oldQ"), b.get("newQ")))
r = tech.get(B + "/budget")
ok(r.status_code == 200 and not errs(r.text) and "Zz F2321 Budget Clinic" in r.text and "Zz F2321 Archived" not in r.text and "Zz F2321 Excluded" not in r.text, "the Budgets page shows (not the archived or excluded clients)")
ok(b and b["grand"] in r.text and b["run"] in r.text, "its totals are the ones worked out one client at a time: %s, %s" % (b.get("grand"), b.get("run")))

# ---- 4. CSV contacts: the plan from one read of the contacts matches findContact() row by row
for n, e, arch in (("Pat Example", "pat.f2321@example.com", 0), ("Lee Example", None, 0), ("José Example", None, 0),
                   ("Old Example", "old.f2321@example.com", 1), ("Sam Example", "sam.f2321@example.com", 0), ("Sam Example", None, 0)):
    q("insert into contacts (client_id, source, name, email, archived_at) values (%s, 'manual', %s, %s, if(%s, now(), null))", C, n, e, arch)
# (review) a Thai name: its vowel sign makes a different name; an email kept as '' counts as no email; the same email
# at another client is not a match
q("insert into contacts (client_id, source, name, email) values (%s, 'manual', 'ก', null), (%s, 'manual', 'Rae Example', '')", C, C)
P = q("select id from clients where name='Zz F2321 Plain Clinic'")[0]["id"]
q("insert into contacts (client_id, source, name, email) values (%s, 'manual', 'Ash Example', 'ash.f2321@example.com')", P)
CSV = ("Client,Name,Email,Title\n"
       "Zz F2321 Budget Clinic,Pat Example,PAT.F2321@example.com,Owner\n"          # email match, any case: update
       "Zz F2321 Budget Clinic,Lee Example,lee.new.f2321@example.com,\n"         # a contact with that name and no email: update
       "zz f2321 budget clinic,jose example,,\n"                                  # name match without accents or case: update
       "Zz F2321 Budget Clinic,Old Example,old.f2321@example.com,\n"             # archived: add
       "Zz F2321 Budget Clinic,New Example,new.f2321@example.com,\n"             # new: add
       "Zz F2321 Budget Clinic,Pat Example,pat.f2321@example.com,\n"             # same as row 2: skip
       "Zz F2321 Budget Clinic,Sam Example,other.f2321@example.com,\n"           # no email match, the no-email Sam: update
       "Zz F2321 Budget Clinic,Sam Example,,\n"                                   # no email: any Sam
       "Zz F2321 Budget Clinic,กิ,,\n"                                            # not the same as ก: add
       "Zz F2321 Budget Clinic,Ash Example,ash.f2321@example.com,\n"             # that email is at another client: add
       "Zz F2321 Budget Clinic,Rae Example,rae.f2321@example.com,\n"             # Rae's email is '': no email, update
       "Zz F2321 Nowhere Clinic,Kim Example,kim.f2321@example.com,\n"            # unknown client: error
       "Zz F2321 Budget Clinic,,nobody.f2321@example.com,\n")                    # no name: error
PLAN = r'''
use Align\Import\CsvImport; use Align\DB;
$t = tempnam(sys_get_temp_dir(), "f2321"); file_put_contents($t, %s);
[$h, $rows] = CsvImport::read($t); unlink($t); [$m] = CsvImport::mapHeaders($h, CsvImport::CONTACT_COLUMNS);
$q0 = DB::$queries; $plan = CsvImport::planContacts($rows, $m); $n = DB::$queries - $q0;
$find = new ReflectionMethod(CsvImport::class, 'findContact');
$bad = [];
foreach ($plan as $p) {
  if (!isset($p['client_id']) || !in_array($p['action'], ['add', 'update'], true)) { continue; }
  $ex = $find->invoke(null, (int) $p['client_id'], $p['values']['email'] ?? null, $p['values']['name']);
  $want = $ex ? 'update:' . $ex['id'] : 'add';
  $got = $p['action'] === 'update' ? 'update:' . $p['id'] : 'add';
  if ($want !== $got) { $bad[] = $p['row'] . " $got, before: $want"; }
}
echo json_encode(['q' => $n, 'bad' => $bad, 'actions' => array_column($plan, 'action')]);
''' % json.dumps(CSV, ensure_ascii=False)
out = run(PLAN)
try:
    p = json.loads(out)
except ValueError:
    p = {}
ok(p.get("actions") == ["update", "update", "update", "add", "add", "skip", "update", "update", "add", "add", "update", "error", "error"], "the plan: matches, new rows, a repeat and errors: %s" % p.get("actions", out[:200]))
ok(p.get("bad") == [], "each row is matched as the per-row lookup matched it: %s" % p.get("bad"))
ok(p and p["q"] <= 3, "a few queries for the whole file (it was two per row): %s" % p.get("q"))
r = tech.post(B + "/clients/import", data={"_csrf": csrf(tech, "/clients/import"), "kind": "contacts"}, files={"file": ("people.csv", CSV.encode(), "text/csv")})
ok(r.status_code == 200 and not errs(r.text) and "4 to add" in r.text, "the import page checks the file the same way: " + flash(r.text)[:100])
# (review) an update is checked again before it is written: a planned id that is no longer this row's match is skipped
PAT = q("select id from contacts where client_id=%s and name='Pat Example'", C)[0]["id"]
LEE = q("select id from contacts where client_id=%s and name='Lee Example'", C)[0]["id"]
UPD = ('[$a, $u] = Align\\Import\\CsvImport::apply("contacts", [["action" => "update", "id" => %d, "client_id" => %d, "name" => "Lee Example", "client" => "x",'
       ' "values" => ["name" => "Lee Example", "title" => "Zz F2321 changed"]]], null); echo "updated $u";')
out = run(UPD % (PAT, C))
ok(out == "updated 0" and q("select title from contacts where id=%s", PAT)[0]["title"] != "Zz F2321 changed", "a planned id that isn't the row's contact is left alone: " + out[:120])
out = run(UPD % (LEE, C))
ok(out == "updated 1" and q("select title from contacts where id=%s", LEE)[0]["title"] == "Zz F2321 changed", "the right one is still updated: " + out[:120])

# ---- 5. Lenovo: at most 200 serials per sync, never-checked first; the next sync goes on
q("delete from warranty_lookups where serial like 'ZZL2321%%'")
for i in range(230):
    q("insert into devices (source, client_id, display_name, system_name, serial, manufacturer, device_type, device_class) values ('manual', %s, %s, %s, %s, 'LENOVO', 'Laptop', 'laptop')",
      C, "ZZF2321-LT%03d" % i, "ZZF2321-LT%03d" % i, "ZZL2321%03d" % i)
# (review) Dell keeps its cap of 500 serials per sync
q("delete from warranty_lookups where serial like 'ZZD2321%%'")
q("insert into devices (source, client_id, display_name, system_name, serial, manufacturer, device_type, device_class) values "
  + ",".join(["('manual', %s, 'ZZF2321-DT%03d', 'ZZF2321-DT%03d', 'ZZD2321%03d', 'Dell Inc.', 'Desktop', 'desktop')" % (C, i, i, i) for i in range(510)]))
# one checked long ago: due, but after every serial never checked
q("insert into warranty_lookups (vendor, serial, status, looked_up_at) values ('lenovo', 'ZZL2321000', 'ok', now() - interval 400 day)")
LOOK = ('$r = new Align\\Sync\\SyncRunner("cli"); echo (new ReflectionMethod($r, "lookupWarranties"))->invoke($r);')
since = q("select now() t")[0]["t"]
out = run(LOOK, timeout=300)
n1 = q("select count(*) n from warranty_lookups where vendor='lenovo' and looked_up_at >= %s", since)[0]["n"]
mine1 = q("select count(*) n from warranty_lookups where serial like 'ZZL2321%%' and looked_up_at >= %s", since)[0]["n"]
old1 = q("select looked_up_at >= %s f from warranty_lookups where serial='ZZL2321000'", since)[0]["f"]
ok(n1 == 200 and mine1 >= 170, "the first sync looks up 200 Lenovo serials, no more: %d (%d of the test's) · %s" % (n1, mine1, out[:120]))
ok(not old1, "never-checked serials go first: the one checked long ago waits")
d1 = q("select count(*) n from warranty_lookups where vendor='dell' and looked_up_at >= %s", since)[0]["n"]
ok(d1 == 500, "Dell still looks up at most 500 serials per sync: %d" % d1)
time.sleep(1.1)
since2 = q("select now() t")[0]["t"]
out = run(LOOK, timeout=300)
left = q("select count(*) n from devices d left join warranty_lookups w on w.serial = d.serial where d.serial like 'ZZL2321%%' and (w.looked_up_at is null or w.looked_up_at < %s)", since)[0]["n"]
n2 = q("select count(*) n from warranty_lookups where vendor='lenovo' and looked_up_at >= %s", since2)[0]["n"]
dleft = q("select count(*) n from devices d left join warranty_lookups w on w.serial = d.serial where d.serial like 'ZZD2321%%' and w.looked_up_at is null")[0]["n"]
ok(dleft == 0, "and the next sync looks up the other Dell serials: %d left" % dleft)
ok(left == 0 and 0 < n2 <= 200, "the next sync looks up the rest: %d left, %d looked up · %s" % (left, n2, out[:120]))

# ---- 1. Negative amounts on a page: the minus sign goes first
out = run('echo money(-45), "|", money_exact(-1234.5), "|", Align\\Fmt::moneyShort(-1250000);')
ok(out == "-$45|-$1,234.50|-$1.3M", "negative amounts: " + out)
js = tech.get(B + "/assets/app.js").text
ok("const sign = r < 0" in js, "the browser's amounts put the sign first too")

# ---- clean up
q("delete from warranty_lookups where serial like 'ZZL2321%%' or serial like 'ZZD2321%%'")
q("delete from devices where serial like 'ZZL2321%%' or serial like 'ZZD2321%%'")
q("delete from contract_events where contract_id in (select id from contracts where title='Zz F2321 Agreement')")
q("delete from contracts where title='Zz F2321 Agreement'")
for t in ("contacts", "budget_lines", "psa_billing", "roadmap_items", "licenses"):
    q(f"delete from {t} where client_id in (select id from clients where name like 'Zz F2321%%')")
q("delete from clients where name like 'Zz F2321%%'")
done()

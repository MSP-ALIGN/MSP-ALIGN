"""2.2.1 review: PSA / RMM / backup providers, client links, licensing and the mapping pages.
Data from ITFlow, NinjaOne and Veeam is untrusted: ids are parsed strictly (no record lands on another client), text and
dates are made to fit before they reach the database (one bad row can't stop a sync), names match by every script,
ticket subjects go out on one line, and the Hosted backups forms leave items alone on malformed input."""
from lib import *
import json, subprocess

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def pj(code):
    out = phpv(code)
    try:
        return json.loads(out)
    except ValueError:
        print("   (php said: %s)" % out[:200])
        return None
def chk(f, m):
    """ok() for a condition that may fail to evaluate on old code (a missing method, a PHP warning instead of JSON)."""
    try:
        c = bool(f())
    except Exception as e:
        c, m = False, m + " (error: %r)" % e
    ok(c, m)
def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
REF = "(new ReflectionMethod(Align\\Providers\\Psa\\ItflowPsa::class, %s))"

# ---- ItflowPsa: ids only when they are whole numbers (a loose cast made [5], true and "1abc" client 1)
ids = pj("$m = " + REF % "'id'" + "; echo json_encode(array_map(fn($v) => $m->invoke(null, $v), [[5], true, 1.9, '1abc', '1e3', '12', 12, ' 7 ', '0', -3]));")
chk(lambda: ids == ["", "", "", "", "", "12", "12", "7", "", ""], "ITFlow ids: only ints and digit strings count; [5], true, 1.9, '1abc', '1e3' are no id")
a = pj("$o = (new ReflectionClass(Align\\Providers\\Psa\\ItflowPsa::class))->newInstanceWithoutConstructor(); $m = " + REF % "'asNeutralAsset'" + ";"
       " echo json_encode($m->invoke($o, ['asset_id' => 5, 'asset_client_id' => [2], 'asset_name' => ['x'], 'asset_type' => str_repeat('t', 300),"
       " 'asset_serial' => \"SN\\r\\n1\\x00\", 'asset_purchase_date' => '2026-13-01', 'asset_warranty_expire' => '0000-00-00', 'asset_install_date' => '2026-02-28',"
       " 'asset_updated_at' => 'yesterday', 'asset_description' => \"line 1\\r\\nline 2\\x07\"]));")
chk(lambda: a["client_id"] == "", "an asset whose client id is [2] belongs to no client (was client 1)")
chk(lambda: a["name"] is None and len(a["type"]) == 100 and a["serial"] == "SN  1", "asset text: non-text is null, type cut to its column (100), control characters gone")
chk(lambda: a["purchase_date"] is None and a["warranty_expire"] is None and a["install_date"] == "2026-02-28" and a["updated_at"] is None, "asset dates: month 13, zero dates and words are dropped instead of failing the sync in the database")
chk(lambda: a["description"] == "line 1\nline 2", "a multi-line field keeps its line breaks but loses control characters")
t = pj("$m = " + REF % "'asNeutralTicket'" + "; echo json_encode($m->invoke(null, ['ticket_id' => '9', 'ticket_client_id' => true, 'ticket_prefix' => ['x'],"
       " 'ticket_number' => 12, 'ticket_subject' => '<b>Printer</b> &amp; scanner', 'ticket_created_at' => '2026-01-02T03:04:05Z', 'ticket_response_sla_met' => ['x']]));")
chk(lambda: t["client_id"] == "" and t["number"] == "12" and t["subject"] == "Printer & scanner" and t["created_at"] == "2026-01-02 03:04:05" and t["response_met"] is None, "tickets: a client id of true is no client (was 1), an array prefix is dropped, times are normalised")

# ---- ItflowPsa: ticket subjects go out as one clean line of at most 250 characters (they can hold what a visitor typed)
mock("/mock/ticket-create-fail", {"on": False})
n0 = len(mock("/mock/tickets-created")["created"])
out = phpv("echo Align\\Providers\\Providers::psa()->createTicket('1', \"NEW USER: Pat Example\\r\\nBcc: someone@evil.example\\x00\" . str_repeat('x', 400), '<p>Details</p>');")
made = mock("/mock/tickets-created")["created"][n0:]
subj = made[-1]["ticket_subject"] if made else ""
ok(made and "\r" not in subj and "\n" not in subj and "\x00" not in subj and len(subj) == 250 and subj.startswith("NEW USER: Pat Example  Bcc:"),
   "ticket subject sent on one line, without control characters, cut to 250: %r (%s)" % (subj[:60], out[:80]))

# ---- NinjaOneRmm: console links only for real device ids and an http(s) instance
links = pj("$ok = new Align\\Providers\\Rmm\\NinjaOneRmm(null, 'eu.ninjarmm.com'); $bad = new Align\\Providers\\Rmm\\NinjaOneRmm(null, 'javascript://x%0Aalert(1)');"
           " echo json_encode([$ok->deviceUrl('12'), $ok->deviceUrl('abc'), $bad->deviceUrl('12')]);")
chk(lambda: links == ["https://eu.ninjarmm.com/#/deviceDashboard/12/overview", None, None], "device links: a non-numeric id gives no link (was device 0), a non-http instance gives no link (was a javascript: href)")
nid = pj("$m = new ReflectionMethod(Align\\Providers\\Rmm\\NinjaOneRmm::class, 'id'); echo json_encode(array_map(fn($v) => $m->invoke(null, $v), ['abc', [7], true, 0, '15', 15, '007']));")
chk(lambda: nid == [None, None, None, None, "15", "15", "7"], "NinjaOne organization/device ids: 'abc', [7], true and 0 are skipped, not folded into org 0 or 1")
txt = pj("$m = new ReflectionMethod(Align\\Providers\\Rmm\\NinjaOneRmm::class, 'text'); $d = new ReflectionMethod(Align\\Providers\\Rmm\\NinjaOneRmm::class, 'dt');"
         " echo json_encode([$m->invoke(null, str_repeat('n', 300), 255), $m->invoke(null, ['x'], 255), $m->invoke(null, \"PC\\r\\n01\", 255),"
         " $d->invoke(null, '2026-01-01 10:00:00'), $d->invoke(null, '31690708-01-01 00:00:00')]);")
chk(lambda: len(txt[0]) == 255 and txt[1] is None and txt[2] == "PC  01" and txt[3] == "2026-01-01 10:00:00" and txt[4] is None, "NinjaOne text cut to the devices columns, non-text dropped, impossible timestamps dropped (one device no longer fails the device sync)")

# ---- VeeamBackup: a uid too long for its column is hashed, not cut (two cut uids became one machine)
wl = pj("$m = new ReflectionMethod(Align\\Providers\\Backup\\VeeamBackup::class, 'addWorkload'); $wl = []; $j = [];"
        " $long = str_repeat('a', 120); foreach ([$long . '1', $long . '2'] as $i => $id) { $m->invokeArgs(null, [&$wl, ['instanceUid' => $id, 'name' => 'srv' . $i,"
        " 'organizationUid' => str_repeat('c', 70) . $i], 'vm', null, &$j]); } echo json_encode(array_values($wl));")
uids = [w.get("uid") for w in (wl or []) if isinstance(w, dict)]
chk(lambda: len(wl) == 2 and len(set(uids)) == 2 and all(len(u or "") <= 100 for u in uids), "two machines whose long ids share 100 characters stay two machines")
chk(lambda: len({w["company_uid"] for w in wl}) == 2 and all(w["company_uid"].startswith("sha1:") and len(w["company_uid"]) <= 64 for w in wl), "long company uids fit backup_*.company_uid (64) and stay distinct")

# ---- ClientLinks::normalizeName: letters of every script count, accents fold
nm = pj("echo json_encode(array_map([Align\\Providers\\ClientLinks::class, 'normalizeName'], ['Bäcker Example GmbH', 'Böcker Example GmbH', 'Асme', 'Me', 'Café Uno LLC', 'Cafe Uno', 'Cedar Ridge Family Dental, Inc.']));")
chk(lambda: nm[0] != nm[1], "'Bäcker' and 'Böcker' no longer reduce to the same name (an organization could be auto-linked to the wrong client)")
chk(lambda: nm[2] != nm[3], "a Cyrillic 'Асme' no longer reduces to 'me' and matches a client called 'Me'")
chk(lambda: nm[4] == nm[5] == "cafe uno" and nm[6] == "cedar ridge family dental", "accents fold and Inc/LLC still drop")

# ---- ClientLinks::autoMatch: a name two clients share links neither (the first one used to take the organization)
links0 = q("select * from client_links order by client_id, provider")
q("insert into clients (name, source, is_archived, planning_excluded) values ('Zz Prov Twin Inc','manual',0,0),('ZZ PROV TWIN LLC','manual',0,0),('Zz Prov Bäcker','manual',0,0)")
tw = [r["id"] for r in q("select id from clients where name in ('Zz Prov Twin Inc','ZZ PROV TWIN LLC','Zz Prov Bäcker')")]
q("insert into rmm_orgs (provider, org_id, name, synced_at) values ('ninjaone','9931','Zz Prov Twin',now()),('ninjaone','9932','Zz Prov Böcker',now())")
phpv('Align\\Providers\\ClientLinks::autoMatch("ninjaone");')
got = q("select client_id, external_id from client_links where provider='ninjaone' and external_id in ('9931','9932')")
ok(not got, "an organization named like two clients, or like a client with different letters (Böcker / Bäcker), is linked to none: %s" % (got,))
q("delete from client_links where client_id in (%s)" % ",".join(map(str, tw))); q("delete from rmm_orgs where org_id in ('9931','9932')")
q("delete from clients where id in (%s)" % ",".join(map(str, tw)))
q("delete from client_links")  # auto-match may have linked other test clients too: put every link back as it was
for r in links0: q("insert into client_links (client_id, provider, external_id, match_method, created_at) values (%s,%s,%s,%s,%s)", r["client_id"], r["provider"], r["external_id"], r["match_method"], r["created_at"])
ok(q("select * from client_links order by client_id, provider") == links0, "client links restored after the auto-match check")

# ---- Licenses: hostile software rows from ITFlow are stored safely, and never move to another client
lic = lambda sid: (q("select client_id, name, seats, expire_date, retired_at from licenses where psa_id=%s", sid) or [None])[0]
c202 = lic("202")
mock("/mock/software-edit", {"software_id": 105, "fields": {"software_name": "Adobe\r\nAcrobat\x00 Pro", "software_seats": "99999999999", "software_expire": "not-a-date"}})
mock("/mock/software-edit", {"software_id": 202, "fields": {"software_client_id": [1]}})
out = phpv("echo Align\\Licensing\\Licenses::syncFromPsa(Align\\Providers\\Providers::psa());")
l105, l202 = lic("105"), lic("202")
ok("licenses" in out and "Exception" not in out and "SQLSTATE" not in out, "license sync finishes with a malformed date and a huge seat count in ITFlow: " + out[:120])
ok(l105 and l105["name"] == "Adobe  Acrobat Pro" and l105["seats"] == 4294967295 and l105["expire_date"] is None,
   "the name loses its line break and NUL, seats are clamped to the column, the bad date is dropped: %s" % l105)
ok(c202 and l202 and l202["client_id"] == c202["client_id"], "a license whose client id is [1] stays with its client (a loose cast moved it to client 1): %s" % l202)
mock("/mock/software-edit", {"software_id": 105, "fields": {"software_name": "Adobe Acrobat Pro", "software_seats": 3, "software_expire": "2025-06-01"}})
mock("/mock/software-edit", {"software_id": 202, "fields": {"software_client_id": 2}})
phpv("Align\\Licensing\\Licenses::syncFromPsa(Align\\Providers\\Providers::psa());")
ok((lic("202")["retired_at"] is None) == (c202["retired_at"] is None) and lic("105")["seats"] == 3, "restored in ITFlow, restored in Align")

# an empty answer (an API key that lost access to software) doesn't retire every license
FAKE = ("$p = new class implements Align\\Providers\\Psa\\PsaProvider {"
        " public function key(): string { return 'itflow'; } public function name(): string { return 'ITFlow'; }"
        " public function supports(string $c): bool { return true; } public function test(): string { return ''; }"
        " public function clients(): array { return []; } public function contacts(): array { return []; } public function locations(): array { return []; }"
        " public function assets(): array { return []; } public function asset(string $a): ?array { return null; }"
        " public function createAsset(string $c, array $f): string { return ''; } public function updateAsset(string $c, string $a, array $f): bool { return false; }"
        " public function mapAssetType(array $a): array { return ['', '']; } public function assetTypeFor(string $t): ?string { return null; }"
        " public function assetStatus(bool $r): string { return ''; } public function statusRetired(?string $s): bool { return false; }"
        " public function updateContact(string $c, string $k, array $f): bool { return false; } public function createContact(string $c, array $f): string { return ''; }"
        " public function archiveContact(string $c, string $k, bool $a = true): bool { return false; }"
        " public function licenses(): array { return []; } public function vendors(): array { return []; } public function invoices(): array { return []; }"
        " public function tickets(string $s, array $st, callable $store): array { return []; } public function ticket(string $t): ?array { return null; }"
        " public function createTicket(string $c, string $s, string $d, string $p = 'Medium', ?string $k = null): string { return ''; }"
        " public function clientUrl(string $c): ?string { return null; } public function assetUrl(string $c, string $a): ?string { return null; }"
        " public function ticketUrl(string $t): ?string { return null; } }; ")
active = q("select count(*) n from licenses where source='psa' and retired_at is null")[0]["n"]
out = phpv(FAKE + "try { echo Align\\Licensing\\Licenses::syncFromPsa($p); } catch (RuntimeException $e) { echo 'REFUSED: ' . $e->getMessage(); }")
ok(active > 0 and out.startswith("REFUSED: ITFlow returned no licenses") and q("select count(*) n from licenses where source='psa' and retired_at is null")[0]["n"] == active,
   "a PSA answer with no licenses is refused and nothing is retired (was: every license retired): " + out[:100])

# ---- Hosted backups: a malformed value leaves an assignment alone; hosting flag changes are named in the audit log
tech = login("tech@example.com", TECH_PASSWORD)
uid = "vm:prov-q-test"
q("replace into backup_workloads (uid, provider, company_uid, kind, name, synced_at) values (%s, 'veeam', NULL, 'vm', 'PROVQ-SRV', now())", uid)
q("replace into backup_assignments (item_type, item_uid, client_id, item_name) values ('workload', %s, 1, 'prov_q')", uid)
r = tech.post(B + "/mapping/backups", data={"_csrf": csrf(tech, "/mapping/backups"), "wl[%s][]" % uid: "x"})
row = q("select client_id from backup_assignments where item_type='workload' and item_uid=%s", uid)
ok(row and row[0]["client_id"] == 1 and not errs(r.text), "a machine's value posted as an array leaves its assignment as it was (was reset to automatic)")

# bulk: the same id posted twice counts once
r = tech.post(B + "/mapping/backups/bulk", data={"_csrf": csrf(tech, "/mapping/backups"), "ids[]": [uid, uid], "client": "none"})
ok("1 machine set to" in flash(r.text) and q("select client_id from backup_assignments where item_type='workload' and item_uid=%s", uid)[0]["client_id"] is None,
   "bulk: the same machine posted twice is one change: " + flash(r.text))
q("delete from backup_assignments where item_type='workload' and item_uid=%s", uid)
q("delete from backup_workloads where uid=%s", uid)

C1 = "11111111-1111-1111-1111-111111111111"
flags0 = json.loads((q("select value from settings where name='veeam_hosting_companies'") or [{"value": "[]"}])[0]["value"] or "[]")
name1 = (q("select name from backup_companies where provider='veeam' and uid=%s", C1) or [{"name": C1}])[0]["name"]
tech.post(B + "/mapping/backups", data={"_csrf": csrf(tech, "/mapping/backups"), "hosting[]": [u for u in flags0 if u != C1]})
tech.post(B + "/mapping/backups", data={"_csrf": csrf(tech, "/mapping/backups"), "hosting[]": [*[u for u in flags0 if u != C1], C1]})
d = q("select detail from audit_log where action='backup.assign' order by id desc limit 1")[0]["detail"]
ok(f"{name1} → hosting" in d, "turning a company into a hosting server is named in the audit entry: " + d[:160])
tech.post(B + "/mapping/backups", data={"_csrf": csrf(tech, "/mapping/backups"), "hosting[]": [u for u in flags0 if u != C1]})
d = q("select detail from audit_log where action='backup.assign' order by id desc limit 1")[0]["detail"]
ok(f"{name1} → not hosting" in d, "...and so is turning it off")
tech.post(B + "/mapping/backups", data={"_csrf": csrf(tech, "/mapping/backups"), "hosting[]": flags0})

# ---- the mapping pages still answer for tech, not for a viewer
for p in ["/mapping", "/mapping/backups"]:
    t = tech.get(B + p).text
    ok(not errs(t), p + " renders without PHP errors")
viewer = login("viewer@example.com", "ViewerPassword123!")
ok(viewer.get(B + "/mapping").status_code == 403 and viewer.post(B + "/mapping/backups/bulk", data={"_csrf": csrf(viewer, "/"), "ids[]": ["x"], "client": "none"}).status_code == 403,
   "a viewer can't open the mapping page or post a bulk change")
done()

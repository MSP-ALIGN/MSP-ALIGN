"""2.26.0 Backups. (1) A backup copy (offsite) job Veeam reports no size for (backupChainSize 0) shows the restore points
its protected machines hold through it, added up; a job still with no size shows "—", never "0 B". (2) A client can be
marked "Doesn't need backups" on Client mapping (the backup product's column) or its Connectors page: it's no longer
missing a link, sync never links it by name, and its Backups page, Connectors page, portfolio report and Alignment
backups indicator say it doesn't need backups instead of flagging it. A client that does have backup data keeps showing
it. Techs only; viewers can't; migration 085 runs twice."""
import re, json, html as H
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
MIG = ROOT + "/db/migrations/085_backup_not_needed.php"
NN = "__not_needed"


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))


def save_map(who, cid, val, show=""):
    """Posts Client mapping with one Veeam choice for one client."""
    return who.post(B + "/mapping", data={"_csrf": csrf(who, "/mapping"), f"link[veeam][{cid}]": val, "show": show})


def link(cid):
    r = q("select external_id, match_method from client_links where client_id=%s and provider='veeam'", cid)
    return r[0] if r else None


def not_needed(cid):
    return php(f"echo json_encode(Align\\Backup\\Backup::notNeeded({cid}));").stdout.strip() == "true"


def cleanup():
    q("delete from client_links where client_id in (select id from clients where name like 'R226 %%')")
    q("delete from clients where name like 'R226 %%'")


cleanup()
keep1 = link(1)
try:
    # ======== (1) copy job size
    j = q("select name, chain_bytes from backup_jobs where uid='j-1002'")
    ok(j and j[0]["chain_bytes"] == 41 * 1024 ** 3, f"the offsite copy job (Veeam sends 0) has the size of the restore points it holds, from VSPC's per-backup list, each VM once: {j and j[0]['chain_bytes']}")
    ok(q("select chain_bytes from backup_jobs where uid='j-1001'")[0]["chain_bytes"] == 912 * 1024 ** 3, "a job Veeam reports a size for keeps it")
    G = 1024 ** 3
    def sizes(backups, records):
        return json.loads(php(f'echo json_encode(Align\\Providers\\Backup\\VeeamBackup::jobSizes(json_decode({json.dumps(json.dumps(backups))}, true), json_decode({json.dumps(json.dumps(records))}, true)));').stdout or "null")
    got = sizes([{"virtualMachineUid": "a", "jobUid": "J1", "totalRestorePointSize": 5 * G}],
                [{"instanceUid": "a", "jobUid": "J1", "totalRestorePointSize": 9 * G},              # J1 comes from the per-backup list
                 {"instanceUid": "b", "jobUid": "J2", "totalRestorePointSize": 3 * G},
                 {"instanceUid": "b", "jobUid": "J2", "totalRestorePointSize": 2 * G},              # listed twice: largest, once
                 {"instanceUid": "c", "jobUid": "J2", "totalRestorePointSize": 0, "backupSize": G},  # 0 first: the next field
                 {"instanceUid": "d", "jobUid": "00000000-0000-0000-0000-000000000000", "totalRestorePointSize": G},
                 {"instanceUid": "e", "jobUid": "J3", "totalRestorePointSize": -5}, "junk", {"instanceUid": "f", "jobUid": ["x"], "totalRestorePointSize": G}])
    ok(got == {"J1": 5 * G, "J2": 4 * G}, f"jobSizes: per-backup list first, each machine once, 0/negative/junk/zero uid skipped: {got}")
    t = st.get(B + "/clients/1/backups").text
    row = text(t.split("Offsite copy to cloud", 1)[1].split("</tr>", 1)[0]) if "Offsite copy to cloud" in t else ""
    ok(re.search(r"\b(41|44)(\.\d)? Gi?B", row), "the client's Backups page shows it: " + row[-80:])
    q("update backup_jobs set chain_bytes=0 where uid='j-1002'")
    t = st.get(B + "/clients/1/backups").text
    row = t.split("Offsite copy to cloud", 1)[1].split("</tr>", 1)[0]
    ok("0 B" not in text(row) and "doesn&#039;t report a size for this job" in row and not errs(t), "a job with no size shows —, never 0 B")
    q("update backup_jobs set chain_bytes=%s where uid='j-1002'", 41 * 1024 ** 3)

    # ======== (2) Doesn't need backups
    q("insert into clients (name, source, is_archived, planning_excluded) values ('R226 No Backups Co', 'manual', 0, 0)")
    cid = q("select id from clients where name='R226 No Backups Co'")[0]["id"]
    m = st.get(B + "/mapping?show=missing").text
    ok(f'name="link[veeam][{cid}]"' in m and f'value="{NN}"' in m and "Doesn't need backups" in H.unescape(m), "Client mapping offers Doesn't need backups in the Veeam column; the new client is missing a link")
    ok(not not_needed(cid), "not set yet")
    r = save_map(viewer, cid, NN)
    ok(link(cid) is None, "a viewer can't save mapping")
    r = save_map(tech, cid, NN, show="missing")
    ok(link(cid) == {"external_id": None, "match_method": "not_needed"} and not errs(r.text), f"a tech marks it: {link(cid)} {flash(r.text)[:100]}")
    ok(not_needed(cid), "Backup::notNeeded is true")
    m = st.get(B + "/mapping").text
    sel = m.split(f'name="link[veeam][{cid}]"', 1)[1].split("</select>", 1)[0]
    ok(f'value="{NN}" data-keep="1" selected' in sel and "no backups needed" in m.split(f'name="link[veeam][{cid}]"', 1)[1][:3000], "the mapping page shows it chosen, labelled no backups needed")
    # (the seed has an RMM column too: keep the client unlinked there, so only the backup column decides)
    for p in php('foreach (Align\\Providers\\Providers::linkConnectors() as $k => $c) if ($k !== "veeam") echo $k, " ";').stdout.split():
        q("insert ignore into client_links (client_id, provider, external_id, match_method) values (%s, %s, NULL, 'manual')", cid, p)
    ok(f'name="link[veeam][{cid}]"' not in st.get(B + "/mapping?show=missing").text, "and it's no longer missing a link")
    r = save_map(tech, cid, NN)
    ok("No changes" in flash(r.text), "saving it again changes nothing: " + flash(r.text)[:60])
    t = st.get(B + "/reports/backups").text
    tt = text(t)
    unl = tt.split("Not linked to a", 1)[1].split("Link them on Client mapping", 1)[0] if "Not linked to a" in tt else ""
    ok("Don't need backups (set on Client mapping): R226 No Backups Co" in tt and "R226" not in unl and not errs(t),
       "the all-clients Backup Status report lists it under Don't need backups, not as not linked")
    t = st.get(B + f"/clients/{cid}/backups").text
    ok("This client doesn't need backups" in text(t) and "No backups found" not in t and 'id="hosted-claim-hint"' not in t and not errs(t),
       "its Backups page says so instead of No backups found (and doesn't offer hosted machines to claim)")
    t = tech.get(B + f"/clients/{cid}/connectors").text
    ok("Doesn't need backups" in text(t) and "no backups needed" in t and not errs(t), "its Connectors page shows the badge and chip")
    t = st.get(B + "/reports/portfolio").text
    if "Back&shy;ups" in t:
        ok("Not needed" in text(t.split("R226 No Backups Co", 1)[1].split("</tr>", 1)[0]), "the portfolio report's backups cell says Not needed")
    ind = json.loads(php(f'$c = Align\\DB::one("select * from clients where id = {cid}"); echo json_encode(Align\\Alignment\\Alignment::indicators([], Align\\Backup\\Backup::forClient($c) ?? (Align\\Backup\\Backup::notNeeded($c) ? ["not_needed" => true] : null))["backups"] ?? null);').stdout or "null")
    ok(ind and ind["ok"] and not ind["unknown"] and ind["suggest"] == "na", f"the Alignment backups indicator is N/A, not unknown: {ind}")
    t = st.get(B + f"/clients/{cid}/alignment").text
    ok(not errs(t), "the Alignment page renders")
    # sync never links it by name: a company with its name exists
    q("update backup_companies set name='R226 No Backups Co' where provider='veeam' and uid=(select uid from (select uid from backup_companies where provider='veeam' and uid not in (select external_id from client_links where provider='veeam' and external_id is not null) limit 1) x)")
    php("Align\\Providers\\ClientLinks::autoMatch();")
    ok(link(cid)["match_method"] == "not_needed" and link(cid)["external_id"] is None, "auto-match by name leaves it alone")
    sync_now()
    ok(link(cid)["match_method"] == "not_needed", "and so does a full sync")
    # back to Not linked from its Connectors page (same endpoint, back to the page)
    r = tech.post(B + "/mapping", data={"_csrf": csrf(tech, f"/clients/{cid}/connectors"), f"link[veeam][{cid}]": "", "back": f"/clients/{cid}/connectors"}, allow_redirects=False)
    ok(r.status_code == 302 and f"/clients/{cid}/connectors" in r.headers.get("Location", ""), "the Connectors page form saves and comes back to it")
    ok(link(cid) == {"external_id": None, "match_method": "manual"} and not not_needed(cid), "back to Not linked: kept unlinked, counted again")
    ok(q("select 1 from audit_log where action='mapping.save' and detail like %s", f'%"{cid}":"{NN}"%'), "audited")

    # a client with backup data (machines on the MSP's own server) set to not needed keeps showing it
    keep4 = link(4)
    q("delete from client_links where client_id=4 and provider='veeam'")
    q("insert into client_links (client_id, provider, external_id, match_method) values (4, 'veeam', NULL, 'not_needed')")
    ok(q("select 1 from backup_workloads where client_id=4") and not not_needed(4), "a client with hosted backup data is never treated as not needing backups")
    t = st.get(B + "/clients/4/backups").text
    ok("doesn't need backups" not in text(t) and "Backup jobs" in t, "its Backups page still shows its backups")
    q("delete from client_links where client_id=4 and provider='veeam'")
    if keep4:
        q("insert into client_links (client_id, provider, external_id, match_method) values (4, 'veeam', %s, %s)", keep4["external_id"], keep4["match_method"])

    # the older backup[veeam][] field works too
    tech.post(B + "/mapping", data={"_csrf": csrf(tech, "/mapping"), f"backup[veeam][{cid}]": NN})
    ok(link(cid)["match_method"] == "not_needed", "the older backup[veeam] field saves it too")
    # an RMM column ignores the value
    rmm = php('foreach (Align\\Providers\\Providers::rmmConnectors() as $k => $c) if ($c->configured()) { echo $k; break; }').stdout.strip()
    ok(rmm, "an RMM is connected in the seed: " + rmm)
    before = q("select external_id, match_method from client_links where provider=%s and client_id=%s", rmm, cid)
    tech.post(B + "/mapping", data={"_csrf": csrf(tech, "/mapping"), f"link[{rmm}][{cid}]": NN})
    ok(q("select external_id, match_method from client_links where provider=%s and client_id=%s", rmm, cid) == before, "an RMM can't be set to doesn't need backups")

    # mapping JS keeps the option when the picker is filled
    js = open(ROOT + "/public/assets/app.js").read()
    ok("o.dataset.keep" in js, "the mapping picker keeps the Doesn't need backups option when it fills the list")

    out = php(f'(require "{MIG}")(); (require "{MIG}")(); echo "ok";')
    ok(out.stdout.strip() == "ok", "migration 085 runs twice " + out.stderr[-200:])
finally:
    if keep1:
        q("update client_links set external_id=%s, match_method=%s where client_id=1 and provider='veeam'", keep1["external_id"], keep1["match_method"])
    cleanup()
    sync_now()
done()

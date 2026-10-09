"""2.7.5 Backed-up machines matched to the client's servers when their names differ: a VM named for people ("PC-0022
(DC/File)", "PC-0022 - file server", "[Prod] PC-0022") still matches the server PC-0022, so it isn't listed under
Servers with no backup; one whose name has nothing in common is linked by hand (Backed up as…), kept by the backup
sort, shown as linked by hand and unlinked again; a loose name never moves a machine to another client; only the
client's own machines and devices; techs only; audited.
The test data's machines are changed in the database and put back afterwards."""
import re, html as H
from lib import *

C = 1


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def assign(): return php("Align\\Sync\\BackupSync::assign();")
def dev_of(uid): return q("select device_id from backup_workloads where uid=%s", uid)[0]["device_id"]


st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
tok = lambda s, p: csrf(s, p)

rows = q("select w.uid, w.name, w.hostname, w.device_id, d.display_name from backup_workloads w join devices d on d.id = w.device_id "
         "where w.client_id = %s and d.device_class = 'server' and w.kind = 'vm' order by w.uid limit 2", C)
ok(len(rows) == 2, f"client {C} has backed-up servers matched by name ({len(rows)})")
saved = [(r["uid"], r["name"], r["hostname"]) for r in rows]
import atexit


def restore():
    for uid, name, host in saved:
        q("update backup_workloads set name=%s, hostname=%s where uid=%s", name, host, uid)
    q("delete from backup_device_links")
    assign()


atexit.register(restore)
A, Bw = rows[0], rows[1]

# ---- names a person gave the VM
for name in [f"{A['display_name']} (DC/File)", f"{A['display_name']} - file server", f"[Prod] {A['display_name']}"]:
    q("update backup_workloads set name=%s, hostname=NULL, device_id=NULL where uid=%s", name, A["uid"])
    assign()
    ok(dev_of(A["uid"]) == A["device_id"], f"'{name}' matches the server {A['display_name']}")
t = text(st.get(B + f"/clients/{C}/backups").text)
sec = t.split("Servers with no backup")[1].split("Backup jobs")[0] if "Servers with no backup" in t else ""
ok(A["display_name"] not in sec, "...so it isn't listed as a server with no backup")

# ---- a loose form only picks a device within the client, never the client: a hosted machine without a client stays so
uh = q("select uid, name, hostname, client_id, client_how, device_id from backup_workloads where client_id is null and client_how is null limit 1")
if uh:
    q("update backup_workloads set name=%s, hostname=NULL where uid=%s", f"{A['display_name']} - copy for testing", uh[0]["uid"])
    assign()
    ok(q("select client_id from backup_workloads where uid=%s", uh[0]["uid"])[0]["client_id"] is None, "a hosted machine named '<server> - …' isn't given to that server's client")
    q("update backup_workloads set name=%s, hostname=%s where uid=%s", uh[0]["name"], uh[0]["hostname"], uh[0]["uid"])
    assign()

# ---- a name with nothing in common: linked by hand
q("update backup_workloads set name='Accounting server', hostname=NULL, device_id=NULL where uid=%s", Bw["uid"])
assign()
ok(dev_of(Bw["uid"]) is None, "a VM whose name has nothing in common isn't matched")
page = st.get(B + f"/clients/{C}/backups").text
ok("Servers with no backup" in page and "Backed up as" in page and "Accounting server" in page, "its server is listed with a Backed up as… choice offering the machine")
r = tech.post(B + f"/clients/{C}/backups/link", data={"_csrf": tok(tech, f"/clients/{C}/backups"), "device": Bw["device_id"], "workload": Bw["uid"]})
ok(dev_of(Bw["uid"]) == Bw["device_id"] and q("select 1 from backup_device_links where workload_uid=%s", Bw["uid"]) and q("select 1 from audit_log where action='backup.link_device'"),
   "a tech links it (audited): " + flash(r.text)[:100])
assign()
ok(dev_of(Bw["uid"]) == Bw["device_id"], "the backup sort keeps the link")
t = text(st.get(B + f"/clients/{C}/backups").text)
ok("linked by hand" in t and "unlink" in t, "the machine shows it was linked by hand")
r = tech.post(B + f"/clients/{C}/backups/link", data={"_csrf": tok(tech, f"/clients/{C}/backups"), "workload": Bw["uid"], "unlink": "1"})
assign()
ok(dev_of(Bw["uid"]) is None and not q("select 1 from backup_device_links"), "unlinked: back to not matched")

# ---- only the client's own, techs only
other = q("select id from devices where id not in (select d.id from devices d where d.id = %s) and id not in (select device_id from backup_workloads where device_id is not null) "
          "and client_id <> %s and client_id is not null limit 1", Bw["device_id"], C)
if other:
    r = tech.post(B + f"/clients/{C}/backups/link", data={"_csrf": tok(tech, f"/clients/{C}/backups"), "device": other[0]["id"], "workload": Bw["uid"]})
    ok(not q("select 1 from backup_device_links") and "Pick one of this client's devices" in flash(r.text), "another client's device is refused")
o = q("select uid from backup_workloads where client_id <> %s and client_id is not null limit 1", C)
if o:
    r = tech.post(B + f"/clients/{C}/backups/link", data={"_csrf": tok(tech, f"/clients/{C}/backups"), "device": Bw["device_id"], "workload": o[0]["uid"]})
    ok(not q("select 1 from backup_device_links") and "backed-up machines" in flash(r.text), "another client's machine is refused")
viewer.post(B + f"/clients/{C}/backups/link", data={"_csrf": tok(viewer, f"/clients/{C}"), "device": Bw["device_id"], "workload": Bw["uid"]})
ok(not q("select 1 from backup_device_links"), "viewers can't link")
ok("Backed up as" not in viewer.get(B + f"/clients/{C}/backups").text, "...or see the choice")
done()

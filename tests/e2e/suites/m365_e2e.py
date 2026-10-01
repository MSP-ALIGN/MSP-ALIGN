"""2.0.1: Microsoft 365 backups kept in two repositories (a legacy one and a current one) count as one object, and the
newest restore point wins; days with a backup count each day once, across repositories."""
from lib import *
import re, subprocess, html as H
from datetime import date, timedelta

C1 = "11111111-1111-1111-1111-111111111111"
def sync(): return subprocess.run(["php", ALIGN, "sync"], env=ENV, capture_output=True, text=True).stdout
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
today = php('echo date("Y-m-d");').stdout.strip()
d = lambda n: (date.fromisoformat(today) - timedelta(days=n)).isoformat()

q("delete from backup_exemptions where item_uid like %s", "o-1:%"); q("delete from backup_m365_days"); q("update backup_m365_objects set days_from=null, restore_days=null")
out = sync()
ok("in more than one repository" in out, "the sync says how many objects are in more than one repository: " + (re.findall(r"Microsoft 365:[^\n]*", out) or [""])[0])

# ---- the same mailbox in a legacy and a current repository: one object, the newest restore point counts
lee = q("select * from backup_m365_objects where uid='o-1:user-5'")
ok(len(lee) == 1 and lee[0]["repositories"] == 2 and lee[0]["restore_points"] == 3030, "Dr Lee: one object from two repositories, restore points added up")
ok(lee and str(lee[0]["last_point"]) > d(1), "Dr Lee: the newest restore point is the current repository's, not the legacy one from months ago: " + str(lee and lee[0]["last_point"]))
ok(lee and lee[0]["licensed"] == 1, "uses a license if either repository says so")
# rows with different ids but the same name, from a different repository: one object, keeping the smaller id
intra = q("select uid, repositories, last_point from backup_m365_objects where company_uid=%s and name='Intranet'", C1)
ok(len(intra) == 1 and intra[0]["uid"] == "o-1:site-1" and intra[0]["repositories"] == 2 and str(intra[0]["last_point"]) > d(1), "Intranet: the legacy copy with its own id is the same site: " + str(intra))
# two sites with the same name in the same repository stay two
docs = q("select uid from backup_m365_objects where company_uid=%s and name='Documents' order by uid", C1)
ok([r["uid"] for r in docs] == ["o-1:site-3", "o-1:site-4"], "two different sites called Documents in one repository stay two")
ok(len(q("select uid from backup_m365_objects where company_uid=%s and name='Team Site'", C1)) == 2, "two sites with one name and no repository details stay two")
doc = q("select repositories, last_point from backup_m365_objects where uid='o-1:grp-2'")[0]
ok(doc["repositories"] == 2 and str(doc["last_point"]) > d(1), "a group in two repositories: the newest restore point counts: " + str(doc))

ok(q("select count(*) n from backup_m365_objects where company_uid=%s and object_type='user'", C1)[0]["n"] == 12, "still 12 users")

# ---- days with a backup: counted from the first sync, a day counts once
lee = q("select days_from, restore_days from backup_m365_objects where uid='o-1:user-5'")[0]
ok(str(lee["days_from"]) in (today, d(1)) and lee["restore_days"] == 1, "counting starts with the newest backup's day (today, or yesterday just after midnight): " + str(lee))
ok(not q("select 1 from backup_m365_days where day < %s", d(1)), "the legacy repository's old restore point isn't counted (before counting started)")
sync()
ok(q("select restore_days from backup_m365_objects where uid='o-1:user-5'")[0]["restore_days"] == 1, "a second sync the same day: still one day")
q("update backup_m365_objects set days_from=%s where uid='o-1:user-5'", d(5))
q("insert into backup_m365_days (object_uid, day) values ('o-1:user-5', %s), ('o-1:user-5', %s), ('o-1:user-5', %s)", d(3), d(2), d(200))
sync()
ok(q("select restore_days from backup_m365_objects where uid='o-1:user-5'")[0]["restore_days"] == 3, "three days with a backup since counting started (one before it doesn't count)")
arch = q("select restore_days from backup_m365_objects where uid='o-1:site-2'")[0]
ok(arch["restore_days"] == 0, "a site whose newest restore point is from before counting started: 0 days")

# ---- the stored uid is kept: a "backup not required" mark on the legacy copy doesn't hide the current site
q("update backup_m365_objects set uid='o-1:site-1-legacy' where uid='o-1:site-1'")
q("update backup_m365_days set object_uid='o-1:site-1-legacy' where object_uid='o-1:site-1'")
sync()
ok([r["uid"] for r in q("select uid from backup_m365_objects where company_uid=%s and name='Intranet'", C1)] == ["o-1:site-1-legacy"], "an object combined from two ids keeps the uid Align already stores")
q("insert into backup_exemptions (client_id, kind, item_uid, item_name, reason, created_at) values (1, 'm365', 'o-1:site-1-legacy', 'Intranet', 'old copy', now())")
sync()
ok([r["uid"] for r in q("select uid from backup_m365_objects where company_uid=%s and name='Intranet'", C1)] == ["o-1:site-1"], "a uid with a 'not required' mark is passed over, so the mark can't hide the current site")
q("insert into backup_exemptions (client_id, kind, item_uid, item_name, reason, created_at) values (1, 'm365', 'o-1:site-1', 'Intranet', 'retired site', now())")
sync()
ok(q("select uid from backup_m365_objects where company_uid=%s and name='Intranet'", C1)[0]["uid"] in ("o-1:site-1", "o-1:site-1-legacy"), "both copies marked 'not required': the object keeps a marked uid and stays marked")
t = login("admin@example.com", "LongPassword123!").get(B + "/clients/1/backups").text
allx = t.split('id="m365-all"')[1].split("</details>")[0] if 'id="m365-all"' in t else ""
ok("Not required" in text(next((r for r in allx.split("<tr") if "Intranet" in r), "")), "it's listed as Not required")
q("delete from backup_exemptions where item_uid in ('o-1:site-1-legacy', 'o-1:site-1')")
sync()

# ---- the API lists each object with its days
k = php('$k=Align\\Api\\Keys::create("m365 e2e", Align\\Api\\Keys::allScopes(), null, null, 600, null, 1); echo $k[1];').stdout.strip()
import requests
api = requests.get(B + "/api/v1/clients/1/backups", headers={"Authorization": "Bearer " + k}).json().get("data", {})
objs = {o["uid"]: o for o in (api.get("microsoft_365") or {}).get("objects", [])}
lee = objs.get("o-1:user-5", {})
ok(lee.get("days_with_backup") == 3 and lee.get("repositories") == 2 and lee.get("health") == "ok" and lee.get("counting_since") == d(5), "the API lists Dr Lee with 3 days with a backup, 2 repositories: " + str(lee)[:200])

# ---- the Backups page
st = login("admin@example.com", "LongPassword123!")
t = st.get(B + "/clients/1/backups").text
ok(not errs(t), "page has no PHP errors: " + str(errs(t))[:200])
over = text(t.split("Without a recent backup")[1].split("All protected users")[0]) if "Without a recent backup" in t else ""
ok("Documents" in over and "Archive" in over and "Dr Lee" not in over and "Intranet" not in over, "overdue: the stale sites, not the ones a current repository backs up")
m365card = t.split("fa-microsoft")[1].split('id="m365-all"')[0] if "fa-microsoft" in t else ""
ok("Days with a backup" in m365card and "Restore points</th>" not in m365card, "the Microsoft 365 column is days with a backup")
allx = t.split('id="m365-all"')[1].split("</details>")[0] if 'id="m365-all"' in t else ""
lee_row = text(next((r for r in allx.split("<tr>") if "Dr Lee" in r), ""))
ok("Current" in lee_row and "2 repositories" in lee_row and re.search(r"\b3\b", lee_row), "all objects: Dr Lee is current, in 2 repositories, 3 days: " + lee_row[:160])
ok("3,030 restore points in all" in allx, "the day count explains itself (restore points in all)")
ok(re.search(r"(\d+)\s*<small[^>]*>\s*/\s*12 current", t) and re.search(r"(\d+)\s*<small[^>]*>\s*/\s*12 current", t).group(1) == "10", "users: 10 of 12 current (Dr Lee counts as current)")
ok("10/12 users current" in st.get(B + "/clients/1").text, "the client overview agrees")
done()

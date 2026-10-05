"""1.32.1 test server: staging mode (reads work, nothing is written or sent anywhere real) and the update channel."""
from lib import *
import json, subprocess, time, os, shutil
S_URL = "http://127.0.0.1:8082"
SCFG = WORK + "/staging.php"
TEST_BOX = "align-test@examplemsp.example"
base = open(CONFIG).read().rstrip().rstrip(";").rstrip()
assert base.endswith("]")
open(SCFG, "w").write(base[:-1] + f', "staging" => true, "staging_mail_to" => "{TEST_BOX}", "update_branch" => "develop"];\n')
SENV = {**ENV, "ALIGN_CONFIG": SCFG}
setting("mail_provider", "microsoft"); setting("mail_mode", "app")  # earlier suites may have switched to Google
def sphp(code, env=None): return subprocess.run(["php", "-r", f'require "{BOOTSTRAP}"; ' + code], env=env or SENV, capture_output=True, text=True)
srv = subprocess.Popen(["php", "-S", "127.0.0.1:8082", "-t", "public", "tests/dev-router.php"], cwd=ROOT, env=SENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
for _ in range(50):
    try:
        requests.get(S_URL + "/login", timeout=1); break
    except Exception:
        time.sleep(0.1)
try:
    # ---- every page says so; the normal server doesn't
    st = login("admin@example.com", "LongPassword123!", base=S_URL)
    t = st.get(S_URL + "/").text
    ok("Test server." in t and TEST_BOX in t and "is-staging" in t and "<title>[TEST]" in t and not errs(t), "banner, title and colour on the test server")
    ok("Test server." not in login("admin@example.com", "LongPassword123!").get(B + "/").text, "the normal server shows no banner")
    t = st.get(S_URL + "/clients/1/report/assets").text
    ok("TEST SERVER — not for clients" in t, "printed reports are marked")
    t = st.get(S_URL + "/settings/system").text
    ok("Test channel" in t and "develop" in t, "Updates page shows the test channel")

    # ---- 2.2.2 Ready to start on a test server: the whole flow works, with a pretend ticket and nothing sent to ITFlow
    scur = sphp('echo Align\\Roadmap\\Plan::quarters()[Align\\Roadmap\\Plan::currentIndex()]["start"];').stdout.strip()
    q("delete from roadmap_items where title = 'STG ready'")
    q("insert into roadmap_items (client_id, title, category, priority, status, target_quarter, cost) values (1, 'STG ready', 'security', 'high', 'approved', %s, 900)", scur)
    sid = q("select id from roadmap_items where title = 'STG ready'")[0]["id"]
    t = st.get(S_URL + "/todo").text
    ok(f'data-todo="project-{sid}"' in t, "To do lists the project on the test server too")
    fw = st.get(S_URL + f"/projects/{sid}/start").text
    ok("Create the ticket" in fw and f"TEST-{sid}" in fw and "nothing is sent to ITFlow" in fw, "the confirm window shows the ticket, and says it will be pretend")
    sent = requests.post(M + "/mock/tickets-created", json={}).json()["created"]
    r = st.post(S_URL + f"/projects/{sid}/start", data={"_csrf": re.search(r'name="_csrf" value="([^"]+)"', st.get(S_URL + "/todo").text).group(1), "back": "/todo"})
    row = q("select psa_ticket_id, ticket_at from roadmap_items where id = %s", sid)[0]
    ok(row["psa_ticket_id"] == f"TEST-{sid}" and row["ticket_at"] and len(requests.post(M + "/mock/tickets-created", json={}).json()["created"]) == len(sent),
       "Create the ticket saves TEST-%s and sends nothing to ITFlow" % sid)
    ok("pretend ticket" in r.text, "and says so")
    fr = st.get(S_URL + f"/projects/{sid}/form").text
    ok(f"Pretend ticket TEST-{sid}" in fr and "agent/ticket.php" not in fr, "the project window calls it a pretend ticket, with no link into ITFlow")
    pg = st.get(S_URL + "/projects?client=1&ticket=has&status=all").text
    ok("<th>Ticket</th>" in pg and "STG ready" in pg, "Projects keeps its Ticket column on the test server")
    dv = st.get(S_URL + "/clients/1/devices").text
    ok('id="mp-ticket"' in dv, "and Make projects still offers the ticket box")
    q("delete from roadmap_items where title = 'STG ready'")

    # ---- client portal and API are off
    for p in ["/portal/login", "/portal", "/portal/welcome/abc123"]:
        r = requests.get(S_URL + p)
        ok(r.status_code == 503 and "client portal is turned off" in r.text, f"{p}: portal off")
    r = requests.post(S_URL + "/portal/login", data={"email": "x@y.example", "password": "x"})
    ok(r.status_code == 503, "portal sign-in refused")
    php('$k=Align\\Api\\Keys::create("staging e2e", ["clients:read"], null, null, 600, null, 1); file_put_contents("/tmp/stg_key", $k[1]);')
    key = open("/tmp/stg_key").read().strip()
    r = requests.get(S_URL + "/api/v1/clients", headers={"Authorization": "Bearer " + key})
    ok(r.status_code == 503 and r.json()["error"]["code"] == "test_server", "API off: 503 test_server")
    ok(requests.get(B + "/api/v1/clients", headers={"Authorization": "Bearer " + key}).status_code == 200, "the same key works on the normal server")
    q("delete from api_keys where name='staging e2e'")

    # ---- the PSA: reads yes, writes no
    out = json.loads(sphp('$p = Align\\Providers\\Providers::psa(); try { $p->updateAsset(1, 1, ["asset_name" => "x"]); $w = "wrote"; } catch (RuntimeException $e) { $w = $e->getMessage(); }'
                          ' echo json_encode([get_class($p), count($p->clients()) > 0, Align\\Providers\\Providers::psaSupports("assets"), Align\\Providers\\Providers::psaSupports("assets.write"),'
                          ' Align\\Providers\\Providers::psaSupports("contacts.write"), Align\\Providers\\Providers::psaSupports("tickets.create"), Align\\Sync\\PsaAssetSync::twoWay(), $w]);').stdout)
    ok(out[0] == "Align\\Providers\\Psa\\StagingPsa" and out[1] and out[2], "PSA reads work on the test server")
    ok(out[3:7] == [False, False, False, False], "asset, contact and ticket writes and two-way sync are off: " + str(out[3:7]))
    ok("Test server: changes are not sent to ITFlow" in out[7], "a write that gets through anyway is refused: " + out[7])
    norm = json.loads(php('echo json_encode([Align\\Providers\\Providers::psaSupports("assets.write"), Align\\Sync\\PsaAssetSync::twoWay()]);').stdout)
    ok(norm == [True, True], "the normal server still writes")
    # a sync with a pending Align-side change sends nothing to ITFlow
    dev = q("select id, psa_asset_id from devices where source='psa' and psa_asset_id is not null and removed_at is null limit 1")[0]
    log = "/tmp/itflow-updates.log"
    before = open(log).read() if os.path.exists(log) else ""
    q("update psa_sync_state set pending=1 where device_id=%s", dev["id"])
    r = subprocess.run(["php", ALIGN, "sync", "--quiet"], env=SENV, capture_output=True, text=True)
    after = open(log).read() if os.path.exists(log) else ""
    run = json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
    ok(r.returncode == 0 and after == before, "a test-server sync writes nothing to ITFlow")
    ok(any("assets read" in str(v) for v in run.values()) and "ITFlow clients" in run, "it still reads everything: " + ", ".join(run)[:120])
    q("update psa_sync_state set pending=0 where device_id=%s", dev["id"])

    # ---- email: only to the test mailbox, marked, saying who it was for
    requests.get(M + "/mock/graph-reset")
    sphp('Align\\Mail\\Mailer::queue("test", [["address" => "client@clientco.example", "name" => "A Client"]], "Quarterly review", "<p>Hello</p>", ["cc" => [["address" => "boss@clientco.example"]], "immediate" => true]);')
    m = graph()["mail"][-1]["message"]
    to = [r["emailAddress"]["address"] for r in m["toRecipients"]]
    ok(to == [TEST_BOX] and not m.get("ccRecipients") and m["subject"] == "[TEST] Quarterly review", "email goes only to the test mailbox, marked [TEST]: " + str(to))
    ok("would have gone to A Client &lt;client@clientco.example&gt;" in m["body"]["content"] and "boss@clientco.example" in m["body"]["content"], "and says who it was meant for")
    n = len(graph()["mail"])
    nobox = WORK + "/staging-nobox.php"
    open(nobox, "w").write(open(SCFG).read().replace(f'"staging_mail_to" => "{TEST_BOX}"', '"staging_mail_to" => ""'))
    sphp('Align\\Mail\\Mailer::queue("test", [["address" => "client@clientco.example"]], "Not sent", "<p>x</p>", ["immediate" => true]);', {**ENV, "ALIGN_CONFIG": nobox})
    r = q("select status, last_error from mail_queue where subject='Not sent' order by id desc limit 1")[0]
    ok(len(graph()["mail"]) == n and r["status"] == "cancelled" and "no test mailbox" in (r["last_error"] or ""), "without a test mailbox nothing is sent, and the outbox says so")
    q("delete from mail_queue where subject in ('Quarterly review','Not sent')")

    # ---- meeting invitations: test events only; real events from the copied data are never touched
    info = 'array("uid" => "stg-1", "subject" => "QBR", "html" => "<p>Agenda</p>", "text" => "", "start" => "2026-12-01 10:00:00", "end" => "2026-12-01 11:00:00", "location" => null, "online" => true)'
    real = json.loads(php(f'echo json_encode(Align\\Mail\\Mail::client()->calendarCreate(null, {info}, [["address" => "client@clientco.example", "name" => "A Client"]]));').stdout)
    out = json.loads(sphp(f'$c = Align\\Mail\\Mail::client(); $a = $c->calendarCreate("owner@examplemsp.example", {info}, [["address" => "client@clientco.example", "name" => "A Client"]]);'
                          f' $u = $c->calendarUpdate($a["mailbox"], $a["id"], {info}, [["address" => "client@clientco.example", "name" => "A Client"]]);'
                          f' $r = $c->calendarUpdate({json.dumps(real["mailbox"])}, {json.dumps(real["id"])}, {info}, [["address" => "client@clientco.example", "name" => "A Client"]]);'
                          f' $c->calendarCancel({json.dumps(real["mailbox"])}, {json.dumps(real["id"])}, "x"); echo json_encode([$a, $u, $r]);').stdout)
    ev = graph()["events"]
    a, u, rr = out
    mine = ev[a["id"].split(":", 1)[1]]
    ok(a["id"].startswith("staging:") and u["id"] == a["id"], "test events are marked as the test server's own")
    ok([x["emailAddress"]["address"] for x in mine["attendees"]] == [TEST_BOX] and mine["subject"].startswith("[TEST]"), "invitations go only to the test mailbox")
    ok("owner@examplemsp.example" not in json.dumps(mine.get("organizerMailbox", "")), "never in a staff member's own calendar")
    ok(ev[real["id"]]["status"] == "created" and rr["id"].startswith("staging:") and rr["id"] != a["id"], "a real event is neither changed nor cancelled; an update makes a test event instead")
    ok(mine.get("transactionId", "").startswith("staging-") and mine.get("transactionId") != "stg-1", "test events get their own id, so the calendar can't match them to the real event")
    ok(m.get("replyTo") and m["replyTo"][0]["emailAddress"]["address"] == TEST_BOX, "replies go to the test mailbox, not production's reply-to")
    r = sphp('try { Align\\Mail\\Mail::client()->call("POST", "/me/sendMail", []); echo "passed"; } catch (RuntimeException $e) { echo $e->getMessage(); }')
    ok("isn't allowed" in r.stdout, "anything but the guarded methods is refused: " + r.stdout[:60])
    # Google "Disconnect" on a test server must not revoke production's grant
    g0 = requests.get(M + "/mock/graph").json().get("google", {}).get("revoked", 0)
    sphp('Align\\Settings::setSecret("g_refresh_token", "prod-refresh-token"); Align\\Mail\\Google::disconnect();')
    ok(requests.get(M + "/mock/graph").json().get("google", {}).get("revoked", 0) == g0 and not q("select 1 from settings where name='g_refresh_token' and value<>''"), "Google disconnect clears it here but doesn't revoke production's access")
    t = requests.get(S_URL + "/login").text
    ok("Test server: a copy of" in t, "the sign-in page says it's the test server")

    # ---- the update channel comes from config.php
    T = SYS
    subprocess.run(f"git -C {T}/remote.git branch -f develop main", shell=True)
    shutil.rmtree(T + "/stg-app", ignore_errors=True)
    subprocess.run(["git", "clone", "-q", T + "/remote.git", T + "/stg-app"])
    aenv = {**SENV, "ALIGN_APP_DIR": T + "/stg-app", "ALIGN_DATA_DIR": T + "/data", "ALIGN_AGENT_DIR": T + "/stg-agent", "ALIGN_RUN_DIR": T + "/run",
            "ALIGN_RECIPIENT": T + "/recipient.txt", "ALIGN_RUNAS": "root", "ALIGN_SYSTEMCTL": "none", "ALIGN_LEGACY_BACKUPS": T + "/legacy", "ALIGN_RELEASE_SIGNERS": "none", "ALIGN_AGENT_TEST": "1"}
    os.makedirs(T + "/stg-agent", exist_ok=True)
    r = subprocess.run(["php", ROOT + "/scripts/agent.php", "check"], env=aenv, capture_output=True, text=True)
    u = json.load(open(T + "/stg-agent/update.json"))
    ok(r.returncode == 0 and u["branch"] == "develop" and u["error"] is None, "agent checks the branch set in config.php: " + str(u.get("branch")))
    r = subprocess.run(["php", ROOT + "/scripts/agent.php", "check"], env={**aenv, "ALIGN_CONFIG": CONFIG, "ALIGN_AGENT_DIR": T + "/stg-agent"}, capture_output=True, text=True)
    ok(json.load(open(T + "/stg-agent/update.json"))["branch"] == "main", "without update_branch: main")
    # a branch with an older version is never offered (its code could meet newer tables)
    subprocess.run(f"cd {T}/work && git checkout -q -B older && echo 0.1.0 > VERSION && git -c user.name=t -c user.email=t@t commit -qam 'older' && git push -q -f origin HEAD:older && git checkout -q main", shell=True)
    oldcfg = WORK + "/staging-older.php"
    open(oldcfg, "w").write(open(SCFG).read().replace('"update_branch" => "develop"', '"update_branch" => "older"'))
    subprocess.run(["php", ROOT + "/scripts/agent.php", "check"], env={**aenv, "ALIGN_CONFIG": oldcfg}, capture_output=True, text=True)
    u = json.load(open(T + "/stg-agent/update.json"))
    ok(u["branch"] == "older" and not u["available"] and "older than this server" in (u["error"] or ""), "an older version on the chosen branch isn't offered: " + str(u.get("error"))[:90])
    shutil.rmtree(T + "/stg-app", ignore_errors=True); shutil.rmtree(T + "/stg-agent", ignore_errors=True)
finally:
    srv.terminate()
print("FAILURES:", len(fails)); [print(" -", f) for f in fails]
